<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Database;

use AtarCms\Database\Connection;
use AtarCms\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class ConnectionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute(
            'CREATE TABLE items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(20) NOT NULL,
                qty INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function testInsertAndSelectRoundTripUnicode(): void
    {
        $id = $this->db->insert('items', ['name' => 'Привет 👋', 'qty' => 3, 'active' => true]);

        $row = $this->db->selectOne('SELECT * FROM items WHERE id = ?', [$id]);

        self::assertNotNull($row);
        self::assertSame('Привет 👋', $row['name']);
        self::assertSame(3, $row['qty']);
        self::assertSame(1, $row['active']);
    }

    public function testNamedBindingsAndNull(): void
    {
        $this->db->insert('items', ['name' => 'a', 'qty' => null]);
        $this->db->insert('items', ['name' => 'b', 'qty' => 1]);

        $rows = $this->db->select('SELECT name FROM items WHERE qty IS NULL AND name = :name', ['name' => 'a']);

        self::assertSame([['name' => 'a']], $rows);
    }

    public function testLimitAcceptsIntegerBinding(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->db->insert('items', ['name' => $name]);
        }

        self::assertCount(2, $this->db->select('SELECT name FROM items ORDER BY id LIMIT ?', [2]));
    }

    public function testDateTimesAreStoredInUtc(): void
    {
        $this->db->insert('items', [
            'name' => 'dated',
            'created_at' => new DateTimeImmutable('2026-09-28 12:00:00', new DateTimeZone('Europe/Moscow')),
        ]);

        self::assertSame('2026-09-28 09:00:00', $this->db->scalar('SELECT created_at FROM items'));
    }

    public function testExecuteReturnsAffectedRowsAndScalarReturnsNullForNoRows(): void
    {
        $this->db->insert('items', ['name' => 'a']);
        $this->db->insert('items', ['name' => 'b']);

        self::assertSame(2, $this->db->execute('UPDATE items SET qty = ?', [5]));
        self::assertNull($this->db->scalar('SELECT name FROM items WHERE id = ?', [999]));
        self::assertNull($this->db->selectOne('SELECT name FROM items WHERE id = ?', [999]));
    }

    public function testTransactionCommitsAndRollsBack(): void
    {
        $result = $this->db->transaction(static fn(Connection $db): int => $db->insert('items', ['name' => 'kept']));
        self::assertGreaterThan(0, $result, 'the callback\'s value: the new row\'s id');

        try {
            $this->db->transaction(static function (Connection $db): void {
                $db->insert('items', ['name' => 'dropped']);

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([['name' => 'kept']], $this->db->select('SELECT name FROM items'));
    }

    public function testSessionUsesUtcAndStrictMode(): void
    {
        self::assertSame('+00:00', $this->db->scalar('SELECT @@session.time_zone'));

        $this->expectException(PDOException::class);

        $this->db->insert('items', ['name' => str_repeat('x', 21)]);
    }

    public function testQuoteIdentifier(): void
    {
        self::assertSame('`items`', Connection::quoteIdentifier('items'));

        $this->expectException(InvalidArgumentException::class);

        Connection::quoteIdentifier('items`; DROP TABLE items; --');
    }

    public function testRejectsUnsupportedBindingTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->db->select('SELECT ?', [[1]]);
    }
}
