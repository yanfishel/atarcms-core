<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Media\ImageFormats;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\ImageVariant;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ImageProcessorTest extends TestCase
{
    use TempDirectory;

    /**
     * A rotated photo just under the default pixel limit must be re-encoded within a 256M memory limit: the image is
     * scaled down before it is rotated. Runs in its own process because the limit is lowered for the test.
     */
    #[RunInSeparateProcess]
    public function testRotatesLargePhotosWithoutExhaustingMemory(): void
    {
        $canvas = imagecreatetruecolor(6000, 4166) ?: self::fail('GD could not create an image');
        ob_start();
        imagejpeg($canvas, null, 60);
        $jpeg = (string) ob_get_clean();
        unset($canvas);
        $exif = TestImages::exif(6);
        $file = $this->writeFile('big.jpg', substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
        unset($jpeg);

        ini_set('memory_limit', '256M');
        $image = new ImageProcessor()->reencode($file, 'image/jpeg', 4096, 82);

        self::assertSame([2844, 4096], [$image->width, $image->height]);
        self::assertSame([2844, 4096], array_slice((array) getimagesizefromstring($image->bytes), 0, 2));
    }

    public function testEncodesVariantsAsAvif(): void
    {
        if (!ImageFormats::gdWritesAvif()) {
            self::markTestSkipped('This PHP has no GD with AVIF (imageavif); the dev image (docker/php/Dockerfile) has it');
        }
        $png = $this->writeFile('photo.png', TestImages::png(640, 480));

        $bytes = new ImageProcessor()->variant($png, new ImageVariant('card', 320, 200, ImageVariant::CROP), 'image/avif');

        $info = getimagesizefromstring($bytes) ?: [];
        self::assertSame([320, 200, 'image/avif'], [$info[0] ?? null, $info[1] ?? null, $info['mime'] ?? null]);
    }

    public function testCoverFillsTheBoxEnlargingASmallImage(): void
    {
        $small = $this->writeFile('small.png', TestImages::png(64, 48));

        $image = new ImageProcessor()->cover($small, 680, 630);

        self::assertSame([680, 630], [imagesx($image), imagesy($image)]);
    }
}
