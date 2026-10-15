<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Cli\CliException;
use AtarCms\Cli\Command\CollectionsSyncCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\ContentModule;
use AtarCms\Content\IndexedColumns;
use AtarCms\Database\Migrator;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\TempDirectory;
use RuntimeException;

final class CollectionsSyncCommandTest extends ContentTestCase
{
    use TempDirectory;

    public function testWritesAMigrationThatBringsTheColumnsInLine(): void
    {
        $paths = new Paths($this->tempDir());

        [$code, $out] = $this->runCommand($paths);

        self::assertSame(0, $code);
        self::assertSame("Created database/migrations/2026_09_28_120000_sync_collections.php; apply it with \"bin/atar migrate\".\n", $out);
        $source = (string) file_get_contents($paths->migrations() . '/2026_09_28_120000_sync_collections.php');
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $source);
        self::assertStringContainsString('ADD COLUMN `f_rating` DOUBLE', $source);

        $this->migrator($paths)->migrate();
        self::assertSame(['f_event_date', 'f_kind', 'f_rating'], array_keys(new IndexedColumns($this->db, $this->collections)->existing()));

        [$code, $out] = $this->runCommand($paths);
        self::assertSame(0, $code);
        self::assertSame("Indexed columns are up to date.\n", $out);
    }

    public function testTheMigrationIdSortsAfterEveryExistingMigrationEvenWithAnEarlierClock(): void
    {
        $this->clock = new FrozenClock('2026-09-28 09:00:00');
        $paths = new Paths($this->tempDir());

        [$code, $out] = $this->runCommand($paths);

        self::assertSame(0, $code);
        self::assertSame("Created database/migrations/2026_09_28_110901_sync_collections.php; apply it with \"bin/atar migrate\".\n", $out);
        $ids = array_keys($this->migrator($paths)->discover());
        self::assertSame('2026_09_28_110901_sync_collections', end($ids));
        self::assertGreaterThan(0, strcmp('2026_09_28_110901_sync_collections', '2026_09_28_110900_create_slug_history_table'));
    }

    public function testTheGeneratedMigrationRunsAfterTheCoreMigrationsOnAFreshDatabase(): void
    {
        $this->clock = new FrozenClock('2026-09-28 09:00:00');
        $paths = new Paths($this->tempDir());
        $this->runCommand($paths);

        $this->dropAllTables();
        $this->migrator($paths)->migrate();

        self::assertSame(['f_event_date', 'f_kind', 'f_rating'], array_keys(new IndexedColumns($this->db, $this->collections)->existing()));
    }

    public function testRefusesWhileMigrationsArePending(): void
    {
        $paths = new Paths($this->tempDir());
        $this->runCommand($paths);

        $this->expectException(CliException::class);
        $this->expectExceptionMessage('pending migrations');
        $this->runCommand($paths);
    }

    public function testTheContentModuleRegistersTheCommand(): void
    {
        self::assertContains(CollectionsSyncCommand::class, new ContentModule()->commands());
    }

    private function migrator(Paths $paths): Migrator
    {
        return new Migrator($this->db, [dirname(__DIR__, 3) . '/resources/migrations', $paths->migrations()]);
    }

    /**
     * @return array{int, string}
     */
    private function runCommand(Paths $paths): array
    {
        $command = new CollectionsSyncCommand(new IndexedColumns($this->db, $this->collections), $this->migrator($paths), $paths, $this->clock);
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = $command->run(Input::parse([]), new Output($stdout, $stderr));
        rewind($stdout);

        return [$code, (string) stream_get_contents($stdout)];
    }
}
