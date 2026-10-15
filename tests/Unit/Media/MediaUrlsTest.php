<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\Config;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaUrls;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaUrlsTest extends TestCase
{
    public function testBuildsOriginalAndVariantUrls(): void
    {
        $variants = new ImageVariants(['thumb' => ImageVariant::fromArray('thumb', ['width' => 320])]);
        $urls = new MediaUrls($variants, new Config(['app' => ['url' => 'https://example.org/']]));
        $jpeg = self::media('image/jpeg', 'jpg');
        $webp = self::media('image/webp', 'webp');
        $version = $variants->get('thumb')?->version();

        self::assertSame('/uploads/' . $jpeg->path, $urls->original($jpeg));
        self::assertSame('/media/thumb/' . $jpeg->path . '?v=' . $version, $urls->variant($jpeg, 'thumb'));
        self::assertSame('/media/thumb/' . $jpeg->path . '.webp?v=' . $version, $urls->variant($jpeg, 'thumb', true));
        self::assertSame('/media/thumb/' . $webp->path . '?v=' . $version, $urls->variant($webp, 'thumb', true));
        self::assertSame('/media/admin/' . $jpeg->path . '.webp?v=' . $variants->get('admin')?->version(), $urls->variant($jpeg, 'admin', true));
        self::assertSame('https://example.org/uploads/' . $jpeg->path, $urls->absolute($urls->original($jpeg)));
        $icon = '?v=' . $variants->get('icon_192')?->version();
        self::assertSame('/media/icon_192/' . $jpeg->path . '.png' . $icon, $urls->variant($jpeg, 'icon_192'), 'the site icon is always a PNG');
        self::assertSame('/media/icon_192/' . $webp->path . '.png' . $icon, $urls->variant($webp, 'icon_192', true));
        $png = self::media('image/png', 'png');
        self::assertSame('/media/icon_192/' . $png->path . $icon, $urls->variant($png, 'icon_192'));
    }

    public function testBuildsAvifVariantUrls(): void
    {
        $variants = new ImageVariants(['thumb' => ImageVariant::fromArray('thumb', ['width' => 320])]);
        $urls = new MediaUrls($variants, new Config());
        $version = '?v=' . $variants->get('thumb')?->version();
        $jpeg = self::media('image/jpeg', 'jpg');
        $webp = self::media('image/webp', 'webp');

        self::assertSame('/media/thumb/' . $jpeg->path . '.avif' . $version, $urls->avif($jpeg, 'thumb'));
        self::assertSame('/media/thumb/' . $webp->path . '.avif' . $version, $urls->avif($webp, 'thumb'));
        $this->expectException(InvalidArgumentException::class);
        $urls->avif($jpeg, 'icon_192');
    }

    public function testRejectsUnknownVariantsAndNonImages(): void
    {
        $urls = new MediaUrls(new ImageVariants(), new Config());

        foreach ([
            fn() => $urls->variant(self::media('image/jpeg', 'jpg'), 'thumb'),
            fn() => $urls->variant(self::media('application/pdf', 'pdf'), 'admin'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function media(string $mime, string $extension): Media
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');

        return new Media(1, '2026/09/' . str_repeat('a', 32) . '.' . $extension, 'x.' . $extension, $mime, 10, 1200, 800, [], null, $now, $now);
    }
}
