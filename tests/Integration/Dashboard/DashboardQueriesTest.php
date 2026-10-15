<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Dashboard;

use AtarCms\Auth\UserRepository;
use AtarCms\Content\EntryListing;
use AtarCms\Content\EntryRepository;
use AtarCms\Database\TimeSlot;
use AtarCms\Messages\MessageRepository;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The aggregate reads of the dashboard: each in the repository of its table, counted in SQL.
 */
final class DashboardQueriesTest extends ContentTestCase
{
    private EntryListing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listing = new EntryListing($this->db, $this->entries);
    }

    public function testStatusCountsPerCollectionTellPublicFromScheduled(): void
    {
        $now = $this->clock->now();
        $this->insertEntry(['slug' => 'live']);
        $this->insertEntry(['slug' => 'live-ru', 'locale' => 'ru']);
        $this->insertEntry(['slug' => 'due', 'status' => 'scheduled', 'published_at' => $now->modify('-1 minute')]);
        $this->insertEntry(['slug' => 'soon', 'status' => 'scheduled', 'published_at' => $now->modify('+1 day')]);
        $this->insertEntry(['slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $this->insertEntry(['collection' => 'notes', 'slug' => 'note', 'status' => 'draft', 'published_at' => null]);
        $this->insertEntry(['collection' => 'pages', 'slug' => 'hidden']);

        self::assertSame([
            'articles' => ['published' => 3, 'scheduled' => 1, 'draft' => 1],
            'notes' => ['published' => 0, 'scheduled' => 0, 'draft' => 1],
        ], $this->listing->statusCounts(['articles', 'notes'], $now), 'every language; a scheduled entry whose time has come is public');
        self::assertSame([], $this->listing->statusCounts([], $now));
    }

    public function testThePublishedEntryWithADateAheadIsCountedAndListedAsWaiting(): void
    {
        $now = $this->clock->now();
        $this->insertEntry(['slug' => 'ahead', 'status' => 'published', 'published_at' => $now->modify('+2 hours')]);
        $this->insertEntry(['slug' => 'soon', 'status' => 'scheduled', 'published_at' => $now->modify('+1 hour')]);
        $this->insertEntry(['slug' => 'live']);

        $listed = array_map(static fn($entry): string => $entry->slug, $this->listing->scheduled(['articles'], $now, 10));

        self::assertSame(['soon', 'ahead'], $listed, 'every entry waiting for its time, soonest first');
        self::assertSame(count($listed), $this->listing->statusCounts(['articles'], $now)['articles']['scheduled'], 'the count and the list agree');
    }

    public function testPublicationsAreCountedPerSlotFromTheWindowStart(): void
    {
        $now = $this->clock->now();
        $since = $now->modify('-2 days');
        $this->insertEntry(['slug' => 'a', 'published_at' => $now->modify('-1 hour')]);
        $this->insertEntry(['slug' => 'b', 'published_at' => $now->modify('-1 hour')]);
        $this->insertEntry(['slug' => 'c', 'published_at' => $now->modify('-1 day')]);
        $this->insertEntry(['slug' => 'old', 'published_at' => $now->modify('-3 days')]);
        $this->insertEntry(['slug' => 'soon', 'status' => 'scheduled', 'published_at' => $now->modify('+1 hour')]);
        $this->insertEntry(['collection' => 'notes', 'slug' => 'n', 'published_at' => $now->modify('-1 hour')]);

        self::assertSame([
            TimeSlot::of($now->modify('-1 day')) => 1,
            TimeSlot::of($now->modify('-1 hour')) => 2,
        ], $this->listing->publishedSlots(['articles'], $since, $now));
        self::assertSame([], $this->listing->publishedSlots([], $since, $now));
    }

    public function testTranslationCoverageCountsGroupsPerLanguage(): void
    {
        $one = EntryRepository::newTranslationGroup();
        $two = EntryRepository::newTranslationGroup();
        $this->insertEntry(['slug' => 'one', 'translation_group' => $one]);
        $this->insertEntry(['slug' => 'one', 'locale' => 'ru', 'translation_group' => $one]);
        $this->insertEntry(['slug' => 'two', 'translation_group' => $two]);
        $this->insertEntry(['slug' => 'three', 'locale' => 'ru']);
        $this->insertEntry(['collection' => 'pages', 'slug' => 'page']);

        self::assertSame(['groups' => 3, 'locales' => ['en' => 2, 'ru' => 2]], $this->listing->translationCoverage(['articles']));
        self::assertSame(['groups' => 0, 'locales' => []], $this->listing->translationCoverage([]));
    }

    public function testEditsAreTheStoredVersionsOfTheViewableCollections(): void
    {
        $revisions = $this->revisions();
        $article = $this->entries->find($this->insertEntry()) ?? self::fail('no entry');
        $note = $this->entries->find($this->insertEntry(['collection' => 'notes', 'slug' => 'n'])) ?? self::fail('no entry');
        $revisions->record($article, null);
        $revisions->record($article, null);
        $revisions->record($note, null);
        $this->clock->advance('+20 hours');
        $revisions->record($article, null);

        self::assertSame([
            TimeSlot::of(self::utc('2026-09-28 12:00:00')) => 2,
            TimeSlot::of(self::utc('2026-09-29 08:00:00')) => 1,
        ], $revisions->editSlots(['articles'], self::utc('2026-09-28 00:00:00')));
        self::assertSame([TimeSlot::of(self::utc('2026-09-29 08:00:00')) => 1], $revisions->editSlots(['articles'], self::utc('2026-09-29 00:00:00')));
        self::assertSame([], $revisions->editSlots([], self::utc('2026-09-28 00:00:00')));
    }

    public function testMessagesReceivedPerSlotAndTheMediaTotals(): void
    {
        $messages = new MessageRepository($this->db);
        foreach (['2026-09-28 11:00:00', '2026-09-28 11:05:00', '2026-09-20 09:00:00'] as $created) {
            $messages->insert(['form' => 'contact', 'locale' => 'en', 'name' => 'Ann', 'email' => 'ann@example.com', 'phone' => null, 'body' => 'Hi', 'page_url' => '/', 'ip_hash' => str_repeat('a', 64), 'created_at' => $created]);
        }

        self::assertSame([TimeSlot::of(self::utc('2026-09-28 11:00:00')) => 2], $messages->receivedSlots(self::utc('2026-09-27 00:00:00')));

        self::assertSame(['count' => 0, 'bytes' => 0], $this->mediaRepository()->totals());
        $this->insertMedia(['size' => 1000]);
        $this->insertMedia(['size' => 2500]);
        self::assertSame(['count' => 2, 'bytes' => 3500], $this->mediaRepository()->totals());
    }

    public function testPopularTagsOfALanguageByTheirViewableEntries(): void
    {
        $php = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'php', 'name' => 'PHP']);
        $css = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'css', 'name' => 'CSS']);
        $art = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'art', 'name' => 'Art']);
        $ru = $this->insertTerm(['taxonomy' => 'tags', 'slug' => 'php', 'name' => 'ПХП', 'locale' => 'ru']);
        $topic = $this->insertTerm(['slug' => 'topic', 'name' => 'Topic']);
        foreach (['a' => [$php, $css, $topic], 'b' => [$php, $art], 'c' => [$php, $css]] as $slug => $terms) {
            $entry = $this->insertEntry(['slug' => $slug]);
            foreach ($terms as $position => $term) {
                $this->db->insert('entry_terms', ['entry_id' => $entry, 'term_id' => $term, 'position' => $position]);
            }
        }
        $story = $this->insertEntry(['collection' => 'stories', 'slug' => 's']);
        $this->db->insert('entry_terms', ['entry_id' => $story, 'term_id' => $art, 'position' => 0]);
        $this->db->insert('entry_terms', ['entry_id' => $this->insertEntry(['slug' => 'r', 'locale' => 'ru']), 'term_id' => $ru, 'position' => 0]);

        self::assertSame(
            [['name' => 'PHP', 'count' => 3], ['name' => 'CSS', 'count' => 2], ['name' => 'Art', 'count' => 1]],
            $this->terms->popular(['tags'], 'en', ['articles'], 8),
            'by count, then by name; only the viewable collections and the language',
        );
        self::assertSame([['name' => 'PHP', 'count' => 3]], $this->terms->popular(['tags'], 'en', ['articles'], 1));
        self::assertSame(['PHP', 'Art', 'CSS'], array_column($this->terms->popular(['tags'], 'en', ['articles', 'stories'], 8), 'name'), 'a tie goes by name');
        self::assertSame([], $this->terms->popular([], 'en', ['articles'], 8));
        self::assertSame([], $this->terms->popular(['tags'], 'en', [], 8));
    }

    public function testUsersByTheirIds(): void
    {
        $users = new UserRepository($this->db, $this->clock);
        $ann = $users->create('ann@example.com', 'admin', 'Ann');
        $bob = $users->create('bob@example.com', 'editor');

        $found = $users->byIds([$bob->id, $ann->id, 999]);
        self::assertSame([$ann->id, $bob->id], array_keys($found));
        self::assertSame('Ann', $found[$ann->id]->name);
        self::assertSame([], $users->byIds([]));
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
