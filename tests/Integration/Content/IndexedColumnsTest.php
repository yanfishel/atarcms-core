<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Field\Field;
use AtarCms\Content\IndexedColumns;
use AtarCms\Kernel\ConfigException;

final class IndexedColumnsTest extends ContentTestCase
{
    public function testWantedColumnsComeFromIndexedFields(): void
    {
        self::assertSame(
            ['f_event_date' => 'DATE', 'f_kind' => 'VARCHAR(191)', 'f_rating' => 'DOUBLE'],
            $this->columns()->wanted(),
        );
    }

    public function testStatementsAddColumnsThatReadFromData(): void
    {
        $columns = $this->columns();
        $statements = $columns->statements();

        self::assertCount(3, $statements);
        self::assertSame(
            "ALTER TABLE entries ADD COLUMN `f_event_date` DATE AS (JSON_VALUE(data, '$.event_date')) VIRTUAL, "
            . 'ADD INDEX `entries_f_event_date_index` (collection, locale, `f_event_date`)',
            $statements[0],
        );
        self::assertSame(
            "ALTER TABLE entries ADD COLUMN `f_kind` VARCHAR(191) AS (LEFT(JSON_VALUE(data, '$.kind'), 191)) VIRTUAL, "
            . 'ADD INDEX `entries_f_kind_index` (collection, locale, `f_kind`)',
            $statements[1],
        );
        foreach ($statements as $sql) {
            $this->db->execute($sql);
        }

        self::assertSame(['f_event_date' => 'date', 'f_kind' => 'varchar', 'f_rating' => 'double'], $columns->existing());
        self::assertSame([], $columns->statements());

        $full = $this->insertEntry(['data' => ['rating' => 7.5, 'event_date' => '2026-10-01', 'kind' => 'report']]);
        $empty = $this->insertEntry(['slug' => 'empty']);
        $row = $this->db->selectOne('SELECT f_rating, f_event_date, f_kind FROM entries WHERE id = ?', [$full]);
        self::assertEquals(7.5, $row['f_rating'] ?? null);
        self::assertSame('2026-10-01', $row['f_event_date'] ?? null);
        self::assertSame('report', $row['f_kind'] ?? null);
        self::assertSame(
            ['f_rating' => null, 'f_event_date' => null, 'f_kind' => null],
            $this->db->selectOne('SELECT f_rating, f_event_date, f_kind FROM entries WHERE id = ?', [$empty]),
        );
    }

    public function testLongTextIsCutToTheColumnLength(): void
    {
        $columns = new IndexedColumns($this->db, new Collections([
            Collection::make('articles')->fields([Field::text('headline')->max(1000)->indexed()]),
        ]));
        foreach ($columns->statements() as $sql) {
            $this->db->execute($sql);
        }

        $id = $this->insertEntry(['data' => ['headline' => str_repeat('я', 300)]]);

        self::assertSame(str_repeat('я', 191), $this->db->scalar('SELECT f_headline FROM entries WHERE id = ?', [$id]));
    }

    public function testRemovedAndRetypedFieldsAreDroppedAndAddedAgain(): void
    {
        foreach ($this->columns()->statements() as $sql) {
            $this->db->execute($sql);
        }
        $changed = new IndexedColumns($this->db, new Collections([
            Collection::make('articles')->fields([Field::text('rating')->indexed(), Field::date('event_date')->indexed()]),
        ]));

        self::assertSame([
            'ALTER TABLE entries DROP INDEX IF EXISTS `entries_f_kind_index`, DROP COLUMN `f_kind`',
            'ALTER TABLE entries DROP INDEX IF EXISTS `entries_f_rating_index`, DROP COLUMN `f_rating`',
            "ALTER TABLE entries ADD COLUMN `f_rating` VARCHAR(191) AS (LEFT(JSON_VALUE(data, '$.rating'), 191)) VIRTUAL, "
            . 'ADD INDEX `entries_f_rating_index` (collection, locale, `f_rating`)',
        ], $changed->statements());
        foreach ($changed->statements() as $sql) {
            $this->db->execute($sql);
        }

        self::assertSame(['f_event_date' => 'date', 'f_rating' => 'varchar'], $changed->existing());
    }

    public function testAFieldNameHasOneTypeAcrossCollections(): void
    {
        // Collections already rejects this at config time; IndexedColumns keeps its own check as a safety net.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('must have the same type');
        new IndexedColumns($this->db, new Collections([
            Collection::make('a')->fields([Field::date('happens')->indexed()]),
            Collection::make('b')->fields([Field::datetime('happens')->indexed()]),
        ]))->wanted();
    }

    private function columns(): IndexedColumns
    {
        return new IndexedColumns($this->db, $this->collections);
    }
}
