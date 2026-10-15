<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;

/**
 * All SQL on the entries table. Validation and publishing rules live in EntryService and PublicContent.
 */
final class EntryRepository
{
    public const string COLUMNS = 'id, collection, locale, translation_group, slug, title, status, published_at, parent_id, '
        . 'position, author_id, created_at, updated_at, meta_title, meta_description, share_image, preset, data';

    /**
     * The order of the entries carrying one term (its id is the one binding): their place among its entries
     * (entry_terms.entry_position, 0 until a section's articles are ordered, so new and moved-in entries come first),
     * then newest first. A correlated subquery: entries has its own position and id columns.
     */
    public const string TERM_ORDER = '(SELECT et.entry_position FROM entry_terms et WHERE et.entry_id = entries.id AND et.term_id = ?), '
        . 'published_at DESC, id DESC';

    public function __construct(
        private readonly Connection $db,
        private readonly Collections $collections,
    ) {}

    public static function newTranslationGroup(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function find(int $id): ?Entry
    {
        return $this->one('id = ?', [$id]);
    }

    public function findBySlug(string $collection, string $locale, string $slug): ?Entry
    {
        return $this->one('collection = ? AND locale = ? AND slug = ?', [$collection, $locale, $slug]);
    }

    /**
     * The oldest entry of a collection in a locale, whatever its status (the entry of a single() collection).
     */
    public function first(string $collection, string $locale): ?Entry
    {
        return $this->one('collection = ? AND locale = ? ORDER BY id', [$collection, $locale]);
    }

    /**
     * @return list<Entry> every locale of a translation group, ordered by locale
     */
    public function translations(string $group): array
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM entries WHERE translation_group = ? ORDER BY locale', [$group]);
    }

    /**
     * @return array<string, Entry> locale => the entry that is the preset page $preset of $collection (PresetPages)
     */
    public function presetPages(string $collection, string $preset): array
    {
        $pages = [];
        foreach ($this->many('SELECT ' . self::COLUMNS . ' FROM entries WHERE collection = ? AND preset = ? ORDER BY locale', [$collection, $preset]) as $entry) {
            $pages[$entry->locale] = $entry;
        }

        return $pages;
    }

    public function slugExists(string $collection, string $locale, string $slug, ?int $exceptId = null): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM entries WHERE collection = ? AND locale = ? AND slug = ? AND id <> ? LIMIT 1',
            [$collection, $locale, $slug, $exceptId ?? 0],
        ) !== null;
    }

    /**
     * Whether a top-level entry (no parent) of $collection in $locale has $slug (RootSlugs).
     */
    public function topLevelSlugExists(string $collection, string $locale, string $slug): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM entries WHERE collection = ? AND locale = ? AND slug = ? AND parent_id IS NULL LIMIT 1',
            [$collection, $locale, $slug],
        ) !== null;
    }

    public function hasChildren(int $id): bool
    {
        return $this->db->scalar('SELECT 1 FROM entries WHERE parent_id = ? LIMIT 1', [$id]) !== null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids that belong to entries of $collection (any locale and status)
     */
    public function existingIds(string $collection, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT id FROM entries WHERE collection = ? AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            [$collection, ...$ids],
        );

        return array_map(static fn(array $row): int => Row::int($row, 'id'), $rows);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Entry> id => entry of any collection, locale and status, ordered by id; unknown ids and
     *                           entries of unconfigured collections are omitted
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $entries = [];
        foreach ($this->many(
            'SELECT ' . self::COLUMNS . ' FROM entries WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            $ids,
        ) as $entry) {
            $entries[$entry->id] = $entry;
        }

        return $entries;
    }

    /**
     * Every ancestor of the given entries (parent, grandparent, …) in one recursive query; the entries themselves
     * are included only when one is an ancestor of another. The walk stops after TreeOrder::MAX_DEPTH levels, so a
     * corrupt cycle cannot loop.
     *
     * @param list<int> $ids
     *
     * @return array<int, Entry> id => entry, ordered by id; entries of unconfigured collections are omitted
     */
    public function ancestors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $sql = 'WITH RECURSIVE chain (id, parent_id, depth) AS ('
            . ' SELECT id, parent_id, 0 FROM entries WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' UNION ALL'
            . ' SELECT e.id, e.parent_id, c.depth + 1 FROM entries e JOIN chain c ON e.id = c.parent_id WHERE c.depth < ?'
            . ')'
            . ' SELECT ' . self::COLUMNS . ' FROM entries WHERE id IN (SELECT parent_id FROM chain WHERE parent_id IS NOT NULL) ORDER BY id';
        $entries = [];
        foreach ($this->many($sql, [...$ids, TreeOrder::MAX_DEPTH]) as $entry) {
            $entries[$entry->id] = $entry;
        }

        return $entries;
    }

    /**
     * @param bool $lock take row locks (SELECT … FOR UPDATE); only inside a transaction
     *
     * @return list<Entry> every entry of a collection in a locale (any status), in sibling order (position, then id)
     */
    public function forTree(string $collection, string $locale, bool $lock = false): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM entries WHERE collection = ? AND locale = ? ORDER BY position, id' . ($lock ? ' FOR UPDATE' : ''),
            [$collection, $locale],
        );
    }

    /**
     * The entries of $collections in $locale that carry the term $termId, in any status, in the term's order
     * (TERM_ORDER, like its public page).
     *
     * @param list<string> $collections
     * @param bool $lock take row locks (SELECT … FOR UPDATE); only inside a transaction
     *
     * @return list<Entry>
     */
    public function forTerm(array $collections, int $termId, string $locale, bool $lock = false): array
    {
        if ($collections === []) {
            return [];
        }

        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM entries WHERE collection IN (' . implode(', ', array_fill(0, count($collections), '?')) . ')'
                . ' AND locale = ? AND id IN (SELECT entry_id FROM entry_terms WHERE term_id = ?) ORDER BY ' . self::TERM_ORDER . ($lock ? ' FOR UPDATE' : ''),
            [...$collections, $locale, $termId, $termId],
        );
    }

    /**
     * Walks every entry of $collections (any locale and status) in id order, $limit at a time: pass the last id of
     * a batch to get the next one (search:reindex).
     *
     * @param non-empty-list<string> $collections
     *
     * @return list<Entry> at most $limit entries with an id above $afterId, by id
     */
    public function batchAfter(array $collections, int $afterId, int $limit): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM entries WHERE collection IN (' . implode(', ', array_fill(0, count($collections), '?')) . ')'
            . ' AND id > ? ORDER BY id LIMIT ?',
            [...$collections, $afterId, $limit],
        );
    }

    /**
     * @param array<string, mixed> $row column => value; "data" is an array of field values
     */
    public function insert(array $row): int
    {
        return $this->db->insert('entries', self::encode($row));
    }

    /**
     * @param array<string, mixed> $row column => value; "data" is an array of field values
     */
    public function update(int $id, array $row): void
    {
        $row = self::encode($row);
        $sets = implode(', ', array_map(
            static fn(string $column): string => Connection::quoteIdentifier($column) . ' = ?',
            array_keys($row),
        ));
        $this->db->execute('UPDATE entries SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM entries WHERE id = ?', [$id]);
    }

    /**
     * Deletes every entry with its revisions, terms, media references and search rows (cascades): bin/atar demo:seed.
     * The parent links go first (a parent's row cannot be deleted before its children's).
     *
     * @return int the number of deleted entries
     */
    /**
     * How many entries there are (bin/atar demo:seed tells before deleting them).
     */
    public function countAll(): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entries')], 'count');
    }

    public function deleteAll(): int
    {
        $this->db->execute('UPDATE entries SET parent_id = NULL WHERE parent_id IS NOT NULL');

        return $this->db->execute('DELETE FROM entries');
    }

    /**
     * Sets the entry's generated share image (a file name of ShareImages, or null) when the stored one is still
     * $expected: a compare-and-set, so a concurrent save that already changed it wins. updated_at (the entry form's
     * stale-version token) is not touched: the image is derived from the saved entry.
     *
     * @return bool whether the row was changed
     */
    public function setShareImage(int $id, ?string $name, ?string $expected): bool
    {
        return $this->db->execute('UPDATE entries SET share_image = ? WHERE id = ? AND share_image <=> ?', [$name, $id, $expected]) > 0;
    }

    /**
     * Every recorded share image name, of entries of any collection (configured or not): what bin/atar media:cleanup
     * must keep in storage/uploads/share/.
     *
     * @return list<string>
     */
    public function shareImageNames(): array
    {
        return array_map(
            static fn(array $row): string => Row::string($row, 'share_image'),
            $this->db->select('SELECT share_image FROM entries WHERE share_image IS NOT NULL'),
        );
    }

    /**
     * Runs a SELECT of self::COLUMNS and hydrates the rows, skipping rows of unconfigured collections.
     *
     * @param list<mixed> $bindings
     *
     * @return list<Entry>
     */
    public function many(string $sql, array $bindings = []): array
    {
        $entries = [];
        foreach ($this->db->select($sql, $bindings) as $row) {
            $entry = $this->hydrate($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): ?Entry
    {
        $collection = $this->collections->find(Row::string($row, 'collection'));
        if ($collection === null) {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = Row::json($row, 'data');

        return new Entry(
            id: Row::int($row, 'id'),
            collection: $collection,
            locale: Row::string($row, 'locale'),
            translationGroup: Row::string($row, 'translation_group'),
            slug: Row::string($row, 'slug'),
            title: Row::string($row, 'title'),
            status: EntryStatus::from(Row::string($row, 'status')),
            publishedAt: Row::nullableDateTime($row, 'published_at'),
            parentId: Row::nullableInt($row, 'parent_id'),
            position: Row::int($row, 'position'),
            authorId: Row::nullableInt($row, 'author_id'),
            createdAt: Row::dateTime($row, 'created_at'),
            updatedAt: Row::dateTime($row, 'updated_at'),
            seo: new Seo(
                metaTitle: Row::nullableString($row, 'meta_title'),
                metaDescription: Row::nullableString($row, 'meta_description'),
            ),
            data: $data,
            shareImage: Row::nullableString($row, 'share_image'),
            preset: Row::nullableString($row, 'preset'),
        );
    }

    /**
     * @param list<mixed> $bindings
     */
    private function one(string $where, array $bindings): ?Entry
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM entries WHERE ' . $where . ' LIMIT 1', $bindings)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function encode(array $row): array
    {
        if (array_key_exists('data', $row)) {
            $row['data'] = json_encode(
                is_array($row['data']) ? (object) $row['data'] : $row['data'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        }

        return $row;
    }
}
