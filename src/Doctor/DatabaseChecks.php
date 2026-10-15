<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Auth\Roles;
use AtarCms\Database\Connection;
use AtarCms\Database\Migrator;
use Throwable;

/**
 * The database: it connects, the server is new enough for the SQL the core uses (JSON_VALUE, recursive CTEs, CHECK
 * constraints, indexed virtual columns, InnoDB FULLTEXT), three-letter words are searchable, every migration ran and
 * someone can sign in to the admin.
 */
final class DatabaseChecks implements Check
{
    private const string NO_CONNECTION = 'skipped: no database connection (see db.connect)';

    public function run(DoctorContext $context): array
    {
        $app = $context->app();
        if ($app === null) {
            return array_map($context->skipWithoutBoot(...), ['db.connect', 'db.server', 'db.fulltext', 'db.migrations', 'db.admin']);
        }
        $config = $context->config();
        $where = sprintf('%s@%s:%d/%s', $config?->string('database.username', '') ?? '', $config?->string('database.host', '') ?? '', $config?->int('database.port', 3306) ?? 3306, $config?->string('database.database', '') ?? '');
        try {
            $db = $app->container()->service(Connection::class);
        } catch (Throwable $e) {
            return [
                CheckResult::fail('db.connect', sprintf('Cannot connect to the database %s: %s', $where, $this->clean($e->getMessage(), $config?->string('database.password', '') ?? '')), 'check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env, that the server runs and that the user has all privileges on the database'),
                ...array_map(static fn(string $id): CheckResult => CheckResult::skip($id, self::NO_CONNECTION), ['db.server', 'db.fulltext', 'db.migrations', 'db.admin']),
            ];
        }
        $results = [CheckResult::ok('db.connect', 'connected to ' . $where)];
        $results[] = self::server(self::text($db->scalar('SELECT VERSION()')));
        $tokenSize = self::number($db->scalar('SELECT @@innodb_ft_min_token_size'));
        $results[] = $tokenSize <= 3
            ? CheckResult::ok('db.fulltext', 'innodb_ft_min_token_size ' . $tokenSize)
            : CheckResult::fail('db.fulltext', sprintf('innodb_ft_min_token_size is %d: search does not find words of 3 letters', $tokenSize), 'set innodb_ft_min_token_size = 3 under [mysqld] in the server\'s my.cnf, restart it, then run bin/atar search:reindex');

        $pending = $app->container()->service(Migrator::class)->pendingReadOnly();
        $results[] = $pending === []
            ? CheckResult::ok('db.migrations', 'every migration ran')
            : CheckResult::fail('db.migrations', sprintf('%d migrations have not run (%s)', count($pending), $pending[0] . (count($pending) > 1 ? ', …' : '')), 'bin/atar migrate');
        $results[] = $this->admin($context, $db);

        return $results;
    }

    /**
     * The verdict on the server's VERSION().
     */
    public static function server(string $version): CheckResult
    {
        // MariaDB 10 once reported itself as "5.5.5-10.x.y-MariaDB" for old replication clients.
        $raw = (string) preg_replace('/^5\.5\.5-/', '', $version);
        preg_match('/^(\d+\.\d+\.\d+)/', $raw, $match);
        $number = $match[1] ?? '0.0.0';
        if (stripos($raw, 'mariadb') !== false) {
            $name = 'MariaDB ' . $number;

            return match (true) {
                version_compare($number, '10.11.0', '>=') => CheckResult::ok('db.server', $name),
                version_compare($number, '10.6.0', '>=') => CheckResult::warn('db.server', $name . ' should work but is not tested (tested: 10.11 and 11)', 'use MariaDB 10.11 or newer'),
                default => CheckResult::fail('db.server', $name . ' is too old: AtarCMS needs MariaDB 10.11 or newer (JSON_VALUE, recursive queries, indexed virtual columns)', 'upgrade to MariaDB 10.11 or newer'),
            };
        }
        $name = 'MySQL ' . $number;

        return version_compare($number, '8.0.21', '>=')
            ? CheckResult::warn('db.server', $name . ' should work but is not tested (AtarCMS is tested on MariaDB)', 'MariaDB 10.11 or newer is the tested server')
            : CheckResult::fail('db.server', $name . ' is too old: AtarCMS needs MariaDB 10.11+ (or MySQL 8.0.21+)', 'use MariaDB 10.11 or newer');
    }

    private function admin(DoctorContext $context, Connection $db): CheckResult
    {
        $app = $context->app();
        if ($app === null || self::number($db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['users'])) === 0) {
            return CheckResult::skip('db.admin', 'skipped: the users table does not exist yet (run bin/atar migrate)');
        }
        $roles = $app->container()->service(Roles::class);
        $admins = array_values(array_filter(array_keys($roles->labels()), static fn(string $role): bool => $roles->allows($role, 'users.manage')));
        $count = $admins === [] ? 0 : self::number($db->scalar(
            sprintf("SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN (%s)", implode(', ', array_fill(0, count($admins), '?'))),
            $admins,
        ));

        return $count > 0
            ? CheckResult::ok('db.admin', sprintf('%d active administrator%s', $count, $count === 1 ? '' : 's'))
            : CheckResult::warn('db.admin', 'Nobody can sign in to the admin yet', 'bin/atar user:create you@example.com --role=admin --name="Your Name", then bin/atar user:login-link you@example.com');
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function clean(string $message, string $password): string
    {
        return $password === '' ? $message : str_replace($password, '***', $message);
    }
}
