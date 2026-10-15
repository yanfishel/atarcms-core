<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Database;

use AtarCms\Database\MigrationException;
use AtarCms\Database\Migrator;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\TempDirectory;

final class MigratorTest extends DatabaseTestCase
{
    use TempDirectory;

    public function testAppliesPendingMigrationsInIdOrderAcrossDirectories(): void
    {
        $this->writeFile('core/2026_01_01_000002_create_b.php', $this->creating('b'));
        $this->writeFile('site/2026_01_01_000001_create_a.php', $this->creating('a'));
        $migrator = $this->migrator();
        $expected = ['2026_01_01_000001_create_a', '2026_01_01_000002_create_b'];

        self::assertSame($expected, $migrator->pending());
        self::assertSame($expected, $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate());
        self::assertSame($expected, $migrator->applied());
        self::assertSame(2, $this->db->scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('a', 'b')",
        ));
    }

    public function testStatusListsAppliedAndPending(): void
    {
        $this->writeFile('core/2026_01_01_000001_create_a.php', $this->creating('a'));
        $migrator = $this->migrator();
        $migrator->migrate();
        $this->writeFile('site/2026_01_01_000002_create_b.php', $this->creating('b'));

        self::assertSame([
            ['id' => '2026_01_01_000001_create_a', 'applied' => true],
            ['id' => '2026_01_01_000002_create_b', 'applied' => false],
        ], $migrator->status());
    }

    public function testRejectsInvalidFileNames(): void
    {
        $this->writeFile('core/create_users.php', $this->creating('users'));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Invalid migration file name');

        $this->migrator()->discover();
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->writeFile('core/2026_01_01_000001_create_a.php', $this->creating('a'));
        $this->writeFile('site/2026_01_01_000001_create_a.php', $this->creating('a'));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Duplicate migration id');

        $this->migrator()->discover();
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $this->writeFile('core/2026_01_01_000001_create_a.php', $this->creating('a'));
        $this->writeFile('core/2026_01_01_000002_broken.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use AtarCms\Database\Connection;
            use AtarCms\Database\Migration;

            return new class implements Migration {
                public function up(Connection $db): void
                {
                    throw new RuntimeException('bad schema');
                }
            };
            PHP);
        $migrator = $this->migrator();

        try {
            $migrator->migrate();
            self::fail('Expected MigrationException');
        } catch (MigrationException $e) {
            self::assertStringContainsString('2026_01_01_000002_broken', $e->getMessage());
            self::assertSame('bad schema', $e->getPrevious()?->getMessage());
        }

        self::assertSame(['2026_01_01_000001_create_a'], $migrator->applied());
    }

    public function testFileMustReturnMigration(): void
    {
        $this->writeFile('core/2026_01_01_000001_wrong.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn 42;\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must return an instance of');

        $this->migrator()->migrate();
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, [$this->tempDir() . '/core', $this->tempDir() . '/site']);
    }

    private function creating(string $table): string
    {
        return sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            use AtarCms\Database\Connection;
            use AtarCms\Database\Migration;

            return new class implements Migration {
                public function up(Connection $db): void
                {
                    $db->execute('CREATE TABLE %s (id INT PRIMARY KEY)');
                }
            };
            PHP, $table);
    }
}
