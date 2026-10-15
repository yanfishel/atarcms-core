<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;

/**
 * All SQL on media and entry_media. MediaService is the only writer of media rows; EntryMedia (through EntryService)
 * the only writer of entry_media rows.
 */
final class MediaRepository
{
    public const string COLUMNS = 'id, path, original_name, mime, size, width, height, alt, uploaded_by, folder_id, created_at, updated_at';

    public const int PER_PAGE = 48;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $row column => value; "alt" is an array locale => text
     */
    public function insert(array $row): int
    {
        return $this->db->insert('media', self::encode($row));
    }

    /**
     * @param bool $lock take a row lock (SELECT … FOR UPDATE); only inside a transaction
     */
    public function find(int $id, bool $lock = false): ?Media
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM media WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Media> id => media, ordered by id; unknown ids are omitted
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $media = [];
        foreach ($this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE id IN (' . self::placeholders($ids) . ') ORDER BY id',
            $ids,
        ) as $row) {
            $item = self::hydrate($row);
            $media[$item->id] = $item;
        }

        return $media;
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, int> path => id of the paths that exist, ordered by path
     */
    public function idsByPaths(array $paths): array
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return [];
        }
        $ids = [];
        foreach ($this->db->select(
            'SELECT id, path FROM media WHERE path IN (' . self::placeholders($paths) . ') ORDER BY path',
            $paths,
        ) as $row) {
            $ids[Row::string($row, 'path')] = Row::int($row, 'id');
        }

        return $ids;
    }

    /**
     * One page of files; the search matches the original name or any alt text value (not the JSON keys).
     */
    public function page(MediaQuery $query): MediaPage
    {
        $where = ['1 = 1'];
        $bindings = [];
        if ($query->folderIds !== null) {
            $scope = [];
            if ($query->root) {
                $scope[] = 'folder_id IS NULL';
            }
            if ($query->folderIds !== []) {
                $scope[] = 'folder_id IN (' . self::placeholders($query->folderIds) . ')';
                array_push($bindings, ...$query->folderIds);
            }
            $where[] = $scope === [] ? '0 = 1' : '(' . implode(' OR ', $scope) . ')';
        }
        if ($query->type === MediaQuery::TYPE_IMAGE) {
            $where[] = "mime LIKE 'image/%'";
        } elseif ($query->type === MediaQuery::TYPE_VIDEO) {
            $where[] = "mime LIKE 'video/%'";
        } elseif ($query->type === MediaQuery::TYPE_DOCUMENT) {
            $where[] = "mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%'";
        }
        if ($query->search !== '') {
            $like = '%' . addcslashes($query->search, '%_\\') . '%';
            // JSON_EXTRACT(alt, '$.*') is a JSON array of the values only, so locale codes never match; JSON
            // strings are utf8mb4_bin, hence the explicit collation for a case-insensitive comparison.
            $where[] = "(original_name LIKE ? OR JSON_EXTRACT(alt, '$.*') COLLATE utf8mb4_unicode_ci LIKE ?)";
            array_push($bindings, $like, $like);
        }
        if ($query->unused) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM entry_media em WHERE em.media_id = media.id)';
            if ($query->alsoUsed !== []) {
                $where[] = 'id NOT IN (' . self::placeholders($query->alsoUsed) . ')';
                array_push($bindings, ...$query->alsoUsed);
            }
        }
        $sql = ' FROM media WHERE ' . implode(' AND ', $where);
        $order = match ($query->sort) {
            'oldest' => 'id ASC',
            'name' => 'original_name ASC, id ASC',
            'size' => 'size DESC, id DESC',
            default => 'id DESC',
        };
        $total = Row::int(['count' => $this->db->scalar('SELECT COUNT(*)' . $sql, $bindings)], 'count');
        $page = max(1, $query->page);
        $items = array_map(
            self::hydrate(...),
            $this->db->select(
                'SELECT ' . self::COLUMNS . $sql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
                [...$bindings, $query->perPage, ($page - 1) * $query->perPage],
            ),
        );

        return new MediaPage($items, $page, $query->perPage, $total);
    }

    /**
     * Moves files to a folder (null: outside any folder); the caller validates the folder.
     *
     * @param list<int> $ids
     */
    public function moveTo(array $ids, ?int $folderId): void
    {
        if ($ids !== []) {
            $this->db->execute('UPDATE media SET folder_id = ? WHERE id IN (' . self::placeholders($ids) . ')', [$folderId, ...$ids]);
        }
    }

    /**
     * @param array<string, mixed> $row column => value; "alt" is an array locale => text
     */
    public function update(int $id, array $row): void
    {
        $row = self::encode($row);
        $sets = implode(', ', array_map(
            static fn(string $column): string => Connection::quoteIdentifier($column) . ' = ?',
            array_keys($row),
        ));
        $this->db->execute('UPDATE media SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
    }

    /**
     * @return int the number of deleted rows
     */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM media WHERE id = ?', [$id]);
    }

    /**
     * Deletes every media row (bin/atar demo:seed, after the entries that reference them); the files are
     * MediaStorage::purge()'s.
     *
     * @return int the number of deleted rows
     */
    /**
     * How many files the library holds (bin/atar demo:seed tells before deleting them).
     */
    public function countAll(): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM media')], 'count');
    }

    /**
     * The library's file count and the bytes its originals take (the dashboard's tile).
     *
     * @return array{count: int, bytes: int}
     */
    public function totals(): array
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM media') ?? [];

        return ['count' => Row::int($row, 'files'), 'bytes' => Row::int($row, 'bytes')];
    }

    public function deleteAll(): int
    {
        return $this->db->execute('DELETE FROM media');
    }

    /**
     * Replaces the media references of an entry (its media fields and rich-text links); call inside the entry's
     * transaction. A reference to a missing medium fails with the foreign key error 1452.
     *
     * @param list<array{field: string, mediaId: int}> $references
     */
    public function replaceEntryReferences(int $entryId, array $references): void
    {
        $this->db->execute('DELETE FROM entry_media WHERE entry_id = ?', [$entryId]);
        $seen = [];
        foreach ($references as $reference) {
            $key = $reference['field'] . '#' . $reference['mediaId'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->db->insert('entry_media', ['entry_id' => $entryId, 'field' => $reference['field'], 'media_id' => $reference['mediaId']]);
        }
    }

    /**
     * Deletes the entry_media rows of every entry whose collection is not a key of $keep and of every field not listed
     * for its collection (media:references:prune).
     *
     * @param array<string, list<string>> $keep configured collection => its fields that can reference media
     *
     * @return int the number of rows deleted
     */
    public function pruneReferences(array $keep): int
    {
        $kept = [];
        $bindings = [];
        foreach ($keep as $collection => $fields) {
            if ($fields !== []) {
                $kept[] = '(e.collection = ? AND em.field IN (' . self::placeholders($fields) . '))';
                array_push($bindings, $collection, ...$fields);
            }
        }

        return $this->db->execute(
            'DELETE em FROM entry_media em JOIN entries e ON e.id = em.entry_id' . ($kept === [] ? '' : ' WHERE NOT (' . implode(' OR ', $kept) . ')'),
            $bindings,
        );
    }

    /**
     * @return list<array{entryId: int, field: string}> the entries using $mediaId: fields and rich-text links
     *                                                 (entry_media)
     */
    public function usages(int $mediaId): array
    {
        $rows = $this->db->select('SELECT entry_id, field FROM entry_media WHERE media_id = ? ORDER BY entry_id, field', [$mediaId]);

        return array_map(static fn(array $row): array => ['entryId' => Row::int($row, 'entry_id'), 'field' => Row::string($row, 'field')], $rows);
    }

    public function usageCount(int $mediaId): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entry_media WHERE media_id = ?', [$mediaId])], 'count');
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int> id => the number of entry references (fields, rich-text links), for every given id
     */
    public function usageCounts(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $counts = array_fill_keys($ids, 0);
        if ($ids === []) {
            return $counts;
        }
        foreach ($this->db->select(
            'SELECT media_id, COUNT(*) AS uses FROM entry_media WHERE media_id IN (' . self::placeholders($ids) . ') GROUP BY media_id',
            $ids,
        ) as $row) {
            $counts[Row::int($row, 'media_id')] = Row::int($row, 'uses');
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Media
    {
        $alt = [];
        foreach (Row::json($row, 'alt') as $locale => $text) {
            if (is_string($locale) && is_string($text)) {
                $alt[$locale] = $text;
            }
        }

        return new Media(
            id: Row::int($row, 'id'),
            path: Row::string($row, 'path'),
            originalName: Row::string($row, 'original_name'),
            mime: Row::string($row, 'mime'),
            size: Row::int($row, 'size'),
            width: Row::nullableInt($row, 'width'),
            height: Row::nullableInt($row, 'height'),
            alt: $alt,
            uploadedBy: Row::nullableInt($row, 'uploaded_by'),
            createdAt: Row::dateTime($row, 'created_at'),
            updatedAt: Row::dateTime($row, 'updated_at'),
            folderId: Row::nullableInt($row, 'folder_id'),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function encode(array $row): array
    {
        if (array_key_exists('alt', $row)) {
            $row['alt'] = json_encode(
                (object) (is_array($row['alt']) ? $row['alt'] : []),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $row;
    }

    /**
     * @param list<int|string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
