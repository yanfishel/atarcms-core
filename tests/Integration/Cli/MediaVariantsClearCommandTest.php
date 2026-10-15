<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Cli;

use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\TempDirectory;
use RuntimeException;

final class MediaVariantsClearCommandTest extends DatabaseTestCase
{
    use TempDirectory;

    private Application $app;

    private MediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_DEBUG=0');
        $this->app = Application::fromSite(__DIR__ . '/../../Fixtures/site');
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
        $this->app->container()->set(MediaStorage::class, $this->storage);
        $path = '2026/09/' . str_repeat('a', 32) . '.jpg';
        $this->storage->writeVariant('thumb', $path, 'x');
        $this->storage->writeVariant('thumb', $path . '.webp', 'x');
        $this->storage->writeVariant('medium', $path, 'x');
    }

    public function testClearsOneVariantOrAll(): void
    {
        self::assertSame([0, "Deleted 2 cached variant files.\n", ''], $this->console(['media:variants:clear', '--variant=thumb']));
        self::assertSame([0, "Deleted 1 cached variant file.\n", ''], $this->console(['media:variants:clear']));
        self::assertSame([0, "Deleted 0 cached variant files.\n", ''], $this->console(['media:variants:clear']));
    }

    public function testRejectsInvalidVariantNames(): void
    {
        [$code, , $error] = $this->console(['media:variants:clear', '--variant=../uploads']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Invalid variant name "../uploads"', $error);

        [$code, , $error] = $this->console(['media:variants:clear', '--variant']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--variant needs a name', $error);
        self::assertFileExists($this->storage->variant('medium', '2026/09/' . str_repeat('a', 32) . '.jpg'));
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private function console(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = $this->app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
