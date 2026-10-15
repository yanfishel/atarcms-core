<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;

final class FeedTest extends ContentFunctionalTestCase
{
    public function testListsTheNewestPublicEntriesOfTheLocale(): void
    {
        foreach ([3, 2, 1] as $daysAgo) {
            $this->entry('articles', 'Day ' . $daysAgo, [
                'publishedAt' => $this->clock->now()->modify(sprintf('-%d days', $daysAgo)),
                'metaDescription' => sprintf('About day %d & more', $daysAgo),
            ]);
        }
        $this->entry('articles', 'Draft', ['status' => EntryStatus::Draft]);
        $this->entry('articles', 'Russian', ['slug' => 'russian', 'locale' => 'ru']);

        $response = $this->browser->get('/articles/feed.xml');
        $xml = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/rss+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('<title>Articles — Fixture</title>', $xml);
        self::assertStringContainsString('<link>https://localhost/articles</link>', $xml);
        self::assertStringContainsString('<language>en</language>', $xml);
        self::assertStringContainsString('<lastBuildDate>Sun, 27 Sep 2026 12:00:00 +0000</lastBuildDate>', $xml);
        self::assertStringContainsString('<atom:link href="https://localhost/articles/feed.xml" rel="self" type="application/rss+xml"/>', $xml);
        preg_match_all('~<item>\s*<title>([^<]+)</title>~', $xml, $matches);
        self::assertSame(['Day 1', 'Day 2', 'Day 3'], $matches[1]);
        self::assertStringContainsString('<link>https://localhost/articles/day-1</link>', $xml);
        self::assertStringContainsString('<guid isPermaLink="true">https://localhost/articles/day-1</guid>', $xml);
        self::assertStringContainsString('<pubDate>Sun, 27 Sep 2026 12:00:00 +0000</pubDate>', $xml);
        self::assertStringContainsString('<description>About day 1 &amp; more</description>', $xml);
        self::assertStringNotContainsString('Draft', $xml);
        self::assertStringNotContainsString('russian', $xml);
    }

    public function testFeedsArePerLocale(): void
    {
        $this->entry('articles', 'Russian', ['slug' => 'russian', 'locale' => 'ru']);

        $xml = (string) $this->browser->get('/ru/articles/feed.xml')->getBody();

        self::assertStringContainsString('<language>ru</language>', $xml);
        self::assertStringContainsString('<link>https://localhost/ru/articles</link>', $xml);
        self::assertStringContainsString('<link>https://localhost/ru/articles/russian</link>', $xml);
        self::assertStringContainsString('<atom:link href="https://localhost/ru/articles/feed.xml"', $xml);
    }

    public function testEmptyAndMissingFeeds(): void
    {
        $empty = (string) $this->browser->get('/articles/feed.xml')->getBody();
        self::assertStringNotContainsString('<item>', $empty);
        self::assertStringContainsString('<lastBuildDate>Mon, 28 Sep 2026 12:00:00 +0000</lastBuildDate>', $empty);

        self::assertSame(404, $this->browser->get('/pages/feed.xml')->getStatusCode());
    }

    public function testAFeedHoldsTwentyItems(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->entry('articles', 'Item ' . $i, ['publishedAt' => $this->clock->now()->modify(sprintf('-%d minutes', $i))]);
        }

        $xml = (string) $this->browser->get('/articles/feed.xml')->getBody();

        self::assertSame(20, substr_count($xml, '<item>'));
        self::assertStringNotContainsString('Item 21', $xml);
    }
}
