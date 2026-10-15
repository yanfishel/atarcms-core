<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use InvalidArgumentException;

/**
 * All SQL on the terms and entry_terms tables. Validation lives in TermService and EntryTerms.
 */
final class TermRepository
{
    public const string COLUMNS = 'id, taxonomy, locale, translation_group, slug, name, parent_id, position, meta_title, meta_description, '
        . 'created_at, updated_at';

    private const string JOINED_COLUMNS = 't.id, t.taxonomy, t.locale, t.translation_group, t.slug, t.name, t.parent_id, '
        . 't.position, t.meta_title, t.meta_description, t.created_at, t.updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?Term
    {
        return $this->one('id = ?', [$id]);
    }

    public function findBySlug(string $taxonomy, string $locale, string $slug): ?Term
    {
        return $this->one('taxonomy = ? AND locale = ? AND slug = ?', [$taxonomy, $locale, $slug]);
    }

    /**
     * The oldest term with this name; the comparison follows the column collation (case-insensitive).
     */
    public function findByName(string $taxonomy, string $locale, string $name): ?Term
    {
        return $this->one('taxonomy = ? AND locale = ? AND name = ? ORDER BY id', [$taxonomy, $locale, $name]);
    }

    /**
     * @param bool $lock take row locks (SELECT … FOR UPDATE); only inside a transaction
     *
     * @return list<Term> ordered by position, name and id (TermTree turns them into a tree)
     */
    public function forTaxonomy(string $taxonomy, string $locale, bool $lock = false): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM terms WHERE taxonomy = ? AND locale = ? ORDER BY position, name, id' . ($lock ? ' FOR UPDATE' : ''),
            [$taxonomy, $locale],
        );
    }

    /**
     * The terms of $taxonomy and $locale whose name contains $query, as the column collation compares (case- and
     * accent-insensitive); "%", "_" and "\" match themselves.
     *
     * @return list<Term> ordered by position, name and id
     */
    public function search(string $taxonomy, string $locale, string $query): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM terms WHERE taxonomy = ? AND locale = ? AND name LIKE ? ORDER BY position, name, id',
            [$taxonomy, $locale, '%' . addcslashes($query, '%_\\') . '%'],
        );
    }

    /**
     * @return list<Term> every term of $taxonomy in every locale, by translation group, then locale
     */
    public function allOf(string $taxonomy): array
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM terms WHERE taxonomy = ? ORDER BY translation_group, locale', [$taxonomy]);
    }

    /**
     * The number of terms of $taxonomy in every locale (sitemap URLs).
     */
    public function count(string $taxonomy): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM terms WHERE taxonomy = ?', [$taxonomy])], 'count');
    }

    /**
     * The number of translation groups of $taxonomy.
     */
    public function groupCount(string $taxonomy): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(DISTINCT translation_group) FROM terms WHERE taxonomy = ?', [$taxonomy])], 'count');
    }

    /**
     * One sitemap page of $taxonomy: the terms of $groups whole translation groups after the first $offset, by
     * translation group, then locale.
     *
     * @return list<Term>
     */
    public function groupsChunk(string $taxonomy, int $offset, int $groups): array
    {
        if ($offset < 0 || $groups < 1) {
            throw new InvalidArgumentException('The sitemap chunk needs a non-negative offset and at least one group');
        }

        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM terms WHERE taxonomy = ? AND translation_group IN (SELECT translation_group FROM'
            . ' (SELECT DISTINCT translation_group FROM terms WHERE taxonomy = ? ORDER BY translation_group LIMIT ? OFFSET ?) g)'
            . ' ORDER BY translation_group, locale',
            [$taxonomy, $taxonomy, $groups, $offset],
        );
    }

    /**
     * @return list<Term> every locale of a translation group, ordered by locale
     */
    public function translations(string $group): array
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM terms WHERE translation_group = ? ORDER BY locale', [$group]);
    }

    /**
     * @param list<string> $groups
     *
     * @return array<string, list<string>> translation group => locales
     */
    public function localesByGroup(array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $locales = [];
        foreach ($this->db->select(
            'SELECT translation_group, locale FROM terms WHERE translation_group IN (' . self::placeholders($groups) . ') ORDER BY translation_group, locale',
            $groups,
        ) as $row) {
            $locales[Row::string($row, 'translation_group')][] = Row::string($row, 'locale');
        }

        return $locales;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Term> id => term, ordered by id; unknown ids are omitted
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $terms = [];
        foreach ($this->many('SELECT ' . self::COLUMNS . ' FROM terms WHERE id IN (' . self::placeholders($ids) . ') ORDER BY id', $ids) as $term) {
            $terms[$term->id] = $term;
        }

        return $terms;
    }

    /**
     * Every ancestor of the terms $ids (parents, their parents…) in one query, whatever the depth; the terms themselves
     * are included only when one is an ancestor of another. The walk stops after TreeOrder::MAX_DEPTH levels, so a
     * corrupt cycle cannot loop.
     *
     * @param list<int> $ids
     *
     * @return array<int, Term> id => term, ordered by id
     */
    public function ancestors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sql = 'WITH RECURSIVE chain (id, parent_id, depth) AS ('
            . ' SELECT id, parent_id, 0 FROM terms WHERE id IN (' . self::placeholders($ids) . ')'
            . ' UNION ALL'
            . ' SELECT t.id, t.parent_id, c.depth + 1 FROM terms t JOIN chain c ON t.id = c.parent_id WHERE c.depth < ?'
            . ')'
            . ' SELECT ' . self::COLUMNS . ' FROM terms WHERE id IN (SELECT parent_id FROM chain WHERE parent_id IS NOT NULL) ORDER BY id';
        $terms = [];
        foreach ($this->many($sql, [...$ids, TreeOrder::MAX_DEPTH]) as $term) {
            $terms[$term->id] = $term;
        }

        return $terms;
    }

    public function slugExists(string $taxonomy, string $locale, string $slug, ?int $exceptId = null): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM terms WHERE taxonomy = ? AND locale = ? AND slug = ? AND id <> ? LIMIT 1',
            [$taxonomy, $locale, $slug, $exceptId ?? 0],
        ) !== null;
    }

    public function topLevelSlugExists(string $taxonomy, string $locale, string $slug): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM terms WHERE taxonomy = ? AND locale = ? AND slug = ? AND parent_id IS NULL LIMIT 1',
            [$taxonomy, $locale, $slug],
        ) !== null;
    }

    public function hasChildren(int $id): bool
    {
        return $this->db->scalar('SELECT 1 FROM terms WHERE parent_id = ? LIMIT 1', [$id]) !== null;
    }

    /**
     * @return list<int> the ids of the terms of $taxonomy (any locale) that have children
     */
    public function parentIds(string $taxonomy): array
    {
        return array_map(
            static fn(array $row): int => Row::int($row, 'parent_id'),
            $this->db->select('SELECT DISTINCT parent_id FROM terms WHERE taxonomy = ? AND parent_id IS NOT NULL ORDER BY parent_id', [$taxonomy]),
        );
    }

    /**
     * @return int the position after the last term of $parentId (null: the top level) in one taxonomy and locale
     */
    public function nextPosition(string $taxonomy, string $locale, ?int $parentId): int
    {
        $last = $this->db->scalar('SELECT MAX(position) FROM terms WHERE taxonomy = ? AND locale = ? AND parent_id <=> ?', [$taxonomy, $locale, $parentId]);

        return $last === null ? 0 : Row::int(['last' => $last], 'last') + 1;
    }

    /**
     * @param array<string, mixed> $row column => value
     */
    public function insert(array $row): int
    {
        return $this->db->insert('terms', $row);
    }

    /**
     * @param array<string, mixed> $row column => value
     */
    public function update(int $id, array $row): void
    {
        $sets = implode(', ', array_map(
            static fn(string $column): string => Connection::quoteIdentifier($column) . ' = ?',
            array_keys($row),
        ));
        $this->db->execute('UPDATE terms SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
    }

    /**
     * Deletes a term; its entry_terms rows go with it (ON DELETE CASCADE). Fails while it has children.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM terms WHERE id = ?', [$id]);
    }

    /**
     * Deletes every term of every taxonomy (bin/atar demo:seed), parent links first.
     *
     * @return int the number of deleted terms
     */
    /**
     * How many terms of every taxonomy there are (bin/atar demo:seed tells before deleting them).
     */
    public function countAll(): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM terms')], 'count');
    }

    public function deleteAll(): int
    {
        $this->db->execute('UPDATE terms SET parent_id = NULL WHERE parent_id IS NOT NULL');

        return $this->db->execute('DELETE FROM terms');
    }

    /**
     * @return list<int> the entry's term ids, by taxonomy, then in assignment order
     */
    public function idsForEntry(int $entryId): array
    {
        return array_map(
            static fn(array $row): int => Row::int($row, 'term_id'),
            $this->db->select(
                'SELECT et.term_id FROM entry_terms et JOIN terms t ON t.id = et.term_id
                 WHERE et.entry_id = ? ORDER BY t.taxonomy, et.position, et.term_id',
                [$entryId],
            ),
        );
    }

    /**
     * The terms of many entries in one query (a page of a list), optionally of one taxonomy.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, list<Term>> entry id => its terms, by taxonomy, then in assignment order; entries without terms are omitted
     */
    public function forEntries(array $entryIds, ?string $taxonomy = null): array
    {
        if ($entryIds === []) {
            return [];
        }
        $sql = 'SELECT et.entry_id, ' . self::JOINED_COLUMNS . ' FROM entry_terms et JOIN terms t ON t.id = et.term_id
                WHERE et.entry_id IN (' . self::placeholders($entryIds) . ')';
        $bindings = $entryIds;
        if ($taxonomy !== null) {
            $sql .= ' AND t.taxonomy = ?';
            $bindings[] = $taxonomy;
        }
        $terms = [];
        foreach ($this->db->select($sql . ' ORDER BY et.entry_id, t.taxonomy, et.position, t.id', $bindings) as $row) {
            $terms[Row::int($row, 'entry_id')][] = self::hydrate($row);
        }

        return $terms;
    }

    /**
     * Replaces the entry's terms of $taxonomy with $termIds, in this order (duplicates are ignored). A term the entry
     * keeps keeps the entry's place among its entries (entry_position, setEntryPositions()); a new one starts at 0.
     * The rows are read locked, so a reorder committing meanwhile is not undone by stale positions.
     *
     * @param list<int> $termIds
     */
    public function assign(int $entryId, string $taxonomy, array $termIds): void
    {
        $kept = [];
        foreach ($this->db->select(
            'SELECT et.term_id, et.entry_position FROM entry_terms et JOIN terms t ON t.id = et.term_id WHERE et.entry_id = ? AND t.taxonomy = ? FOR UPDATE',
            [$entryId, $taxonomy],
        ) as $row) {
            $kept[Row::int($row, 'term_id')] = Row::int($row, 'entry_position');
        }
        $this->db->execute(
            'DELETE et FROM entry_terms et JOIN terms t ON t.id = et.term_id WHERE et.entry_id = ? AND t.taxonomy = ?',
            [$entryId, $taxonomy],
        );
        foreach (array_values(array_unique($termIds)) as $position => $termId) {
            $this->db->insert('entry_terms', [
                'entry_id' => $entryId,
                'term_id' => $termId,
                'position' => $position,
                'entry_position' => $kept[$termId] ?? 0,
            ]);
        }
    }

    /**
     * Sets the places of entries among the entries carrying $termId (the manual order of a section's articles).
     *
     * @param array<int, int> $positions entry id => its entry_position
     */
    public function setEntryPositions(int $termId, array $positions): void
    {
        foreach ($positions as $entryId => $position) {
            $this->db->execute('UPDATE entry_terms SET entry_position = ? WHERE term_id = ? AND entry_id = ?', [$position, $termId, $entryId]);
        }
    }

    /**
     * @param list<int> $termIds
     *
     * @return array<int, int> term id => number of entries carrying it, ordered by term id; unused terms are omitted
     */
    public function entryCounts(array $termIds): array
    {
        if ($termIds === []) {
            return [];
        }
        $counts = [];
        foreach ($this->db->select(
            'SELECT term_id, COUNT(*) AS entries FROM entry_terms WHERE term_id IN (' . self::placeholders($termIds) . ') GROUP BY term_id ORDER BY term_id',
            $termIds,
        ) as $row) {
            $counts[Row::int($row, 'term_id')] = Row::int($row, 'entries');
        }

        return $counts;
    }

    /**
     * The most used terms of $taxonomies in $locale, counted over the entries of $collections: by count, then by name.
     *
     * @param list<string> $taxonomies
     * @param list<string> $collections
     *
     * @return list<array{name: string, count: int}>
     */
    public function popular(array $taxonomies, string $locale, array $collections, int $limit): array
    {
        if ($taxonomies === [] || $collections === []) {
            return [];
        }
        $popular = [];
        foreach ($this->db->select(
            'SELECT t.name, COUNT(*) AS entries FROM terms t JOIN entry_terms et ON et.term_id = t.id JOIN entries e ON e.id = et.entry_id'
                . ' WHERE t.taxonomy IN (' . self::placeholders($taxonomies) . ') AND t.locale = ? AND e.collection IN (' . self::placeholders($collections) . ')'
                . ' GROUP BY t.id, t.name ORDER BY entries DESC, t.name, t.id LIMIT ?',
            [...$taxonomies, $locale, ...$collections, $limit],
        ) as $row) {
            $popular[] = ['name' => Row::string($row, 'name'), 'count' => Row::int($row, 'entries')];
        }

        return $popular;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int> source term id => id of its translation in $locale; terms without one are omitted
     */
    public function translationIds(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->select(
            'SELECT s.id AS source_id, t.id AS target_id FROM terms s
             JOIN terms t ON t.translation_group = s.translation_group AND t.locale = ?
             WHERE s.id IN (' . self::placeholders($ids) . ')',
            [$locale, ...$ids],
        ) as $row) {
            $map[Row::int($row, 'source_id')] = Row::int($row, 'target_id');
        }

        return $map;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function one(string $where, array $bindings): ?Term
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM terms WHERE ' . $where . ' LIMIT 1', $bindings)[0] ?? null;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<Term>
     */
    private function many(string $sql, array $bindings): array
    {
        return array_map(self::hydrate(...), $this->db->select($sql, $bindings));
    }

    /**
     * @param list<mixed> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Term
    {
        return new Term(
            id: Row::int($row, 'id'),
            taxonomy: Row::string($row, 'taxonomy'),
            locale: Row::string($row, 'locale'),
            translationGroup: Row::string($row, 'translation_group'),
            slug: Row::string($row, 'slug'),
            name: Row::string($row, 'name'),
            parentId: Row::nullableInt($row, 'parent_id'),
            position: Row::int($row, 'position'),
            createdAt: Row::dateTime($row, 'created_at'),
            updatedAt: Row::dateTime($row, 'updated_at'),
            seo: new Seo(Row::nullableString($row, 'meta_title'), Row::nullableString($row, 'meta_description')),
        );
    }
}
