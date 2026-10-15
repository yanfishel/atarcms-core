<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration;

use AtarCms\Database\Connection;
use AtarCms\Database\Migrator;
use AtarCms\Tests\Support\Cast;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        $this->db = Connection::fromConfig(self::config());

        $database = Cast::string($this->db->scalar('SELECT DATABASE()'));
        if (!str_ends_with($database, '_test')) {
            self::fail(sprintf(
                'Refusing to reset database "%s": expected its name to end with "_test" to avoid dropping tables in a non-test database.',
                $database,
            ));
        }

        $this->dropAllTables();
    }

    protected function dropAllTables(): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
            $this->db->execute('DROP TABLE ' . Connection::quoteIdentifier(Cast::string($row['name'])));
        }
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function migrateCore(): void
    {
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/resources/migrations']))->migrate();
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function config(): array
    {
        return [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'atar_test',
            'username' => getenv('DB_USERNAME') ?: 'atar',
            'password' => getenv('DB_PASSWORD') ?: 'atar',
        ];
    }
}
