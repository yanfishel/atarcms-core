<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;

final class ArchiveTest extends ContentFunctionalTestCase
{
    public function testPaginatesPublicEntriesOfTheLocaleNewestFirst(): void
    {
        foreach ([3, 2, 1] as $daysAgo) {
            $this->entry('articles', 'Day ' . $daysAgo, ['publishedAt' => $this->clock->now()->modify(sprintf('-%d days', $daysAgo))]);
        }
        $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);
        $this->entry('articles', 'Russian', ['slug' => 'russian', 'locale' => 'ru']);

        $first = (string) $this->browser->get('/articles')->getBody();
        self::assertStringContainsString('<ul class="entries"><li><a href="/articles/day-1">Day 1</a></li><li><a href="/articles/day-2">Day 2</a></li></ul>', $first);
        self::assertStringContainsString('<p class="pages">1/2</p>', $first);
        self::assertStringContainsString('<a rel="next" href="/articles?page=2">', $first);
        self::assertStringNotContainsString('Draft', $first);

        $second = (string) $this->browser->get('/articles?page=2')->getBody();
        self::assertStringContainsString('<ul class="entries"><li><a href="/articles/day-3">Day 3</a></li></ul>', $second);
        self::assertStringContainsString('<p class="pages">2/2</p>', $second);
        self::assertStringNotContainsString('rel="next"', $second);

        $russian = (string) $this->browser->get('/ru/articles')->getBody();
        self::assertStringContainsString('<ul class="entries"><li><a href="/ru/articles/russian">Russian</a></li></ul>', $russian);
    }

    public function testInvalidPagesAreNotFound(): void
    {
        self::assertSame(200, $this->browser->get('/articles')->getStatusCode(), 'an empty archive still has page 1');
        $this->entry('articles', 'Only');

        self::assertSame(200, $this->browser->get('/articles?page=1')->getStatusCode());
        foreach (['0', '-1', 'abc', '2', '1.5', '01', '9999999', ''] as $page) {
            self::assertSame(404, $this->browser->get('/articles?page=' . $page)->getStatusCode(), 'page=' . $page);
        }
        self::assertSame(404, $this->browser->get('/articles?page[]=1')->getStatusCode());
    }

    public function testTemplateHelpers(): void
    {
        $this->entry('articles', 'Newest', ['slug' => 'newest']);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '+1 555 <0100>']]);

        $body = (string) $this->browser->get('/articles')->getBody();

        self::assertStringContainsString('<p class="phone">+1 555 &lt;0100&gt;</p>', $body);
        self::assertStringContainsString('<p class="latest">Newest</p>', $body);
        self::assertStringContainsString('<a class="in-ru" href="/ru/articles">', $body);
    }
}
