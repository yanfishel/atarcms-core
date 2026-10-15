<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Media\MediaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = MediaConfig::fromConfig(new Config());

        self::assertSame(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'video/webm'], $config->types);
        self::assertSame(10485760, $config->maxSize);
        self::assertSame(104857600, $config->maxVideoSize);
        self::assertSame(25000000, $config->maxPixels);
        self::assertSame(4096, $config->maxDimension);
        self::assertSame(82, $config->quality);
        self::assertSame(10.0, $config->maxSizeMegabytes());
        self::assertSame(100.0, $config->maxSizeMegabytes('video/mp4'));
        self::assertSame(10.0, $config->maxSizeMegabytes('application/pdf'));
        self::assertSame(104857600, $config->largestUpload());
        self::assertSame(25.0, $config->maxMegapixels());
        self::assertSame('JPEG, PNG, WebP, GIF, PDF, MP4, WebM', $config->labels());
        self::assertSame('image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,video/webm', $config->accept());
        self::assertSame('JPEG, PNG, WebP, GIF', $config->labels($config->imageTypes()));
        self::assertSame('MP4, WebM', $config->labels($config->videoTypes()));
        self::assertTrue($config->isVideo('video/webm'));
        self::assertFalse($config->isVideo('application/pdf'));
    }

    public function testTheVideoLimitCountsOnlyWhenVideosAreAllowed(): void
    {
        $config = MediaConfig::fromConfig(new Config(['media' => ['types' => ['image/png'], 'max_size' => 2097152, 'max_video_size' => 50 * 1048576]]));

        self::assertSame(50 * 1048576, $config->maxVideoSize);
        self::assertSame(2097152, $config->largestUpload());
        self::assertSame([], $config->videoTypes());
    }

    public function testReadsTheSiteConfig(): void
    {
        $config = MediaConfig::fromConfig(new Config(['media' => [
            'types' => ['image/png', 'image/png', 'application/pdf'],
            'max_size' => 2097152,
            'max_pixels' => 1000000,
            'max_dimension' => 2000,
            'quality' => 70,
        ]]));

        self::assertSame(['image/png', 'application/pdf'], $config->types);
        self::assertTrue($config->allows('image/png'));
        self::assertFalse($config->allows('image/jpeg'));
        self::assertSame('png', $config->extension('image/png'));
        self::assertSame('pdf', $config->extension('application/pdf'));
        self::assertTrue($config->isImage('image/png'));
        self::assertFalse($config->isImage('application/pdf'));
        self::assertSame(['image/png'], $config->imageTypes());
        self::assertSame([2097152, 1000000, 2000, 70], [$config->maxSize, $config->maxPixels, $config->maxDimension, $config->quality]);
        self::assertSame(2.0, $config->maxSizeMegabytes());
        self::assertSame(1.0, $config->maxMegapixels());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'svg' => [['types' => ['image/svg+xml']], 'SVG is not supported'];
        yield 'unknown type' => [['types' => ['text/html']], 'unsupported type "text/html"'];
        yield 'no types' => [['types' => []], 'at least one type'];
        yield 'types not a list' => [['types' => 'image/png'], 'must be a list'];
        yield 'zero size' => [['max_size' => 0], '"media.max_size" must be at least 1'];
        yield 'zero video size' => [['max_video_size' => 0], '"media.max_video_size" must be at least 1'];
        yield 'zero pixels' => [['max_pixels' => 0], '"media.max_pixels" must be at least 1'];
        yield 'tiny dimension' => [['max_dimension' => 10], '"media.max_dimension" must be between 16 and 16384'];
        yield 'quality' => [['quality' => 101], '"media.quality" must be between 1 and 100'];
    }

    /**
     * @param array<string, mixed> $media
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(array $media, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        MediaConfig::fromConfig(new Config(['media' => $media]));
    }
}
