<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Search;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Search\SearchPage;
use AtarCms\Search\SearchQuery;
use AtarCms\Search\SearchRepository;
use AtarCms\Search\TextNormalizer;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\QueryCounter;
use InvalidArgumentException;

final class SearchRepositoryTest extends ContentTestCase
{
    public function testWordFormsMatch(): void
    {
        $ru = $this->publish('Новостями дня', locale: 'ru');
        $en = $this->publish('Running club');

        self::assertSame([$ru->id], $this->search('новость', 'ru')->ids);
        self::assertSame([$en->id], $this->search('runs')->ids);
    }

    public function testEveryWordIsRequired(): void
    {
        $both = $this->publish('Alpha beta', at: '-1 hour');
        $one = $this->publish('Alpha gamma', at: '-2 hours');

        self::assertSame([$both->id], $this->search('alpha beta')->ids);
        self::assertSame([$both->id, $one->id], $this->search('alpha')->ids);
        self::assertSame([], $this->search('alpha delta')->ids);
    }

    public function testTitleMatchesRankAboveTextMatches(): void
    {
        $text = $this->publish('Tips', ['summary' => 'Garden care'], at: '-1 hour');
        $title = $this->publish('Garden', at: '-2 days');
        $this->publish('Weather');

        self::assertSame([$title->id, $text->id], $this->search('garden')->ids);
    }

    public function testStopwordsAreIndexed(): void
    {
        $about = $this->publish('About us');
        $this->publish('Contacts');

        self::assertSame([$about->id], $this->search('about')->ids);
    }

    public function testOnlyEntriesOfTheLocaleAreFound(): void
    {
        $en = $this->publish('Moscow news');
        $ru = $this->publish('Moscow новости', locale: 'ru');

        self::assertSame([$en->id], $this->search('moscow', 'en')->ids);
        self::assertSame([$ru->id], $this->search('moscow', 'ru')->ids);
    }

    public function testOnlyTheGivenCollectionsAreSearched(): void
    {
        $this->publish('Harbour');
        $page = $this->service()->create($this->collections->get('pages'), 'en', new EntryInput('Harbour', status: EntryStatus::Published), null, null);

        self::assertSame([$page->id], $this->search('harbour', collections: ['pages'])->ids);
        self::assertSame(0, QueryCounter::measure($this->db, fn(): SearchPage => $this->search('harbour', collections: [])));
    }

    public function testThePublishingRuleIsAppliedWithTheClock(): void
    {
        $published = $this->publish('Launch published', at: '-1 day');
        $this->publish('Launch draft', at: '-1 day', status: EntryStatus::Draft);
        $this->publish('Launch future', at: '+1 day');
        $scheduled = $this->publish('Launch scheduled', at: '+1 hour', status: EntryStatus::Scheduled);

        self::assertSame([$published->id], $this->search('launch')->ids);

        $this->clock->advance('+2 hours');
        self::assertSame([$scheduled->id, $published->id], $this->search('launch')->ids);
    }

    public function testResultsArePagedWithTheirTotal(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->publish('Report ' . $n, at: sprintf('-%d hours', $n))->id;
        }

        $page = $this->search('report', page: 2, perPage: 2);

        self::assertSame([$ids[2], $ids[3]], $page->ids);
        self::assertSame(5, $page->total);
        self::assertSame(3, $page->pages());
        self::assertSame([$ids[4]], $this->search('report', page: 3, perPage: 2)->ids);
    }

    public function testAPagePastTheLastIsEmptyWithTheRealTotalAndANumberBelowOneIsRejected(): void
    {
        $this->publish('Report one', at: '-1 hour');
        $this->publish('Report two', at: '-2 hours');

        $past = $this->search('report', page: 3, perPage: 2);
        self::assertSame([], $past->ids);
        self::assertSame(2, $past->total);
        self::assertSame(3, $past->number);
        self::assertSame(1, $past->pages());

        foreach ([[0, 10], [-1, 10], [1, 0]] as [$number, $perPage]) {
            try {
                $this->search('report', page: $number, perPage: $perPage);
                self::fail(sprintf('Page %d with %d hits per page must be rejected', $number, $perPage));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAQueryWithoutWordsNeverTouchesTheDatabase(): void
    {
        $this->publish('A to Z');

        self::assertSame(0, QueryCounter::measure($this->db, fn(): SearchPage => $this->search('a to z')));
        self::assertEquals(new SearchPage([], 0, 1, 10), $this->search('a to z'));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function publish(string $title, array $fields = [], string $locale = 'en', string $at = '-1 hour', EntryStatus $status = EntryStatus::Published): Entry
    {
        return $this->service()->create($this->collections->get('articles'), $locale, new EntryInput(
            title: $title,
            status: $status,
            publishedAt: $this->clock->now()->modify($at),
            fields: $fields,
        ), null, null);
    }

    /**
     * @param list<string>|null $collections null for every searchable collection
     */
    private function search(string $q, string $locale = 'en', int $page = 1, int $perPage = 10, ?array $collections = null): SearchPage
    {
        return (new SearchRepository($this->db))->search(
            SearchQuery::parse($q, $locale, new TextNormalizer()),
            $locale,
            $collections ?? array_keys($this->collections->searchable()),
            $this->clock->now(),
            $page,
            $perPage,
        );
    }
}
