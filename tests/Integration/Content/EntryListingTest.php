<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryListing;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;

final class EntryListingTest extends ContentTestCase
{
    private EntryListing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listing = new EntryListing($this->db, $this->entries);
    }

    public function testPagesIncludeEveryStatusAndFilter(): void
    {
        $this->row('alpha', 'Alpha', 'draft', '-3 hours');
        $this->row('beta', 'Beta 100%', 'published', '-2 hours');
        $this->row('gamma', 'Gamma_x', 'scheduled', '-1 hour');
        $this->row('russian', 'Русский', 'published', '-1 hour', locale: 'ru');
        $this->insertEntry(['collection' => 'pages', 'slug' => 'page']);
        $articles = $this->collections->get('articles');

        $all = $this->listing->page($articles, 'en', null, '', '', '', 1);
        self::assertSame(['gamma', 'beta', 'alpha'], self::slugs($all->entries));
        self::assertSame(3, $all->total);
        self::assertSame(['beta'], self::slugs($this->listing->page($articles, 'en', EntryStatus::Published, '', '', '', 1)->entries));
        self::assertSame(['beta'], self::slugs($this->listing->page($articles, 'en', null, '100%', '', '', 1)->entries));
        self::assertSame(['gamma'], self::slugs($this->listing->page($articles, 'en', null, 'a_x', '', '', 1)->entries));
        self::assertSame([], self::slugs($this->listing->page($articles, 'en', null, 'B_ta', '', '', 1)->entries), '"_" is not a wildcard');
        self::assertSame(['alpha'], self::slugs($this->listing->page($articles, 'en', null, '  ALP ', '', '', 1)->entries));
        self::assertSame(['russian'], self::slugs($this->listing->page($articles, 'ru', null, '', '', '', 1)->entries));
    }

    public function testSortingIsWhitelisted(): void
    {
        $this->row('b', 'B', 'draft', '-3 hours');
        $this->row('a', 'A', 'draft', '-1 hour');
        $articles = $this->collections->get('articles');

        self::assertSame(['a', 'b'], self::slugs($this->listing->page($articles, 'en', null, '', 'title', 'asc', 1)->entries));
        self::assertSame(['b', 'a'], self::slugs($this->listing->page($articles, 'en', null, '', 'title', 'desc', 1)->entries));
        self::assertSame(['a', 'b'], self::slugs($this->listing->page($articles, 'en', null, '', 'data; DROP TABLE entries', 'sideways', 1)->entries));

        $this->insertEntry(['collection' => 'pages', 'slug' => 'second', 'position' => 2]);
        $this->insertEntry(['collection' => 'pages', 'slug' => 'first', 'position' => 1]);
        self::assertSame(['first', 'second'], self::slugs($this->listing->page($this->collections->get('pages'), 'en', null, '', '', '', 1)->entries));
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->row('e' . $i, 'E' . $i, 'draft', sprintf('-%d minutes', $i));
        }
        $second = $this->listing->page($this->collections->get('articles'), 'en', null, '', '', '', 2);

        self::assertSame(['e26', 'e27'], self::slugs($second->entries));
        self::assertSame([2, 25, 27, 2], [$second->number, $second->perPage, $second->total, $second->lastPage()]);
    }

    public function testLocalesByGroupAndTitles(): void
    {
        $group = EntryRepository::newTranslationGroup();
        $en = $this->row('hello', 'Hello', 'draft', '-1 hour', group: $group);
        $this->row('privet', 'Привет', 'draft', '-1 hour', locale: 'ru', group: $group);
        $zulu = $this->row('zulu', 'Zulu', 'published', '-1 hour');

        self::assertSame([$group => ['en', 'ru']], $this->listing->localesByGroup([$group]));
        self::assertSame([], $this->listing->localesByGroup([]));
        self::assertSame([$en => 'Hello', $zulu => 'Zulu'], $this->listing->titles('articles', 'en'));
        self::assertSame([$en => 'Hello'], $this->listing->titles('articles', 'en', 1));
    }

    public function testDashboardLists(): void
    {
        $this->row('draft', 'Draft', 'draft', '-1 hour', updated: '-5 minutes');
        $this->row('old-draft', 'Old draft', 'draft', '-1 hour', updated: '-1 day');
        $this->row('soon', 'Soon', 'scheduled', '+2 hours');
        $this->row('sooner', 'Sooner', 'scheduled', '+1 hour');
        $this->row('past', 'Past', 'scheduled', '-1 hour');
        $this->insertEntry(['collection' => 'pages', 'slug' => 'page-draft', 'status' => 'draft']);
        $now = $this->clock->now();

        self::assertSame(['draft', 'old-draft'], self::slugs($this->listing->drafts(['articles'], 10)));
        self::assertSame(['sooner', 'soon'], self::slugs($this->listing->scheduled(['articles'], $now, 10)));
        self::assertSame(['soon'], self::slugs($this->listing->recentlyUpdated(['articles'], 1)), 'row() sets updated_at relative to published_at');
        self::assertCount(3, $this->listing->drafts(['articles', 'pages'], 10));
        self::assertSame([], $this->listing->drafts([], 10));
    }

    private function row(string $slug, string $title, string $status, string $published, string $locale = 'en', ?string $group = null, string $updated = '0 seconds'): int
    {
        $now = $this->clock->now();

        return $this->insertEntry([
            'slug' => $slug,
            'title' => $title,
            'status' => $status,
            'locale' => $locale,
            'published_at' => $now->modify($published),
            'updated_at' => $now->modify($published)->modify($updated),
            'translation_group' => $group ?? EntryRepository::newTranslationGroup(),
        ]);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<string>
     */
    private static function slugs(array $entries): array
    {
        return array_map(static fn(Entry $e): string => $e->slug, $entries);
    }
}
