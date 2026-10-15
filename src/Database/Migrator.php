<?php

declare(strict_types=1);

namespace AtarCms\Database;

use DateTimeImmutable;
use Throwable;

final class Migrator
{
    private const string TABLE = 'migrations';

    /**
     * @param list<string> $directories
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $directories,
    ) {}

    /**
     * @return array<string, string> migration id => file, sorted by id
     */
    public function discover(): array
    {
        $found = [];
        foreach ($this->directories as $directory) {
            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
                $id = basename($file, '.php');
                if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $id) !== 1) {
                    throw new MigrationException(sprintf(
                        'Invalid migration file name "%s"; expected YYYY_MM_DD_HHMMSS_description.php',
                        $file,
                    ));
                }
                if (isset($found[$id])) {
                    throw new MigrationException(sprintf('Duplicate migration id "%s" in %s and %s', $id, $found[$id], $file));
                }
                $found[$id] = $file;
            }
        }
        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * @return list<string>
     */
    public function applied(): array
    {
        $this->ensureTable();

        return array_map(
            static function (array $row): string {
                /** @var string $id */
                $id = $row['id'];

                return $id;
            },
            $this->db->select('SELECT id FROM ' . $this->db->quoteIdentifier(self::TABLE) . ' ORDER BY id'),
        );
    }

    /**
     * @return list<string>
     */
    public function pending(): array
    {
        return array_values(array_diff(array_keys($this->discover()), $this->applied()));
    }

    /**
     * The pending migrations without writing anything (pending() creates the migrations table): bin/atar doctor may
     * look at a database it must not change.
     *
     * @return list<string>
     */
    public function pendingReadOnly(): array
    {
        $exists = $this->db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [self::TABLE]);
        if (!is_numeric($exists) || (int) $exists === 0) {
            return array_keys($this->discover());
        }
        $applied = array_map(
            static fn(array $row): string => is_scalar($row['id']) ? (string) $row['id'] : '',
            $this->db->select('SELECT id FROM ' . $this->db->quoteIdentifier(self::TABLE)),
        );

        return array_values(array_diff(array_keys($this->discover()), $applied));
    }

    /**
     * @return list<string> ids applied by this call
     */
    public function migrate(): array
    {
        $files = $this->discover();
        $applied = [];
        foreach (array_diff(array_keys($files), $this->applied()) as $id) {
            $migration = (static fn(string $__file): mixed => require $__file)($files[$id]);
            if (!$migration instanceof Migration) {
                throw new MigrationException(sprintf(
                    'Migration file %s must return an instance of %s',
                    $files[$id],
                    Migration::class,
                ));
            }

            try {
                $migration->up($this->db);
            } catch (Throwable $e) {
                throw new MigrationException(sprintf('Migration %s failed: %s', $id, $e->getMessage()), 0, $e);
            }
            $this->db->insert(self::TABLE, ['id' => $id, 'applied_at' => new DateTimeImmutable()]);
            $applied[] = $id;
        }

        return $applied;
    }

    /**
     * @return list<array{id: string, applied: bool}>
     */
    public function status(): array
    {
        $applied = array_fill_keys($this->applied(), true);
        $ids = array_unique([...array_keys($this->discover()), ...array_keys($applied)]);
        sort($ids, SORT_STRING);

        return array_map(
            static fn(string $id): array => ['id' => $id, 'applied' => isset($applied[$id])],
            $ids,
        );
    }

    private function ensureTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . $this->db->quoteIdentifier(self::TABLE) . ' (
                id VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
}
