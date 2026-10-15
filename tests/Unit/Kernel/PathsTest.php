<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testBuildsSitePaths(): void
    {
        $paths = new Paths('C:\\sites\\demo\\');

        self::assertSame('C:/sites/demo', $paths->base);
        self::assertSame('C:/sites/demo/config', $paths->config());
        self::assertSame('C:/sites/demo/storage', $paths->storage());
        self::assertSame('C:/sites/demo/storage', $paths->storageRoot);
        self::assertSame('C:/sites/demo/storage/logs', $paths->storage('/logs'));
        self::assertSame('C:/sites/demo/themes/default', $paths->theme('default'));
        self::assertSame('C:/sites/demo/database/migrations', $paths->migrations());
        self::assertSame('C:/sites/demo/.env', $paths->path('.env'));
    }

    public function testStorageCanLiveElsewhere(): void
    {
        $paths = new Paths('/srv/site', 'C:\\tmp\\atar-storage\\');

        self::assertSame('C:/tmp/atar-storage', $paths->storage());
        self::assertSame('C:/tmp/atar-storage/cache/media', $paths->storage('cache/media/'));
        self::assertSame('/srv/site/config', $paths->config());
    }
}
