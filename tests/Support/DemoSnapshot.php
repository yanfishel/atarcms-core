<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Database\Connection;
use AtarCms\Demo\DemoSeeder;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Paths;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The demo content of a site, made once per test process: the first seed() runs DemoSeeder::seed(null) and keeps what
 * it made (every table, AUTO_INCREMENT counters included, and the files it wrote to storage); a later seed() of the same
 * site, config and database state restores that instead of drawing the pictures and share images again. Tests that
 * render the demo site use it; the tests of the seeder itself seed for real.
 */
final class DemoSnapshot
{
    /** @var array<string, self> */
    private static array $snapshots = [];

    /**
     * @param array<string, array{create: string, rows: list<array<string, mixed>>}> $tables
     * @param array<string, string>                                                  $files  path relative to storage => contents
     */
    private function __construct(
        private readonly array $tables,
        private readonly array $files,
    ) {}

    public static function seed(Application $app, Connection $db): void
    {
        $container = $app->container();
        $paths = $container->service(Paths::class);
        $key = hash('xxh128', implode("\0", [
            $paths->base,
            str_replace($paths->storageRoot, '{storage}', (string) json_encode($container->service(Config::class)->all(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            self::shape($db),
        ]));
        if (isset(self::$snapshots[$key])) {
            self::$snapshots[$key]->restore($db, $paths->storageRoot);

            return;
        }
        $before = self::hashes($paths->storageRoot);
        $container->service(DemoSeeder::class)->seed(null);
        self::$snapshots[$key] = self::take($db, $paths->storageRoot, $before);
    }

    /**
     * @param array<string, string> $except files (relative path => sha1) to leave out while unchanged
     */
    public static function take(Connection $db, string $storage, array $except = []): self
    {
        $tables = [];
        foreach (self::tableNames($db) as $table) {
            $quoted = Connection::quoteIdentifier($table);
            $generated = array_map(
                static fn(array $row): string => Cast::string($row['name']),
                $db->select("SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND is_generated = 'ALWAYS'", [$table]),
            );
            $tables[$table] = [
                'create' => Cast::string($db->selectOne('SHOW CREATE TABLE ' . $quoted)['Create Table'] ?? null),
                'rows' => array_map(
                    static fn(array $row): array => array_diff_key($row, array_flip($generated)),
                    $db->select('SELECT * FROM ' . $quoted),
                ),
            ];
        }
        $files = [];
        $hashes = self::hashes($storage);
        $deleted = array_keys(array_diff_key($except, $hashes));
        if ($deleted !== []) {
            throw new LogicException(sprintf('The seed deleted %s; a snapshot cannot restore that', implode(', ', $deleted)));
        }
        foreach ($hashes as $path => $hash) {
            if (($except[$path] ?? null) !== $hash) {
                $files[$path] = (string) file_get_contents($storage . '/' . $path);
            }
        }

        return new self($tables, $files);
    }

    /**
     * Replaces every table with the snapshot's and writes its files into $storage (other files stay).
     */
    public function restore(Connection $db, string $storage): void
    {
        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tableNames($db) as $table) {
            $db->execute('DROP TABLE ' . Connection::quoteIdentifier($table));
        }
        foreach ($this->tables as $table => ['create' => $create, 'rows' => $rows]) {
            $db->execute($create);
            foreach (array_chunk($rows, 100) as $chunk) {
                $columns = array_keys($chunk[0]);
                $row = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $db->execute(
                    sprintf(
                        'INSERT INTO %s (%s) VALUES %s',
                        Connection::quoteIdentifier($table),
                        implode(', ', array_map(Connection::quoteIdentifier(...), $columns)),
                        implode(', ', array_fill(0, count($chunk), $row)),
                    ),
                    array_merge(...array_map(array_values(...), $chunk)),
                );
            }
        }
        $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        foreach ($this->files as $path => $contents) {
            $file = $storage . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $contents);
        }
    }

    /**
     * The structure (AUTO_INCREMENT counters included) and contents of every table.
     */
    public static function state(Connection $db): string
    {
        $state = [];
        foreach (self::tableNames($db) as $table) {
            $quoted = Connection::quoteIdentifier($table);
            $state[] = Cast::string($db->selectOne('SHOW CREATE TABLE ' . $quoted)['Create Table'] ?? null);
            $state[] = Cast::string($db->selectOne('CHECKSUM TABLE ' . $quoted)['Checksum'] ?? null);
        }

        return implode("\n", $state);
    }

    /**
     * What decides what a seed makes: the structure of every table (AUTO_INCREMENT counters included) and its number of
     * rows (the times the migrations ran at differ from test to test).
     */
    private static function shape(Connection $db): string
    {
        $shape = [];
        foreach (self::tableNames($db) as $table) {
            $quoted = Connection::quoteIdentifier($table);
            $shape[] = Cast::string($db->selectOne('SHOW CREATE TABLE ' . $quoted)['Create Table'] ?? null);
            $shape[] = Cast::string($db->scalar('SELECT COUNT(*) FROM ' . $quoted));
        }

        return implode("\n", $shape);
    }

    /**
     * @return array<string, string> every file below $directory (relative path) => its sha1, sorted by path
     */
    public static function hashes(string $directory): array
    {
        $hashes = [];
        if (!is_dir($directory)) {
            return $hashes;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $hashes[substr($file->getPathname(), strlen($directory) + 1)] = (string) sha1_file($file->getPathname());
            }
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * @return list<string>
     */
    private static function tableNames(Connection $db): array
    {
        return array_map(
            static fn(array $row): string => Cast::string($row['name']),
            $db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'),
        );
    }
}
