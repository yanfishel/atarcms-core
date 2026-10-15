<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * A read-only query over the public entries of one collection in one locale; the theme API behind
 * $this->entries('news'). Every method returns a new query. Filters and sorts are whitelisted: core columns
 * (Collection::SORTABLE), ->indexed() fields and the collection's taxonomies (withTerm()).
 */
final class ContentQuery
{
    /** @var list<array{0: string, 1: string|int|float}> */
    private array $conditions = [];

    private ?string $orderColumn = null;

    private string $orderDirection = 'asc';

    private bool $filterParent = false;

    private ?int $parentId = null;

    /** @var list<array{0: string, 1: list<int|string>}> SQL conditions with their bindings */
    private array $termFilters = [];

    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
        public readonly Collection $collection,
        public readonly string $locale,
        private readonly DateTimeImmutable $now,
    ) {}

    public function where(string $field, string|int|float $value): self
    {
        if ($this->collection->field($field)?->indexed !== true) {
            throw new InvalidArgumentException(sprintf(
                'Cannot filter collection "%s" by "%s"; only ->indexed() fields can be filtered',
                $this->collection->name,
                $field,
            ));
        }
        $query = clone $this;
        $query->conditions[] = [IndexedColumns::column($field), $value];

        return $query;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The sort direction must be "asc" or "desc"');
        }
        $query = clone $this;
        $query->orderColumn = self::sqlColumn($this->collection, $column);
        $query->orderDirection = $direction;

        return $query;
    }

    /**
     * Only the direct children of $parent; null selects the top-level entries.
     */
    public function childrenOf(?Entry $parent): self
    {
        if (!$this->collection->hierarchical) {
            throw new LogicException(sprintf('Collection "%s" is not hierarchical', $this->collection->name));
        }
        $query = clone $this;
        $query->filterParent = true;
        $query->parentId = $parent?->id;

        return $query;
    }

    /**
     * Only entries that carry $term: a Term, or the slug of a term of $taxonomy in this query's locale.
     */
    public function withTerm(string $taxonomy, Term|string $term): self
    {
        if (!in_array($taxonomy, $this->collection->termTaxonomies(), true)) {
            throw new InvalidArgumentException(sprintf('Collection "%s" does not use the taxonomy "%s"', $this->collection->name, $taxonomy));
        }
        $query = clone $this;
        if ($term instanceof Term) {
            if ($term->taxonomy !== $taxonomy) {
                throw new InvalidArgumentException(sprintf('Term #%d belongs to the taxonomy "%s", not "%s"', $term->id, $term->taxonomy, $taxonomy));
            }
            if ($term->locale !== $this->locale) {
                throw new InvalidArgumentException(sprintf('Term #%d belongs to the locale "%s", not "%s"', $term->id, $term->locale, $this->locale));
            }
            $query->termFilters[] = ['id IN (SELECT entry_id FROM entry_terms WHERE term_id = ?)', [$term->id]];
        } else {
            $query->termFilters[] = [
                'id IN (SELECT et.entry_id FROM entry_terms et JOIN terms t ON t.id = et.term_id WHERE t.taxonomy = ? AND t.locale = ? AND t.slug = ?)',
                [$taxonomy, $this->locale, $term],
            ];
        }

        return $query;
    }

    /**
     * Only entries that carry $term or one of its descendants: a top section's latest articles when they live in its
     * subsections. The descendants are read first (one recursive query).
     */
    public function withinTerm(string $taxonomy, Term $term): self
    {
        $query = $this->withTerm($taxonomy, $term);
        $ids = [];
        foreach ($this->db->select(
            'WITH RECURSIVE tree (id, depth) AS ('
                . ' SELECT id, 0 FROM terms WHERE id = ?'
                . ' UNION ALL'
                . ' SELECT t.id, tree.depth + 1 FROM terms t JOIN tree ON t.parent_id = tree.id WHERE tree.depth < ?'
                . ') SELECT id FROM tree',
            [$term->id, TreeOrder::MAX_DEPTH],
        ) as $row) {
            $ids[] = Row::int($row, 'id');
        }
        array_pop($query->termFilters);
        $query->termFilters[] = ['id IN (SELECT entry_id FROM entry_terms WHERE term_id IN (' . self::placeholders($ids) . '))', $ids];

        return $query;
    }

    /**
     * @return list<Entry>
     */
    public function get(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 1000 || $offset < 0) {
            throw new InvalidArgumentException('The limit must be between 1 and 1000 and the offset at least 0');
        }
        [$where, $bindings] = $this->conditionsSql();

        return $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $where . ' ORDER BY ' . $this->orderSql() . ' LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );
    }

    /**
     * @return list<Entry> the most recently published entries
     */
    public function latest(int $limit): array
    {
        return $this->orderBy('published_at', 'desc')->get($limit);
    }

    public function first(): ?Entry
    {
        return $this->get(1)[0] ?? null;
    }

    public function count(): int
    {
        [$where, $bindings] = $this->conditionsSql();

        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entries WHERE ' . $where, $bindings)], 'count');
    }

    public function page(int $number, ?int $perPage = null): EntryPage
    {
        $perPage ??= $this->collection->perPage;
        if ($number < 1 || $perPage < 1 || $perPage > 1000) {
            throw new InvalidArgumentException('The page number must be at least 1 and entries per page between 1 and 1000');
        }

        return new EntryPage($this->get($perPage, ($number - 1) * $perPage), $number, $perPage, $this->count());
    }

    /**
     * Relation ids point to one locale of an entry (a translation copies them unchanged), so each id stands
     * for its translation group and resolves to that group's public entry in this query's locale.
     *
     * @param list<int> $ids
     *
     * @return list<Entry> in the order of $ids, without duplicates; groups with no public entry here are dropped
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $groups = [];
        foreach ($this->db->select(
            'SELECT id, translation_group FROM entries WHERE id IN (' . self::placeholders($ids) . ')',
            $ids,
        ) as $row) {
            $groups[Row::int($row, 'id')] = Row::string($row, 'translation_group');
        }
        $groupList = array_values(array_unique($groups));
        if ($groupList === []) {
            return [];
        }

        [$where, $bindings] = $this->conditionsSql();
        $found = [];
        foreach ($this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $where
                . ' AND translation_group IN (' . self::placeholders($groupList) . ')',
            [...$bindings, ...$groupList],
        ) as $entry) {
            $found[$entry->translationGroup] = $entry;
        }

        $ordered = [];
        foreach ($ids as $id) {
            $entry = isset($groups[$id]) ? ($found[$groups[$id]] ?? null) : null;
            if ($entry !== null && !isset($ordered[$entry->id])) {
                $ordered[$entry->id] = $entry;
            }
        }

        return array_values($ordered);
    }

    /**
     * @param list<mixed> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * The SQL column for a sortable name: a core column or the generated column of an indexed field.
     */
    public static function sqlColumn(Collection $collection, string $column): string
    {
        if (in_array($column, Collection::SORTABLE, true)) {
            return $column;
        }
        if ($collection->field($column)?->indexed === true) {
            return IndexedColumns::column($column);
        }

        throw new InvalidArgumentException(sprintf('Cannot sort collection "%s" by "%s"', $collection->name, $column));
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditionsSql(): array
    {
        [$public, $publicBindings] = Entry::publicSql($this->now);
        $sql = 'collection = ? AND locale = ? AND ' . $public;
        $bindings = [$this->collection->name, $this->locale, ...$publicBindings];
        foreach ($this->conditions as [$column, $value]) {
            $sql .= ' AND ' . Connection::quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }
        if ($this->filterParent) {
            if ($this->parentId === null) {
                $sql .= ' AND parent_id IS NULL';
            } else {
                $sql .= ' AND parent_id = ?';
                $bindings[] = $this->parentId;
            }
        }
        foreach ($this->termFilters as [$condition, $values]) {
            $sql .= ' AND ' . $condition;
            array_push($bindings, ...$values);
        }

        return [$sql, $bindings];
    }

    private function orderSql(): string
    {
        $column = $this->orderColumn ?? self::sqlColumn($this->collection, $this->collection->sortColumn);
        $direction = strtoupper($this->orderColumn === null ? $this->collection->sortDirection : $this->orderDirection);

        return sprintf('%s %s, id %s', Connection::quoteIdentifier($column), $direction, $direction);
    }
}
