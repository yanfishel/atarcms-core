<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;

/**
 * All SQL on the menu_items table. Validation lives in MenuService.
 */
final class MenuRepository
{
    public const string COLUMNS = 'id, location, locale, parent_id, position, label, target_type, entry_id, term_id, url, new_tab, created_at, updated_at';

    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?MenuItem
    {
        return $this->many('SELECT ' . self::COLUMNS . ' FROM menu_items WHERE id = ?', [$id])[0] ?? null;
    }

    /**
     * @param bool $lock take row locks (SELECT … FOR UPDATE); only inside a transaction
     *
     * @return list<MenuItem> the items of one location and locale in sibling order (position, then id)
     */
    public function forLocation(string $location, string $locale, bool $lock = false): array
    {
        return $this->many(
            'SELECT ' . self::COLUMNS . ' FROM menu_items WHERE location = ? AND locale = ? ORDER BY position, id' . ($lock ? ' FOR UPDATE' : ''),
            [$location, $locale],
        );
    }

    /**
     * The position after the last child of $parentId, or after the last top-level item of a location and locale
     * (0 when there is none).
     */
    public function nextPosition(string $location, string $locale, ?int $parentId = null): int
    {
        return Row::int(['next' => $this->db->scalar(
            'SELECT COALESCE(MAX(position) + 1, 0) FROM menu_items WHERE location = ? AND locale = ? AND '
                . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'),
            $parentId === null ? [$location, $locale] : [$location, $locale, $parentId],
        )], 'next');
    }

    /**
     * The number of items below $item: its children, their children, and so on.
     */
    public function descendantCount(MenuItem $item): int
    {
        $children = [];
        foreach ($this->forLocation($item->location, $item->locale) as $other) {
            if ($other->parentId !== null) {
                $children[$other->parentId][] = $other->id;
            }
        }
        $seen = [$item->id => true];
        $queue = $children[$item->id] ?? [];
        $count = 0;
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $count++;
            $queue = [...$queue, ...($children[$id] ?? [])];
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row column => value
     */
    public function insert(array $row): int
    {
        return $this->db->insert('menu_items', $row);
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
        $this->db->execute('UPDATE menu_items SET ' . $sets . ' WHERE id = ?', [...array_values($row), $id]);
    }

    /**
     * Deletes an item; its subitems go with it (ON DELETE CASCADE).
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM menu_items WHERE id = ?', [$id]);
    }

    /**
     * Deletes every item of every menu (bin/atar demo:seed), parent links first.
     *
     * @return int the number of deleted items
     */
    /**
     * How many menu items there are (bin/atar demo:seed tells before deleting them).
     */
    public function countAll(): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM menu_items')], 'count');
    }

    public function deleteAll(): int
    {
        $this->db->execute('UPDATE menu_items SET parent_id = NULL WHERE parent_id IS NOT NULL');

        return $this->db->execute('DELETE FROM menu_items');
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<MenuItem>
     */
    private function many(string $sql, array $bindings): array
    {
        return array_map(self::hydrate(...), $this->db->select($sql, $bindings));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): MenuItem
    {
        return new MenuItem(
            id: Row::int($row, 'id'),
            location: Row::string($row, 'location'),
            locale: Row::string($row, 'locale'),
            parentId: Row::nullableInt($row, 'parent_id'),
            position: Row::int($row, 'position'),
            label: Row::string($row, 'label'),
            target: MenuTarget::from(Row::string($row, 'target_type')),
            entryId: Row::nullableInt($row, 'entry_id'),
            termId: Row::nullableInt($row, 'term_id'),
            url: Row::nullableString($row, 'url'),
            newTab: Row::int($row, 'new_tab') === 1,
            createdAt: Row::dateTime($row, 'created_at'),
            updatedAt: Row::dateTime($row, 'updated_at'),
        );
    }
}
