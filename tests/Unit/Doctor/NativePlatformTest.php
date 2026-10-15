<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\NativePlatform;
use AtarCms\Media\ImageFormats;
use PHPUnit\Framework\TestCase;

final class NativePlatformTest extends TestCase
{
    public function testGdInfoSaysWhetherTheAvifEncoderWorks(): void
    {
        $info = new NativePlatform()->gdInfo();

        if (ImageFormats::gdHasAvif()) {
            self::assertSame(ImageFormats::gdWritesAvif(), $info['AVIF Encodes'] ?? null);
        } else {
            self::assertArrayNotHasKey('AVIF Encodes', $info, 'only asked of a GD built with AVIF');
        }
    }
}
