<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\Config;
use AtarCms\Media\ImageFormats;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImageFormatsTest extends TestCase
{
    public function testAvifFollowsGdUnlessTurnedOff(): void
    {
        $gd = function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false) === true;

        self::assertSame($gd, ImageFormats::gdHasAvif());
        self::assertSame($gd, ImageFormats::gdWritesAvif(), 'a GD built with AVIF really encodes it here');
        self::assertSame($gd, ImageFormats::fromConfig(new Config())->avif());
        self::assertSame($gd, ImageFormats::fromConfig(new Config(['media' => ['avif' => true]]))->avif());
        self::assertFalse(ImageFormats::fromConfig(new Config(['media' => ['avif' => false]]))->avif());
        self::assertFalse(new ImageFormats()->avif());
        self::assertTrue(new ImageFormats(true)->avif());
    }

    public function testTheProbeRunsOnceOnFirstUse(): void
    {
        $runs = 0;
        $formats = new ImageFormats(static function () use (&$runs): bool {
            $runs++;

            return true;
        });

        self::assertSame(0, $runs, 'nothing is probed before an image needs it');
        self::assertTrue($formats->avif());
        self::assertTrue($formats->avif());
        self::assertSame(1, $runs);
    }

    public function testAnEncoderThatFailsTurnsAvifOff(): void
    {
        self::assertFalse(ImageFormats::encodes(static fn(\GdImage $image, mixed $stream): bool => false), 'false');
        self::assertFalse(ImageFormats::encodes(static fn(\GdImage $image, mixed $stream): bool => true), 'nothing written');
        self::assertFalse(ImageFormats::encodes(static fn(\GdImage $image, mixed $stream): bool => throw new RuntimeException('libavif: no encoder')), 'an error');
        self::assertTrue(ImageFormats::encodes(static fn(\GdImage $image, mixed $stream): bool => is_resource($stream) && fwrite($stream, 'avif') === 4));
    }
}
