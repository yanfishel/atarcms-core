<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\PublicContent;
use AtarCms\Database\Row;
use AtarCms\I18n\Locales;
use AtarCms\Search\SearchQuery;
use AtarCms\Search\SearchRepository;
use AtarCms\Search\TextNormalizer;

/**
 * The publishing rule (published or scheduled, published_at <= now) is written once as SQL (Entry::publicSql()) and
 * once in PHP (Entry::isPublicAt()); every public read uses it.
 */
final class PublishingRuleTest extends ContentTestCase
{
    private const array PUBLIC = ['published-now', 'published-past', 'scheduled-past'];

    public function testTheSqlPredicateAndIsPublicAtAgree(): void
    {
        $ids = $this->seed();
        $now = $this->clock->now();

        [$sql, $bindings] = Entry::publicSql($now);
        $rows = $this->db->select('SELECT slug FROM entries WHERE ' . $sql . ' ORDER BY slug', $bindings);

        self::assertSame(self::PUBLIC, array_map(static fn(array $row): string => Row::string($row, 'slug'), $rows));
        foreach ($this->entries->byIds($ids) as $entry) {
            self::assertSame(in_array($entry->slug, self::PUBLIC, true), $entry->isPublicAt($now), $entry->slug);
        }
    }

    public function testEveryPublicReadAppliesTheRule(): void
    {
        $ids = $this->seed();
        $term = $this->terms->find($this->insertTerm()) ?? self::fail('no term');
        foreach ($ids as $id) {
            $this->db->insert('entry_terms', ['entry_id' => $id, 'term_id' => $term->id, 'position' => 0]);
        }
        $content = new PublicContent($this->db, $this->entries, $this->collections, $this->clock, new Locales('en', ['en', 'ru']));
        $articles = $this->collections->get('articles');

        self::assertSame(self::PUBLIC, self::slugs($content->all($articles)));
        self::assertSame(3, $content->publicCount($articles));
        self::assertSame(3, $content->publicGroupCount($articles));
        self::assertSame(self::PUBLIC, self::slugs($content->sitemapChunk($articles, 0, 10)));
        self::assertSame(self::PUBLIC, self::slugs($content->query($articles, 'en')->get()));
        self::assertSame(3, $content->query($articles, 'en')->count());
        self::assertSame(self::PUBLIC, self::slugs($content->byTerm($term, 1, 10)->entries));
        self::assertSame(3, $content->byTerm($term, 1, 10)->total);
        foreach (['published-now', 'hidden-draft', 'hidden-scheduled', 'hidden-no-date'] as $slug) {
            self::assertSame(in_array($slug, self::PUBLIC, true), $content->findBySlug($articles, 'en', $slug) !== null, $slug);
        }

        // The rows were inserted directly; index them as EntryService would (every entry is titled "Hello").
        foreach ($this->entries->byIds($ids) as $entry) {
            $this->searchIndexer()->index($entry);
        }
        $hits = new SearchRepository($this->db)->search(SearchQuery::parse('hello', 'en', new TextNormalizer()), 'en', ['articles'], $this->clock->now(), 1, 10);
        self::assertSame(self::PUBLIC, self::slugs(array_values($this->entries->byIds($hits->ids))));
        self::assertSame(3, $hits->total);
    }

    /**
     * One article per case of the rule; slugs starting with "hidden-" are not public at the frozen clock time.
     *
     * @return list<int>
     */
    private function seed(): array
    {
        $now = $this->clock->now();

        return [
            $this->insertEntry(['slug' => 'published-past', 'published_at' => $now->modify('-1 day')]),
            $this->insertEntry(['slug' => 'published-now', 'published_at' => $now]),
            $this->insertEntry(['slug' => 'scheduled-past', 'status' => 'scheduled', 'published_at' => $now->modify('-1 minute')]),
            $this->insertEntry(['slug' => 'hidden-scheduled', 'status' => 'scheduled', 'published_at' => $now->modify('+1 second')]),
            $this->insertEntry(['slug' => 'hidden-published-future', 'published_at' => $now->modify('+1 day')]),
            $this->insertEntry(['slug' => 'hidden-draft', 'status' => 'draft', 'published_at' => $now->modify('-1 day')]),
            $this->insertEntry(['slug' => 'hidden-no-date', 'published_at' => null]),
        ];
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<string> sorted
     */
    private static function slugs(array $entries): array
    {
        $slugs = array_map(static fn(Entry $entry): string => $entry->slug, $entries);
        sort($slugs);

        return $slugs;
    }
}
