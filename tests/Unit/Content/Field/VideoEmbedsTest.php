<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\VideoEmbeds;
use PHPUnit\Framework\TestCase;

final class VideoEmbedsTest extends TestCase
{
    public function testRendersYouTubeAndVimeoPlayersWithoutInlineStyles(): void
    {
        $html = '<p>A</p><figure class="media"><oembed url="https://www.youtube.com/watch?v&#61;dQw4w9WgXcQ"></oembed></figure>'
            . '<figure class="media"><oembed url="https://vimeo.com/76979871"></oembed></figure>';

        self::assertSame(
            '<p>A</p><figure class="media"><iframe class="rich-text-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="YouTube" loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin"></iframe></figure>'
            . '<figure class="media"><iframe class="rich-text-video" src="https://player.vimeo.com/video/76979871" title="Vimeo" loading="lazy" allow="fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin"></iframe></figure>',
            VideoEmbeds::render($html),
        );
        self::assertStringNotContainsString('style', VideoEmbeds::render($html));
    }

    public function testRemovesEmbedsWithoutASupportedUrl(): void
    {
        self::assertSame(
            '<figure class="media"></figure><figure class="media"></figure><figure class="media">text</figure><p>B</p>',
            VideoEmbeds::render(
                '<figure class="media"><oembed></oembed></figure>'
                . '<figure class="media"><oembed url="https://evil.example/x"></oembed></figure>'
                . '<figure class="media"><oembed url="javascript:alert(1)">text</oembed></figure><p>B</p>',
            ),
        );
    }

    public function testNestedEmbedsKeepTheTagsBalanced(): void
    {
        $iframe = '<iframe class="rich-text-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="YouTube" loading="lazy" allow="accelerometer; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin"></iframe>';

        self::assertSame(
            '<div class="wrap"><div>' . $iframe . '</div></div><p>after</p>',
            VideoEmbeds::render('<div class="wrap"><oembed><div><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></div></oembed></div><p>after</p>'),
        );
        self::assertSame(
            $iframe . '<p>inner</p>',
            VideoEmbeds::render('<oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"><p>inner</p></oembed>'),
        );
    }

    public function testOnlyAUrlAttributeCountsNotADataUrl(): void
    {
        self::assertSame('<p>x</p>', VideoEmbeds::render('<oembed data-url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed><p>x</p>'));
    }

    public function testLeavesHtmlWithoutEmbedsUntouched(): void
    {
        $html = '<p>oembed &lt;oembed&gt; <a href="/x">x</a></p>';

        self::assertSame($html, VideoEmbeds::render($html));
        self::assertSame('', VideoEmbeds::render(''));
    }
}
