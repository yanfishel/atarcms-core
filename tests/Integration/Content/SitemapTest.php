<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\PublicContent;
use AtarCms\Content\Sitemap;
use AtarCms\Content\SitemapPage;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Tests\Support\QueryCounter;

final class SitemapTest extends ContentTestCase
{
    public function testGroupsPerPageLeaveRoomForEveryLocale(): void
    {
        self::assertSame(5, $this->sitemap(10)->groupsPerPage());
        self::assertSame(5, $this->sitemap(11)->groupsPerPage());
        self::assertSame(25000, $this->sitemap()->groupsPerPage());
    }

    public function testLimitIsValidated(): void
    {
        foreach ([9, 50001] as $limit) {
            try {
                $this->sitemap($limit);
                self::fail('Expected a ConfigException for ' . $limit);
            } catch (ConfigException $e) {
                self::assertStringContainsString('content.sitemap_limit must be between 10 and 50000', $e->getMessage());
            }
        }
        self::assertSame(50000, $this->fromConfig(new Config())->limit());
        self::assertSame(100, $this->fromConfig(new Config(['content' => ['sitemap_limit' => 100]]))->limit());
    }

    public function testPagesPerSource(): void
    {
        $this->articles(12);
        $this->insertTerm(['slug' => 'php']);
        $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $sitemap = $this->sitemap(10);

        self::assertSame(
            ['/sitemap-entries-articles-1.xml', '/sitemap-entries-articles-2.xml', '/sitemap-entries-articles-3.xml', '/sitemap-terms-topics-1.xml'],
            array_map(static fn(SitemapPage $page): string => $page->path(), $sitemap->pages()),
        );
        self::assertSame(12 + 6 + 2, $sitemap->total(), '12 English articles, 6 Russian translations, 2 terms');
        self::assertTrue($sitemap->isIndexed());
        self::assertFalse($this->sitemap(20)->isIndexed());
    }

    public function testPagesKeepTranslationGroupsTogetherAndCoverEverything(): void
    {
        $all = $this->articles(12);
        $sitemap = $this->sitemap(10);

        $seen = [];
        foreach ($sitemap->pages() as $page) {
            $entries = $sitemap->entries($page)['entries'];
            $groups = array_unique(array_map(static fn(Entry $entry): string => $entry->translationGroup, $entries));
            self::assertLessThanOrEqual(5, count($groups));
            foreach ($entries as $entry) {
                $seen[] = $entry->id;
            }
        }
        sort($seen);

        self::assertSame($all, $seen, 'every public entry exactly once, translations on the same page');
    }

    public function testOnlyPublicEntries(): void
    {
        $this->insertEntry(['slug' => 'draft', 'status' => 'draft']);
        $this->insertEntry(['slug' => 'later', 'status' => 'scheduled', 'published_at' => $this->clock->now()->modify('+1 day')]);
        $public = $this->insertEntry(['slug' => 'live']);
        $sitemap = $this->sitemap(10);

        self::assertSame(1, $sitemap->total());
        $page = $sitemap->find(SitemapPage::ENTRIES, 'articles', 1);
        self::assertNotNull($page);
        self::assertSame([$public], array_map(static fn(Entry $entry): int => $entry->id, $sitemap->entries($page)['entries']));
    }

    public function testQueriesPerPageDoNotGrowWithEntries(): void
    {
        $this->articles(7);
        $root = $this->insertEntry(['collection' => 'pages', 'slug' => 'root']);
        $parent = $root;
        foreach (['a', 'b', 'c'] as $slug) {
            $parent = $this->insertEntry(['collection' => 'pages', 'slug' => $slug, 'parent_id' => $parent]);
        }
        $sitemap = $this->sitemap(10);

        $full = QueryCounter::measure($this->db, static fn(): array => $sitemap->entries(new SitemapPage(SitemapPage::ENTRIES, 'articles', 1)));
        $partial = QueryCounter::measure($this->db, static fn(): array => $sitemap->entries(new SitemapPage(SitemapPage::ENTRIES, 'articles', 2)));
        $nested = QueryCounter::measure($this->db, static fn(): array => $sitemap->entries(new SitemapPage(SitemapPage::ENTRIES, 'pages', 1)));

        self::assertSame(1, $full);
        self::assertSame(1, $partial);
        self::assertSame(1, $nested, 'every page and its ancestors are on the page: no ancestor query');
        self::assertCount(4, $sitemap->entries(new SitemapPage(SitemapPage::ENTRIES, 'pages', 1))['known']);
    }

    public function testFind(): void
    {
        $this->articles(6);
        $sitemap = $this->sitemap(10);

        self::assertNotNull($sitemap->find(SitemapPage::ENTRIES, 'articles', 2));
        self::assertNull($sitemap->find(SitemapPage::ENTRIES, 'articles', 3), 'past the last page');
        self::assertNull($sitemap->find(SitemapPage::ENTRIES, 'articles', 0));
        self::assertNull($sitemap->find(SitemapPage::ENTRIES, 'notes', 1), 'a collection without a route');
        self::assertNull($sitemap->find(SitemapPage::ENTRIES, 'unknown', 1));
        self::assertNull($sitemap->find(SitemapPage::TERMS, 'regions', 1), 'a taxonomy without a route');
        self::assertNull($sitemap->find('other', 'articles', 1));
    }

    /**
     * $count English articles in their own translation groups; every second one also has a Russian translation.
     *
     * @return list<int> the ids of all inserted entries, sorted
     */
    private function articles(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $group = EntryRepository::newTranslationGroup();
            $ids[] = $this->insertEntry(['slug' => 'article-' . $i, 'translation_group' => $group]);
            if ($i % 2 === 0) {
                $ids[] = $this->insertEntry(['slug' => 'statya-' . $i, 'locale' => 'ru', 'translation_group' => $group]);
            }
        }
        sort($ids);

        return $ids;
    }

    private function sitemap(int $limit = Sitemap::MAX_URLS): Sitemap
    {
        return new Sitemap($this->collections, $this->content(), $this->terms, $this->entries, new Locales('en', ['en', 'ru']), $limit);
    }

    private function fromConfig(Config $config): Sitemap
    {
        return Sitemap::fromConfig($config, $this->collections, $this->content(), $this->terms, $this->entries, new Locales('en', ['en', 'ru']));
    }

    private function content(): PublicContent
    {
        return new PublicContent($this->db, $this->entries, $this->collections, $this->clock, new Locales('en', ['en', 'ru']));
    }
}
