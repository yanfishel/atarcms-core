<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

final class RichTextVideoTest extends ContentFunctionalTestCase
{
    public function testAPublicPageShowsTheStoredVideosAsPlayersAndAllowsTheirFrames(): void
    {
        $this->entry('articles', 'Videos', ['slug' => 'videos', 'fields' => ['body' => '<p>Watch</p>'
            . '<figure class="media"><oembed url="https://youtu.be/dQw4w9WgXcQ"></oembed></figure>'
            . '<figure class="media"><oembed url="https://vimeo.com/channels/staffpicks/76979871"></oembed></figure>']]);

        $response = $this->browser->get('/articles/videos');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<figure class="media"><iframe class="rich-text-video" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="YouTube" loading="lazy"', $body);
        self::assertStringContainsString('<figure class="media"><iframe class="rich-text-video" src="https://player.vimeo.com/video/76979871" title="Vimeo" loading="lazy"', $body);
        self::assertStringNotContainsString('<oembed', $body);
        self::assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com;", $response->getHeaderLine('Content-Security-Policy'));
    }
}
