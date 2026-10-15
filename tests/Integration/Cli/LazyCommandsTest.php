<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Cli;

use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\UnreachableDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * bin/atar must work without a database for every command that does not need one.
 */
final class LazyCommandsTest extends TestCase
{
    use TempDirectory;

    private const string SITE = __DIR__ . '/../../Fixtures/site';

    protected function setUp(): void
    {
        UnreachableDatabase::apply();
        putenv('APP_DEBUG=0');
    }

    protected function tearDown(): void
    {
        UnreachableDatabase::restore();
    }

    public function testListWorksWithoutDatabase(): void
    {
        [$code, $output, $error] = $this->console([]);

        self::assertSame(0, $code, $error);
        self::assertStringContainsString('migrate', $output);
        self::assertStringContainsString('media:variants:clear', $output);
        self::assertStringContainsString('media:references:prune', $output);
        self::assertStringContainsString('media:cleanup', $output);
        self::assertStringContainsString('search:reindex', $output);
        self::assertStringContainsString('user:login-link', $output);
    }

    public function testHelpWorksWithoutDatabase(): void
    {
        [$code, $listing, $error] = $this->console(['help']);
        self::assertSame(0, $code, $error);
        self::assertStringContainsString('media:cleanup', $listing);

        foreach ([['help', 'media:cleanup'], ['media:cleanup', '--help'], ['search:reindex', '-h']] as $argv) {
            [$code, $output, $error] = $this->console($argv);
            self::assertSame(0, $code, $error);
            self::assertSame('', $error);
            self::assertStringStartsWith('Usage: php bin/atar ', explode("\n", $output)[1]);
        }
        self::assertStringEndsWith("\nUsage: php bin/atar media:cleanup [--dry-run]\n", $this->console(['media:cleanup', '--help'])[1]);
    }

    public function testMediaVariantsClearWorksWithoutDatabase(): void
    {
        [$code, $output, $error] = $this->console(['media:variants:clear'], function (Application $app): void {
            $app->container()->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
        });

        self::assertSame(0, $code, $error);
        self::assertSame("Deleted 0 cached variant files.\n", $output);
    }

    public function testMigrateConnectsOnlyWhenRun(): void
    {
        [$code, , $error] = $this->console(['migrate']);

        self::assertSame(1, $code);
        self::assertStringContainsString('PDOException', $error);
    }

    /**
     * @param list<string> $argv
     * @param (callable(Application): void)|null $configure
     *
     * @return array{int, string, string}
     */
    private function console(array $argv, ?callable $configure = null): array
    {
        $app = Application::fromSite(self::SITE);
        if ($configure !== null) {
            $configure($app);
        }
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = $app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
