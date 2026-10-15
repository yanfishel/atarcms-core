<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Avatar;

use AtarCms\Auth\Avatar\AvatarFailed;
use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvatarsTest extends TestCase
{
    use TempDirectory;

    public function testAPhotoIsStoredAsASquareWebp(): void
    {
        $avatars = $this->avatars();

        $version = $avatars->save(7, TestImages::jpeg(200, 100));

        $file = $this->tempDir() . '/avatars/7.webp';
        self::assertSame($file, $avatars->path(7));
        self::assertSame([96, 96, IMAGETYPE_WEBP], array_slice(getimagesize($file) ?: [], 0, 3));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        self::assertSame(substr(hash_file('sha256', $file) ?: '', 0, 12), $version);
        self::assertSame(['7.webp'], array_values(array_diff(scandir($this->tempDir() . '/avatars') ?: [], ['.', '..'])), 'no temporary file is left');
    }

    public function testDeleteRemovesThePhotoAndIsQuietWithoutOne(): void
    {
        $avatars = $this->avatars();
        $avatars->save(7, TestImages::png(120, 120));
        $avatars->save(8, TestImages::png(120, 120));

        $avatars->delete(7);
        $avatars->delete(7);
        $avatars->delete(9);

        self::assertFileDoesNotExist($avatars->path(7));
        self::assertFileExists($avatars->path(8));
        // A directory that was never created is fine too.
        new Avatars($this->tempDir() . '/none', new MediaConfig(), new ImageProcessor())->delete(1);
    }

    public function testANewPhotoReplacesTheOldOne(): void
    {
        $avatars = $this->avatars();
        $first = $avatars->save(7, TestImages::png(120, 120));

        $second = $avatars->save(7, TestImages::gif(300, 300));

        self::assertNotSame($first, $second);
        self::assertSame([96, 96, IMAGETYPE_WEBP], array_slice(getimagesize($avatars->path(7)) ?: [], 0, 3));
    }

    public function testAPhotoOverTheAvatarPixelLimitIsRefusedBeforeDecoding(): void
    {
        self::assertSame(4_000_000, Avatars::MAX_PIXELS);
        self::assertLessThan(new MediaConfig()->maxPixels, Avatars::MAX_PIXELS, 'smaller than the uploads\' limit');

        $this->expectException(AvatarFailed::class);
        $this->expectExceptionMessage('too many pixels');

        $this->avatars()->save(7, TestImages::png(2100, 2000));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'a PDF' => [TestImages::pdf()];
        yield 'not an image' => ['<html>not a photo</html>'];
        yield 'too many pixels' => [TestImages::pngBomb()];
        yield 'a truncated image' => [substr(TestImages::jpeg(200, 200), 0, 200)];
    }

    #[DataProvider('refused')]
    public function testAnythingButAnAllowedImageIsRefusedAndTheOldPhotoStays(string $bytes): void
    {
        $avatars = $this->avatars();
        $avatars->save(7, TestImages::jpeg(96, 96));
        $before = hash_file('sha256', $avatars->path(7));

        try {
            $avatars->save(7, $bytes);
            self::fail('The photo was accepted');
        } catch (AvatarFailed) {
            self::assertSame($before, hash_file('sha256', $avatars->path(7)));
            self::assertSame(['7.webp'], array_values(array_diff(scandir($this->tempDir() . '/avatars') ?: [], ['.', '..'])));
        }
    }

    private function avatars(): Avatars
    {
        return new Avatars($this->tempDir() . '/avatars', new MediaConfig(), new ImageProcessor());
    }
}
