<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\Config;
use AtarCms\Media\ImageFormats;
use AtarCms\Media\ImageTag;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaUrls;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ImageTagTest extends TestCase
{
    private ImageVariants $variants;

    private ImageTag $tag;

    protected function setUp(): void
    {
        $this->variants = new ImageVariants([
            'thumb' => ImageVariant::fromArray('thumb', ['width' => 320, 'height' => 320, 'fit' => 'crop']),
            'square' => ImageVariant::fromArray('square', ['width' => 640, 'height' => 640, 'fit' => 'crop']),
            'medium' => ImageVariant::fromArray('medium', ['width' => 800]),
            'large' => ImageVariant::fromArray('large', ['width' => 1600]),
        ]);
        $this->tag = new ImageTag($this->variants, new MediaUrls($this->variants, new Config()));
    }

    public function testRendersAPictureWithAWebpSourceAndTheOriginalFormat(): void
    {
        $media = self::media('image/jpeg', 'jpg', 1200, 800);
        $p = $media->path;
        $m = $this->version('medium');
        $l = $this->version('large');

        self::assertSame(
            '<picture><source type="image/webp" srcset="/media/medium/' . $p . '.webp?v=' . $m . ' 800w, /media/large/' . $p . '.webp?v=' . $l . ' 1200w" sizes="(max-width: 800px) 100vw, 800px">'
            . '<img src="/media/medium/' . $p . '?v=' . $m . '" srcset="/media/medium/' . $p . '?v=' . $m . ' 800w, /media/large/' . $p . '?v=' . $l . ' 1200w" sizes="(max-width: 800px) 100vw, 800px"'
            . ' width="800" height="533" alt="Sea &lt;b&gt; &quot;x&quot;" loading="lazy" decoding="async"></picture>',
            $this->tag->render($media, 'medium', ['alt' => 'Sea <b> "x"']),
        );
    }

    public function testCropFamiliesShareTheAspectRatioAndSmallImagesAreNotUpscaled(): void
    {
        $media = self::media('image/png', 'png', 500, 300);
        $p = $media->path;
        $t = $this->version('thumb');

        $html = $this->tag->render($media, 'thumb', ['class' => 'thumb', 'sizes' => '10rem', 'loading' => 'eager', 'fetchpriority' => 'high']);

        self::assertStringContainsString('srcset="/media/thumb/' . $p . '?v=' . $t . ' 300w"', $html, 'square is 300 wide too: one candidate');
        self::assertStringNotContainsString('/media/square/', $html);
        self::assertStringNotContainsString('/media/medium/', $html);
        self::assertStringContainsString(' sizes="10rem" width="300" height="300" alt="" class="thumb" loading="eager" decoding="async" fetchpriority="high">', $html);
        self::assertStringContainsString('<source type="image/webp" srcset="/media/thumb/' . $p . '.webp?v=' . $t . ' 300w" sizes="10rem">', $html);
    }

    public function testAWebpOriginalNeedsNoPicture(): void
    {
        $media = self::media('image/webp', 'webp', 1600, 900);

        $html = $this->tag->render($media, 'large', ['alt' => 'x']);

        self::assertStringStartsWith('<img src="/media/large/' . $media->path . '?v=' . $this->version('large') . '" srcset="/media/medium/' . $media->path . '?v=' . $this->version('medium') . ' 800w, /media/large/', $html);
        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('.webp.webp', $html);
        self::assertStringContainsString('width="1600" height="900"', $html);
    }

    public function testRejectsUnknownAttributesVariantsAndNonImages(): void
    {
        foreach ([
            fn() => $this->tag->render(self::media('image/jpeg', 'jpg', 10, 10), 'medium', ['onerror' => 'alert(1)']),
            fn() => $this->tag->render(self::media('image/jpeg', 'jpg', 10, 10), 'medium', ['class' => ['a']]),
            fn() => $this->tag->render(self::media('image/jpeg', 'jpg', 10, 10), 'huge'),
            fn() => $this->tag->render(self::media('application/pdf', 'pdf', null, null), 'medium'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNullAttributesAreTreatedAsUnset(): void
    {
        $media = self::media('image/jpeg', 'jpg', 1200, 800);

        self::assertSame(
            $this->tag->render($media, 'medium'),
            $this->tag->render($media, 'medium', ['alt' => null, 'class' => null, 'title' => null, 'sizes' => null]),
        );
    }

    public function testArtDirectedSourcesComeFirstWithTheirOwnSizes(): void
    {
        $tag = $this->tagWith(false);
        $media = self::media('image/jpeg', 'jpg', 1200, 800);
        $p = $media->path;
        $s = $this->version('square');
        $t = $this->version('thumb');

        $html = $tag->render($media, 'medium', ['alt' => 'x', 'sources' => ['(max-width: 40rem)' => 'square']]);

        self::assertStringStartsWith(
            '<picture><source media="(max-width: 40rem)" type="image/webp" srcset="/media/thumb/' . $p . '.webp?v=' . $t . ' 320w, /media/square/' . $p . '.webp?v=' . $s . ' 640w" sizes="(max-width: 640px) 100vw, 640px" width="640" height="640">'
            . '<source media="(max-width: 40rem)" srcset="/media/thumb/' . $p . '?v=' . $t . ' 320w, /media/square/' . $p . '?v=' . $s . ' 640w" sizes="(max-width: 640px) 100vw, 640px" width="640" height="640">'
            . '<source type="image/webp" srcset="/media/medium/' . $p . '.webp?v=',
            $html,
        );
        self::assertStringEndsWith(' width="800" height="533" alt="x" loading="lazy" decoding="async"></picture>', $html);
    }

    public function testArtDirectedSourcesTakeAnotherPictureAndTheGivenSizes(): void
    {
        $tag = $this->tagWith(false);
        $media = self::media('image/jpeg', 'jpg', 1200, 800);
        $other = new Media(8, '2026/09/' . str_repeat('c', 32) . '.webp', 'y', 'image/webp', 10, 300, 600, [], null, new DateTimeImmutable(), new DateTimeImmutable());
        $t = $this->version('thumb');

        $html = $tag->render($media, 'medium', ['sizes' => '100vw', 'sources' => ['(orientation: portrait) and (max-width: 40rem)' => [$other, 'thumb'], '(min-width: 90rem)' => [null, 'large']]]);

        self::assertStringStartsWith(
            '<picture><source media="(orientation: portrait) and (max-width: 40rem)" srcset="/media/thumb/' . $other->path . '?v=' . $t . ' 300w" sizes="100vw" width="300" height="300">'
            . '<source type="image/webp" srcset="/media/medium/' . $media->path . '.webp?v=',
            $html,
            'a WebP picture needs one source; a null picture (an empty field) adds none',
        );
        self::assertStringNotContainsString('min-width: 90rem', $html);
    }

    public function testMediaQueriesAreEscaped(): void
    {
        $html = $this->tag->render(self::media('image/jpeg', 'jpg', 1200, 800), 'medium', ['sources' => ['(max-width: 40rem)" onload="x' => 'thumb']]);

        self::assertStringContainsString('<source media="(max-width: 40rem)&quot; onload=&quot;x" type="image/webp"', $html);
        self::assertStringNotContainsString('" onload="', $html);
    }

    public function testRejectsInvalidSources(): void
    {
        $jpeg = self::media('image/jpeg', 'jpg', 1200, 800);
        $pdf = self::media('application/pdf', 'pdf', null, null);
        foreach ([
            ['sources' => 'thumb'],
            ['sources' => ['thumb']],
            ['sources' => ['' => 'thumb']],
            ['sources' => ['(max-width: 40rem)' => 'huge']],
            ['sources' => ['(max-width: 40rem)' => [null, 'huge']]],
            ['sources' => ['(max-width: 40rem)' => [$pdf, 'thumb']]],
            ['sources' => ['(max-width: 40rem)' => [$jpeg]]],
            ['sources' => ['(max-width: 40rem)' => ['thumb', $jpeg]]],
            ['sources' => ['(max-width: 40rem)' => 5]],
        ] as $index => $attributes) {
            try {
                $this->tag->render($jpeg, 'medium', $attributes);
                self::fail('Expected InvalidArgumentException for case ' . $index);
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString('Expected', $e->getMessage());
            }
        }
    }

    public function testAnUnknownArtDirectedVariantNamesThemePhp(): void
    {
        $this->expectExceptionMessage('declare it under "images" in theme.php');

        $this->tag->render(self::media('image/jpeg', 'jpg', 1200, 800), 'medium', ['sources' => ['(max-width: 40rem)' => 'huge']]);
    }

    public function testWithAvifEveryPictureGetsAnAvifSourceFirst(): void
    {
        $tag = $this->tagWith(true);
        $media = self::media('image/jpeg', 'jpg', 1200, 800);
        $p = $media->path;
        $m = $this->version('medium');
        $l = $this->version('large');
        $t = $this->version('thumb');

        self::assertSame(
            '<picture><source type="image/avif" srcset="/media/medium/' . $p . '.avif?v=' . $m . ' 800w, /media/large/' . $p . '.avif?v=' . $l . ' 1200w" sizes="(max-width: 800px) 100vw, 800px">'
            . '<source type="image/webp" srcset="/media/medium/' . $p . '.webp?v=' . $m . ' 800w, /media/large/' . $p . '.webp?v=' . $l . ' 1200w" sizes="(max-width: 800px) 100vw, 800px">'
            . '<img src="/media/medium/' . $p . '?v=' . $m . '" srcset="/media/medium/' . $p . '?v=' . $m . ' 800w, /media/large/' . $p . '?v=' . $l . ' 1200w" sizes="(max-width: 800px) 100vw, 800px"'
            . ' width="800" height="533" alt="" loading="lazy" decoding="async"></picture>',
            $tag->render($media, 'medium'),
        );
        self::assertStringStartsWith(
            '<picture><source media="(max-width: 40rem)" type="image/avif" srcset="/media/thumb/' . $p . '.avif?v=' . $t . ' 320w, ',
            $tag->render($media, 'medium', ['sources' => ['(max-width: 40rem)' => 'thumb']]),
        );

        $webp = self::media('image/webp', 'webp', 1600, 900);
        $html = $tag->render($webp, 'large');
        self::assertStringStartsWith('<picture><source type="image/avif" srcset="/media/medium/' . $webp->path . '.avif?v=' . $m . ' 800w, ', $html);
        self::assertStringContainsString('1600w" sizes="(max-width: 1600px) 100vw, 1600px"><img src="/media/large/' . $webp->path . '?v=', $html, 'a WebP original has no WebP source');
    }

    public function testWithoutAvifTheMarkupStaysAsBefore(): void
    {
        // Literally what img() printed before art direction and AVIF (main at 0b81775).
        $jpeg = self::media('image/jpeg', 'jpg', 1200, 800);
        $before = '<picture><source type="image/webp" srcset="/media/medium/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg.webp?v=7589a7d4 800w, /media/large/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg.webp?v=597e9ef5 1200w" sizes="(max-width: 800px) 100vw, 800px">'
            . '<img src="/media/medium/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg?v=7589a7d4" srcset="/media/medium/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg?v=7589a7d4 800w, /media/large/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg?v=597e9ef5 1200w" sizes="(max-width: 800px) 100vw, 800px"'
            . ' width="800" height="533" alt="a" class="c" loading="eager" decoding="async" fetchpriority="high"></picture>';
        $webp = self::media('image/webp', 'webp', 1600, 900);
        $webpBefore = '<img src="/media/large/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp?v=597e9ef5" srcset="/media/medium/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp?v=7589a7d4 800w, /media/large/2026/09/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp?v=597e9ef5 1600w"'
            . ' sizes="(max-width: 1600px) 100vw, 1600px" width="1600" height="900" alt="" loading="lazy" decoding="async">';
        $attributes = ['alt' => 'a', 'class' => 'c', 'loading' => 'eager', 'fetchpriority' => 'high'];

        foreach ([$this->tag, $this->tagWith(false)] as $tag) {
            self::assertSame($before, $tag->render($jpeg, 'medium', $attributes));
            self::assertSame($before, $tag->render($jpeg, 'medium', [...$attributes, 'sources' => []]));
            self::assertSame($before, $tag->render($jpeg, 'medium', [...$attributes, 'sources' => null]));
            self::assertSame($webpBefore, $tag->render($webp, 'large'));
        }
    }

    private function tagWith(bool $avif): ImageTag
    {
        return new ImageTag($this->variants, new MediaUrls($this->variants, new Config()), new ImageFormats($avif));
    }

    private function version(string $variant): string
    {
        return $this->variants->get($variant)?->version() ?? self::fail('No variant ' . $variant);
    }

    private static function media(string $mime, string $extension, ?int $width, ?int $height): Media
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');

        return new Media(7, '2026/09/' . str_repeat('b', 32) . '.' . $extension, 'x', $mime, 10, $width, $height, [], null, $now, $now);
    }
}
