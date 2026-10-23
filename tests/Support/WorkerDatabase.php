<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Database\Connection;
use AtarCms\Tests\Integration\DatabaseTestCase;

/**
 * Parallel runs (ParaTest) give every worker process its own database: worker 3 of "atar_test" uses "atar_3_test",
 * created on first use with the character set and collation of the configured one. DB_DATABASE is switched in the
 * process environment, so the tests and the applications they boot both use it.
 */
final class WorkerDatabase
{
    /**
     * Unchanged when $database is the worker's already: a test in a separate process inherits the switched environment.
     */
    public static function name(string $database, int $worker): string
    {
        if (str_ends_with($database, '_' . $worker . '_test')) {
            return $database;
        }

        return preg_replace('/_test$/D', '', $database) . '_' . $worker . '_test';
    }

    public static function use(int $worker): void
    {
        $config = DatabaseTestCase::config();
        $name = self::name($config['database'], $worker);
        $db = Connection::fromConfig($config);
        $defaults = $db->selectOne(
            'SELECT default_character_set_name AS charset, default_collation_name AS collation FROM information_schema.schemata WHERE schema_name = DATABASE()',
        ) ?? [];
        $db->execute(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            Connection::quoteIdentifier($name),
            Cast::string($defaults['charset'] ?? 'utf8mb4'),
            Cast::string($defaults['collation'] ?? 'utf8mb4_unicode_ci'),
        ));
        putenv('DB_DATABASE=' . $name);
    }
}
