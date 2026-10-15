<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

final class NonTranslatableContentTest extends ContentFunctionalTestCase
{
    public function testThemeReadsOfANonTranslatableSingleUseTheDefaultLocale(): void
    {
        $this->entry('settings', 'Settings', ['fields' => ['tagline' => 'Made with care']]);

        self::assertStringContainsString('<p class="tagline">Made with care</p>', (string) $this->browser->get('/articles')->getBody());
        $russian = $this->browser->get('/ru/articles');
        self::assertSame(200, $russian->getStatusCode());
        self::assertStringContainsString('<p class="tagline">Made with care</p>', (string) $russian->getBody());
    }

    public function testNonTranslatableRoutesOnlyExistInTheDefaultLocale(): void
    {
        $this->entry('events', 'Concert', ['slug' => 'x', 'fields' => ['venue' => 'Hall']]);

        $show = $this->browser->get('/events/x');
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('<div class="generic" data-collection="events">', (string) $show->getBody());
        self::assertStringContainsString('<h1>Concert</h1>', (string) $show->getBody());

        $archive = $this->browser->get('/events');
        self::assertSame(200, $archive->getStatusCode());
        self::assertStringContainsString('<li>Concert</li>', (string) $archive->getBody());
        self::assertStringNotContainsString('hreflang', (string) $archive->getBody());

        self::assertSame(200, $this->browser->get('/events/feed.xml')->getStatusCode());
        foreach (['/ru/events/x', '/ru/events', '/ru/events/feed.xml'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
    }
}
