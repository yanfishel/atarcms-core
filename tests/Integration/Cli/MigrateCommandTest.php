<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Cli;

use AtarCms\Cli\Command\MigrateCommand;
use AtarCms\Cli\Command\MigrateStatusCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Database\Migrator;
use AtarCms\Kernel\Application;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use RuntimeException;

final class MigrateCommandTest extends DatabaseTestCase
{
    use TempDirectory;

    public function testMigrateAndStatus(): void
    {
        $this->writeFile('migrations/2026_01_01_000001_create_a.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use AtarCms\Database\Connection;
            use AtarCms\Database\Migration;

            return new class implements Migration {
                public function up(Connection $db): void
                {
                    $db->execute('CREATE TABLE a (id INT PRIMARY KEY)');
                }
            };
            PHP);
        $migrator = new Migrator($this->db, [$this->tempDir() . '/migrations']);

        self::assertSame("[ ] 2026_01_01_000001_create_a\n", $this->invokeCommand(new MigrateStatusCommand($migrator)));
        self::assertSame("Migrated: 2026_01_01_000001_create_a\n", $this->invokeCommand(new MigrateCommand($migrator)));
        self::assertSame("Nothing to migrate.\n", $this->invokeCommand(new MigrateCommand($migrator)));
        self::assertSame("[x] 2026_01_01_000001_create_a\n", $this->invokeCommand(new MigrateStatusCommand($migrator)));
    }

    public function testMigrateCreatesTheMissingPresetPagesOnce(): void
    {
        $app = Application::fromSite(__DIR__ . '/../../Fixtures/site');

        $first = $this->console($app);
        self::assertStringContainsString("Migrated: 2026_09_28_110000_create_entries_table\n", $first);
        self::assertStringEndsWith("Preset page created: info/legal (en)\nPreset page created: info/legal (ru)\n", $first);
        self::assertSame(2, Cast::int($this->db->scalar("SELECT COUNT(*) FROM entries WHERE preset = 'legal'")));

        self::assertSame("Nothing to migrate.\n", $this->console($app), 'a second run changes nothing');
        self::assertSame(2, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    public function testStatusWithoutMigrations(): void
    {
        $migrator = new Migrator($this->db, [$this->tempDir()]);

        self::assertSame("No migrations found.\n", $this->invokeCommand(new MigrateStatusCommand($migrator)));
    }

    private function console(Application $app): string
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        self::assertSame(0, $app->console()->run(['migrate'], new Output($stdout, $stderr)));
        rewind($stdout);
        rewind($stderr);
        self::assertSame('', stream_get_contents($stderr));

        return (string) stream_get_contents($stdout);
    }

    private function invokeCommand(MigrateCommand|MigrateStatusCommand $command): string
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        self::assertSame(0, $command->run(Input::parse([]), new Output($stdout, $stderr)));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
