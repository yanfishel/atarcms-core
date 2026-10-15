<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\AppKey;
use AtarCms\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppKeyTest extends TestCase
{
    use TempDirectory;

    public function testTheKeyIsCreatedOnceAndKeptPrivate(): void
    {
        $file = $this->tempDir() . '/app.key';

        $key = (new AppKey($file))->value();

        self::assertSame(32, strlen($key));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) file_get_contents($file));
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame($key, (new AppKey($file))->value(), 'the stored key is reused');
        self::assertNotSame($key, (new AppKey($this->tempDir() . '/other.key'))->value());
    }

    /**
     * The key is a site's secret: git never takes it (the skeleton's own .gitignore for a site made from it, the
     * repository's for the skeleton inside it).
     */
    public function testTheKeyIsNeverCommitted(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertContains('/storage/app.key', file($root . '/skeleton/.gitignore', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertContains('/skeleton/storage/app.key', file($root . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: []);
        self::assertContains('/tests/Fixtures/site/storage/', file($root . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function testABrokenKeyFileStopsInsteadOfWeakeningTheKey(): void
    {
        $file = $this->writeFile('app.key', 'short');

        $this->expectException(RuntimeException::class);

        (new AppKey($file))->value();
    }
}
