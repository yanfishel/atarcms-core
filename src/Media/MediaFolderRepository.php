<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;

/**
 * All SQL on media_folders. MediaFolderService is the only writer.
 */
final class MediaFolderRepository
{
    private const string COLUMNS = 'id, parent_id, name, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<MediaFolder> every folder, by name (the column's collation)
     */
    public function all(): array
    {
        return array_map(self::hydrate(...), $this->db->select('SELECT ' . self::COLUMNS . ' FROM media_folders ORDER BY name, id'));
    }

    /**
     * @param bool $lock take a row lock (SELECT … FOR UPDATE); only inside a transaction
     */
    public function find(int $id, bool $lock = false): ?MediaFolder
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM media_folders WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Whether a sibling of $parentId other than $exceptId has the name $name (compared like the unique index:
     * ignoring case and accents).
     */
    public function nameTaken(?int $parentId, string $name, ?int $exceptId = null): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM media_folders WHERE parent_key = ? AND name = ? AND id <> ? LIMIT 1',
            [$parentId ?? 0, $name, $exceptId ?? 0],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $row column => value
     */
    public function insert(array $row): int
    {
        return $this->db->insert('media_folders', $row);
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
        $this->db->execute('UPDATE media_folders SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM media_folders WHERE id = ?', [$id]);
    }

    /**
     * Deletes every folder (bin/atar demo:seed, after the media), the folders without subfolders first, level by level
     * (moving them all to the top would clash on equal names).
     *
     * @return int the number of deleted folders
     */
    public function deleteAll(): int
    {
        $total = 0;
        do {
            $deleted = $this->db->execute(
                'DELETE FROM media_folders WHERE id NOT IN'
                . ' (SELECT parent_id FROM (SELECT parent_id FROM media_folders WHERE parent_id IS NOT NULL) AS parents)',
            );
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    public function childCount(int $id): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM media_folders WHERE parent_id = ?', [$id])], 'count');
    }

    public function fileCount(int $id): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM media WHERE folder_id = ?', [$id])], 'count');
    }


    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): MediaFolder
    {
        return new MediaFolder(
            id: Row::int($row, 'id'),
            parentId: Row::nullableInt($row, 'parent_id'),
            name: Row::string($row, 'name'),
            createdAt: Row::dateTime($row, 'created_at'),
            updatedAt: Row::dateTime($row, 'updated_at'),
        );
    }
}
