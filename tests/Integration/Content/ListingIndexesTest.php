<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\IndexedColumns;
use AtarCms\Tests\Support\Cast;

/**
 * Spec §12: list queries rely on (collection, locale, status, published_at) and on the indexes of ->indexed()
 * fields. A regression test, so a migration cannot silently drop them.
 */
final class ListingIndexesTest extends ContentTestCase
{
    public function testEntriesHaveTheListingAndTreeIndexes(): void
    {
        $indexes = $this->indexes();

        self::assertSame(['collection', 'locale', 'status', 'published_at'], $indexes['entries_listing_index'] ?? null);
        self::assertSame(['parent_id', 'position'], $indexes['entries_parent_index'] ?? null);
        self::assertSame(['translation_group', 'locale'], $indexes['entries_group_locale_unique'] ?? null);
    }

    public function testIndexedFieldsGetAnIndexAfterSync(): void
    {
        foreach ((new IndexedColumns($this->db, $this->collections))->statements() as $sql) {
            $this->db->execute($sql);
        }

        $indexes = $this->indexes();

        foreach (['rating', 'event_date', 'kind'] as $field) {
            self::assertSame(['collection', 'locale', 'f_' . $field], $indexes['entries_f_' . $field . '_index'] ?? null, $field);
        }
    }

    /**
     * @return array<string, list<string>> index name => columns in order
     */
    private function indexes(string $table = 'entries'): array
    {
        $indexes = [];
        foreach ($this->db->select('SHOW INDEX FROM ' . $this->db->quoteIdentifier($table)) as $row) {
            $indexes[Cast::string($row['Key_name'])][Cast::int($row['Seq_in_index']) - 1] = Cast::string($row['Column_name']);
        }
        foreach ($indexes as $name => $columns) {
            ksort($columns);
            $indexes[$name] = array_values($columns);
        }

        return $indexes;
    }
}
