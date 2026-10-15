<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\Tests\Support\TempDirectory;
use AtarCms\View\AssetUrl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
    use TempDirectory;

    public function testAppendsContentHash(): void
    {
        $this->writeFile('assets/css/site.css', 'body{}');

        $url = (new AssetUrl($this->tempDir() . '/assets'))->url('/css/site.css');

        self::assertMatchesRegularExpression('~^/theme-assets/css/site\.css\?v=[0-9a-f]{12}$~', $url);
    }

    public function testHashChangesWithContent(): void
    {
        $this->writeFile('assets/app.js', 'one');
        $first = (new AssetUrl($this->tempDir() . '/assets'))->url('app.js');
        $this->writeFile('assets/app.js', 'two');
        $second = (new AssetUrl($this->tempDir() . '/assets'))->url('app.js');

        self::assertNotSame($first, $second);
    }

    public function testTellsWhetherAnAssetExists(): void
    {
        $this->writeFile('assets/flags/en.png', 'png');
        $assets = new AssetUrl($this->tempDir() . '/assets');

        self::assertTrue($assets->exists('flags/en.png'));
        self::assertFalse($assets->exists('flags/ru.png'));
        self::assertFalse($assets->exists('flags'), 'a directory is no asset');
        self::assertFalse($assets->exists('../assets/flags/en.png'), 'never outside the assets');
    }

    public function testMissingFileHasNoVersionAndCustomPrefix(): void
    {
        self::assertSame(
            '/static/img/logo%20new.png',
            (new AssetUrl($this->tempDir(), '/static/'))->url('img/logo new.png'),
        );
    }

    public function testRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AssetUrl($this->tempDir()))->url('../config/app.php');
    }
}
