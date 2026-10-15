<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\IndexedColumns;
use AtarCms\Content\PublicContent;
use AtarCms\I18n\Locales;
use InvalidArgumentException;
use LogicException;

final class ContentQueryTest extends ContentTestCase
{
    private PublicContent $content;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (new IndexedColumns($this->db, $this->collections)->statements() as $sql) {
            $this->db->execute($sql);
        }
        $this->content = new PublicContent($this->db, $this->entries, $this->collections, $this->clock, new Locales('en', ['en', 'ru']));
    }

    public function testOnlyPublicEntriesOfTheCollectionAndLocaleAreVisible(): void
    {
        $this->article('a', '-1 day');
        $this->article('b', '-1 hour', status: 'scheduled');
        $this->article('c', '+1 hour', status: 'scheduled');
        $this->article('d', '-1 day', status: 'draft');
        $this->article('e', '+1 day');
        $this->article('f', '-1 day', locale: 'ru');
        $this->insertEntry(['collection' => 'pages', 'slug' => 'g']);

        self::assertSame(['b', 'a'], self::slugs($this->content->query('articles', 'en')->get()));
        self::assertSame(2, $this->content->query('articles', 'en')->count());
        self::assertSame(['f'], self::slugs($this->content->query('articles', 'ru')->get()));
        self::assertNull($this->content->findBySlug($this->collections->get('articles'), 'en', 'd'));
        self::assertSame('a', $this->content->findBySlug($this->collections->get('articles'), 'en', 'a')?->slug);

        $this->clock->advance('+2 hours');
        self::assertSame(['c', 'b', 'a'], self::slugs($this->content->query('articles', 'en')->get()));
    }

    public function testLatestAndPages(): void
    {
        foreach ([5, 4, 3, 2, 1] as $daysAgo) {
            $this->article('a' . (6 - $daysAgo), sprintf('-%d days', $daysAgo));
        }
        $query = $this->content->query('articles', 'en');

        self::assertSame(['a5', 'a4'], self::slugs($query->latest(2)));
        self::assertSame(['a4', 'a3'], self::slugs($query->get(2, 1)));
        self::assertSame('a5', $query->first()?->slug);

        $first = $query->page(1);
        self::assertSame(['a5', 'a4'], self::slugs($first->entries));
        self::assertSame([1, 2, 5, 3], [$first->number, $first->perPage, $first->total, $first->lastPage()]);
        self::assertTrue($first->hasNext());
        self::assertFalse($first->hasPrevious());

        $last = $query->page(3);
        self::assertSame(['a1'], self::slugs($last->entries));
        self::assertFalse($last->hasNext());
        self::assertTrue($last->hasPrevious());
        self::assertSame([], $query->page(4)->entries);
        self::assertSame(1, $this->content->query('pages', 'en')->page(1)->lastPage());

        $this->expectException(InvalidArgumentException::class);
        $query->get(0);
    }

    public function testFiltersAndSortsOnIndexedFields(): void
    {
        $this->article('r1', '-3 days', ['rating' => 3, 'kind' => 'report', 'event_date' => '2026-10-01']);
        $this->article('r2', '-2 days', ['rating' => 9, 'kind' => 'interview']);
        $this->article('r3', '-1 day', ['rating' => 5.5, 'kind' => 'report', 'event_date' => '2026-10-02']);
        $query = $this->content->query('articles', 'en');

        self::assertSame(['r3', 'r1'], self::slugs($query->where('kind', 'report')->orderBy('rating', 'desc')->get()));
        self::assertSame(['r1'], self::slugs($query->where('event_date', '2026-10-01')->get()));
        self::assertSame(['r2'], self::slugs($query->where('rating', 9)->get()));
        self::assertSame(['r1', 'r2', 'r3'], self::slugs($query->orderBy('title')->orderBy('slug')->get()));
        self::assertSame(['r3', 'r2', 'r1'], self::slugs($query->get()), 'where() and orderBy() return copies');

        foreach ([
            static fn() => $query->where('summary', 'x'),
            static fn() => $query->where('ghost', 'x'),
            static fn() => $query->orderBy('body'),
            static fn() => $query->orderBy('rating', 'sideways'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid query was accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testChildrenFollowTheCollectionOrder(): void
    {
        $about = $this->page('about', position: 2);
        $this->page('contact-us', position: 1);
        $this->page('team', position: 1, parent: $about);
        $this->page('secret', position: 0, parent: $about, status: 'draft');
        $pages = $this->content->query('pages', 'en');

        self::assertSame(['contact-us', 'about'], self::slugs($pages->childrenOf(null)->get()));
        self::assertSame(['team'], self::slugs($pages->childrenOf($this->entries->find($about))->get()));

        $this->expectException(LogicException::class);
        $this->content->query('articles', 'en')->childrenOf(null);
    }

    public function testFiltersByTerm(): void
    {
        $php = $this->insertTerm();
        $go = $this->insertTerm(['slug' => 'go', 'name' => 'Go']);
        $phpRu = $this->insertTerm(['locale' => 'ru']);
        $a = $this->article('a', '-1 day');
        $b = $this->article('b', '-2 days');
        $draft = $this->article('c', '-3 days', status: 'draft');
        $russian = $this->article('d', '-1 day', locale: 'ru');
        $this->terms->assign($a, 'topics', [$php]);
        $this->terms->assign($b, 'topics', [$go, $php]);
        $this->terms->assign($draft, 'topics', [$php]);
        $this->terms->assign($russian, 'topics', [$phpRu]);
        $goTerm = $this->terms->find($go) ?? self::fail('The term is missing');

        $query = $this->content->query('articles', 'en');
        self::assertSame(['a', 'b'], self::slugs($query->withTerm('topics', 'php')->get()));
        self::assertSame(2, $query->withTerm('topics', 'php')->count());
        self::assertSame(['b'], self::slugs($query->withTerm('topics', $goTerm)->get()));
        self::assertSame(['b'], self::slugs($query->withTerm('topics', 'php')->withTerm('topics', 'go')->get()));
        self::assertSame([], $query->withTerm('topics', 'ghost')->get());
        self::assertSame(['d'], self::slugs($this->content->query('articles', 'ru')->withTerm('topics', 'php')->get()));
        self::assertSame(['a', 'b'], self::slugs($query->get()), 'withTerm() returns a new query');

        $this->expectException(InvalidArgumentException::class);
        $this->content->query('pages', 'en')->withTerm('topics', 'php');
    }

    /**
     * withinTerm() also takes the entries of the term's descendants (a top section's articles live in its
     * subsections), never those of its parent or siblings.
     */
    public function testFiltersByATermAndItsDescendants(): void
    {
        $top = $this->insertTerm(['slug' => 'top', 'name' => 'Top']);
        $child = $this->insertTerm(['slug' => 'child', 'name' => 'Child', 'parent_id' => $top]);
        $grandchild = $this->insertTerm(['slug' => 'grandchild', 'name' => 'Grandchild', 'parent_id' => $child]);
        $other = $this->insertTerm(['slug' => 'other', 'name' => 'Other']);
        $a = $this->article('a', '-1 day');
        $b = $this->article('b', '-2 days');
        $c = $this->article('c', '-3 days');
        $d = $this->article('d', '-4 days');
        $draft = $this->article('e', '-1 day', status: 'draft');
        $this->terms->assign($a, 'topics', [$top]);
        $this->terms->assign($b, 'topics', [$grandchild]);
        $this->terms->assign($c, 'topics', [$other]);
        $this->terms->assign($d, 'topics', [$child, $other]);
        $this->terms->assign($draft, 'topics', [$child]);
        $topTerm = $this->terms->find($top) ?? self::fail('The term is missing');
        $childTerm = $this->terms->find($child) ?? self::fail('The term is missing');

        $query = $this->content->query('articles', 'en');
        self::assertSame(['a', 'b', 'd'], self::slugs($query->withinTerm('topics', $topTerm)->get()));
        self::assertSame(['b', 'd'], self::slugs($query->withinTerm('topics', $childTerm)->latest(5)));
        self::assertSame(2, $query->withinTerm('topics', $childTerm)->count());

        $this->expectException(InvalidArgumentException::class);
        $this->content->query('articles', 'ru')->withinTerm('topics', $topTerm);
    }

    /**
     * A Term of a taxonomy the collection does not use is already rejected; a Term of the right taxonomy but
     * the wrong locale must be rejected the same way, instead of silently returning nothing (it can never
     * match: entry_terms links an "en" entry to an "en" term).
     */
    public function testFiltersByTermRejectsATermOfAnotherLocale(): void
    {
        $phpRu = $this->insertTerm(['locale' => 'ru']);
        $phpRuTerm = $this->terms->find($phpRu) ?? self::fail('The term is missing');

        $this->expectException(InvalidArgumentException::class);
        $this->content->query('articles', 'en')->withTerm('topics', $phpRuTerm);
    }

    public function testByIdsKeepsTheGivenOrderAndDropsInvisibleEntries(): void
    {
        $a = $this->article('a', '-1 day');
        $b = $this->article('b', '-1 day');
        $draft = $this->article('c', '-1 day', status: 'draft');
        $russian = $this->article('d', '-1 day', locale: 'ru');

        self::assertSame(['b', 'a'], self::slugs($this->content->query('articles', 'en')->byIds([$b, 999, $a, $draft, $russian, $b])));
        self::assertSame([], $this->content->query('articles', 'en')->byIds([]));
    }

    public function testByIdsResolvesEachIdToItsTranslationInTheQueryLocale(): void
    {
        $group = EntryRepository::newTranslationGroup();
        $hello = $this->article('hello', '-1 day', group: $group);
        $privet = $this->article('privet', '-1 day', locale: 'ru', group: $group);
        $untranslated = $this->article('lonely', '-1 day');
        $draftGroup = EntryRepository::newTranslationGroup();
        $drafted = $this->article('drafted', '-1 day', group: $draftGroup);
        $this->article('chernovik', '-1 day', status: 'draft', locale: 'ru', group: $draftGroup);
        $zdravstvuj = $this->article('zdravstvuj', '-1 day', locale: 'ru');

        self::assertSame(
            ['zdravstvuj', 'privet'],
            self::slugs($this->content->query('articles', 'ru')->byIds([$zdravstvuj, $hello, $untranslated, $drafted, $privet])),
        );
        self::assertSame(['hello'], self::slugs($this->content->query('articles', 'en')->byIds([$privet])));
    }

    public function testNonTranslatableCollectionsAreReadInTheDefaultLocale(): void
    {
        $this->insertEntry(['collection' => 'notes', 'slug' => 'note']);
        $this->insertEntry(['collection' => 'settings', 'slug' => 'settings']);
        $this->article('hello', '-1 day');

        self::assertSame(['note'], self::slugs($this->content->query('notes', 'ru')->get()));
        self::assertSame('en', $this->content->query('notes', 'ru')->locale);
        self::assertSame('settings', $this->content->single($this->collections->get('settings'), 'ru')?->slug);
        self::assertSame([], $this->content->query('articles', 'ru')->get());
        self::assertNull($this->content->single($this->collections->get('contact'), 'en'));
    }

    public function testSinglesAndAllLocales(): void
    {
        $contact = $this->collections->get('contact');
        $this->insertEntry(['collection' => 'contact', 'slug' => 'contact']);
        $this->insertEntry(['collection' => 'contact', 'slug' => 'contact', 'locale' => 'ru', 'status' => 'draft']);
        $group = EntryRepository::newTranslationGroup();
        $this->article('hello', '-1 day', group: $group);
        $this->article('privet', '-1 day', locale: 'ru', group: $group);
        $this->article('hidden', '-1 day', status: 'draft');

        self::assertSame('contact', $this->content->single($contact, 'en')?->slug);
        self::assertNull($this->content->single($contact, 'ru'));

        $all = $this->content->all($this->collections->get('articles'));
        self::assertCount(2, $all);
        self::assertSame([$group, $group], array_map(static fn(Entry $e): string => $e->translationGroup, $all));
        self::assertSame(['en', 'ru'], array_map(static fn(Entry $e): string => $e->locale, $all));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function article(string $slug, string $published, array $data = [], string $status = 'published', string $locale = 'en', ?string $group = null): int
    {
        return $this->insertEntry([
            'slug' => $slug,
            'title' => strtoupper($slug),
            'status' => $status,
            'locale' => $locale,
            'published_at' => $this->clock->now()->modify($published),
            'translation_group' => $group ?? EntryRepository::newTranslationGroup(),
            'data' => $data,
        ]);
    }

    private function page(string $slug, int $position, ?int $parent = null, string $status = 'published'): int
    {
        return $this->insertEntry([
            'collection' => 'pages',
            'slug' => $slug,
            'position' => $position,
            'parent_id' => $parent,
            'status' => $status,
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
