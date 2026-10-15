<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\VideoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function accepted(): iterable
    {
        $id = 'dQw4w9WgXcQ';
        foreach (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'WWW.YouTube.com'] as $host) {
            yield 'watch on ' . $host => ['https://' . $host . '/watch?v=' . $id, 'youtube', $id];
        }
        yield 'v later in the query' => ['https://www.youtube.com/watch?feature=share&v=' . $id . '&t=10#x', 'youtube', $id];
        yield 'http' => ['http://www.youtube.com/watch?v=' . $id, 'youtube', $id];
        yield 'embed' => ['https://www.youtube-nocookie.com/embed/' . $id . '?start=5', 'youtube', $id];
        yield 'shorts' => ['https://youtube.com/shorts/' . $id, 'youtube', $id];
        yield 'live' => ['https://www.youtube.com/live/' . $id . '?si=abc', 'youtube', $id];
        yield 'youtu.be' => [' https://youtu.be/' . $id . '?t=42 ', 'youtube', $id];
        yield 'vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871'];
        yield 'www.vimeo' => ['https://www.vimeo.com/76979871#t=3s', 'vimeo', '76979871'];
        yield 'vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871'];
        yield 'no scheme' => ['youtube.com/watch?v=' . $id, 'youtube', $id];
        yield 'no scheme with www' => ['www.youtube.com/watch?v=' . $id, 'youtube', $id];
        yield 'no scheme youtu.be' => ['youtu.be/' . $id, 'youtube', $id];
        yield 'www.youtu.be' => ['www.youtu.be/' . $id, 'youtube', $id];
        yield 'no scheme vimeo' => ['vimeo.com/76979871', 'vimeo', '76979871'];
        yield 'www.player.vimeo' => ['https://www.player.vimeo.com/video/76979871', 'vimeo', '76979871'];
        yield 'vimeo player' => ['https://player.vimeo.com/video/76979871?h=abc', 'vimeo', '76979871'];
        // The editor retries a link once without "www." (CKEditor's media embed), so it previews these: one extra
        // "www." in front of a host the editor knows.
        yield 'www.www.youtube.com' => ['www.www.youtube.com/watch?v=' . $id, 'youtube', $id];
        yield 'www.m.youtube.com' => ['https://www.m.youtube.com/watch?v=' . $id, 'youtube', $id];
        yield 'www.www.youtube-nocookie.com' => ['https://www.www.youtube-nocookie.com/embed/' . $id, 'youtube', $id];
        yield 'WWW.www.youtube.com' => ['https://WWW.www.YouTube.com/shorts/' . $id, 'youtube', $id];
        yield 'www.www.vimeo.com' => ['www.www.vimeo.com/76979871', 'vimeo', '76979871'];
    }

    #[DataProvider('accepted')]
    public function testParsesEverySupportedShape(string $url, string $provider, string $id): void
    {
        $video = VideoUrl::parse($url);

        self::assertNotNull($video, $url);
        self::assertSame([$provider, $id], [$video->provider, $video->id]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'other host' => ['https://evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'look-alike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'javascript' => ['javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'protocol-relative' => ['//www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'no scheme look-alike host' => ['youtube.com.evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'no scheme other host' => ['evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'www on another host' => ['https://www.evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'www.www.youtu.be' => ['www.www.youtu.be/dQw4w9WgXcQ'];
        yield 'three www on youtube' => ['https://www.www.www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'www.www.player.vimeo' => ['https://www.www.player.vimeo.com/video/76979871'];
        yield 'www.www on another host' => ['https://www.www.evil.example/watch?v=dQw4w9WgXcQ'];
        yield 'www without a dot' => ['https://wwwyoutube.com/watch?v=dQw4w9WgXcQ'];
        yield 'www.m.m.youtube' => ['https://www.m.m.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'ftp' => ['ftp://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'short id' => ['https://www.youtube.com/watch?v=short'];
        yield 'long id' => ['https://youtu.be/dQw4w9WgXcQX'];
        yield 'bad id character' => ['https://www.youtube.com/embed/dQw4w9WgXc!'];
        yield 'watch without v' => ['https://www.youtube.com/watch?list=abc'];
        yield 'v as an array' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'];
        yield 'channel page' => ['https://www.youtube.com/@channel'];
        yield 'player host for youtube' => ['https://player.vimeo.com/embed/dQw4w9WgXcQ'];
        yield 'unlisted vimeo hash' => ['https://vimeo.com/76979871/abcdef1234'];
        yield 'vimeo id too long' => ['https://vimeo.com/1234567890123'];
        yield 'vimeo letters' => ['https://vimeo.com/abc'];
        yield 'vimeo player without video' => ['https://player.vimeo.com/76979871'];
        yield 'trailing newline in v' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ%0A'];
        yield 'trailing newline in youtu.be path' => ['https://youtu.be/dQw4w9WgXcQ%0A'];
        yield 'newline ending an embed path' => ["https://www.youtube.com/embed/dQw4w9WgXcQ\n#x"];
        yield 'newline ending a vimeo path' => ["https://vimeo.com/76979871\n#x"];
        yield 'empty' => [''];
        yield 'not a url' => ['http:///x'];
    }

    #[DataProvider('refused')]
    public function testRefusesOtherShapes(string $url): void
    {
        self::assertNull(VideoUrl::parse($url));
    }

    public function testCanonicalAndEmbedForms(): void
    {
        $youtube = VideoUrl::parse('https://youtu.be/dQw4w9WgXcQ?t=42');
        $vimeo = VideoUrl::parse('https://player.vimeo.com/video/76979871');

        self::assertNotNull($youtube);
        self::assertNotNull($vimeo);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $youtube->canonical(), 'the start time is dropped');
        self::assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube->embedUrl());
        self::assertSame('https://vimeo.com/76979871', $vimeo->canonical());
        self::assertSame('https://player.vimeo.com/video/76979871', $vimeo->embedUrl());
        self::assertEquals($youtube, VideoUrl::parse($youtube->canonical()), 'the canonical form parses to the same video');
        self::assertEquals($vimeo, VideoUrl::parse($vimeo->canonical()));
    }

    public function testTheFrameSourcesAreTheHostsOfTheEmbedBases(): void
    {
        foreach ([VideoUrl::YOUTUBE_EMBED, VideoUrl::VIMEO_EMBED] as $base) {
            $parts = parse_url($base);
            self::assertContains($parts['scheme'] . '://' . $parts['host'], VideoUrl::FRAME_SOURCES, $base);
        }
        self::assertCount(2, VideoUrl::FRAME_SOURCES);
    }
}
