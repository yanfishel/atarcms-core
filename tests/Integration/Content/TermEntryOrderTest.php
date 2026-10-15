<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Term;
use AtarCms\Content\TermEntryOrder;
use AtarCms\Content\TermInput;
use AtarCms\Tests\Support\Cast;

/**
 * The manual order of the articles inside a section: every entry of the routed collections that carry the term, in
 * its language and any status, ordered by entry_position, then newest first.
 */
final class TermEntryOrderTest extends ContentTestCase
{
    public function testListsTheSectionsEntriesOfEveryStatusInTheirOrder(): void
    {
        $guides = $this->section('Guides');
        $notes = $this->section('Notes');
        $old = $this->story('Old', $guides, '-3 days');
        $new = $this->story('New', $guides, '-1 day');
        $draft = $this->story('Draft', $guides, null, EntryStatus::Draft);
        $later = $this->story('Later', $guides, '+1 day', EntryStatus::Scheduled);
        $this->story('Elsewhere', $notes, '-1 hour');
        $ru = $this->section('Гайды', 'ru');
        $this->story('Русская', $ru, '-1 hour', locale: 'ru');

        $entries = $this->order()->entries($guides);
        self::assertSame(['Later', 'New', 'Old', 'Draft'], self::titles($entries), 'unsorted: newest first, drafts last');
        self::assertSame(['Русская'], self::titles($this->order()->entries($ru)));

        $this->order()->reorder($guides, [$old->id, $draft->id, $new->id, $later->id], TermEntryOrder::version($entries), null, null);

        self::assertSame(['Old', 'Draft', 'New', 'Later'], self::titles($this->order()->entries($guides)));
        $fresh = $this->story('Fresh', $guides, '-1 hour');
        self::assertSame(['Fresh', 'Old', 'Draft', 'New', 'Later'], self::titles($this->order()->entries($guides)), 'a new article comes first');
        $this->service()->update($fresh, new EntryInput('Fresh again', status: EntryStatus::Published), null, null);
        self::assertSame(['Fresh again', 'Old', 'Draft', 'New', 'Later'], self::titles($this->order()->entries($guides)));
        $this->service()->update($new, new EntryInput('New again', status: EntryStatus::Published, terms: ['sections' => [$guides->id]]), null, null);
        self::assertSame(['Fresh again', 'Old', 'Draft', 'New again', 'Later'], self::titles($this->order()->entries($guides)), 'a save keeps the place');
        $this->service()->update($later, new EntryInput('Later', status: EntryStatus::Scheduled, publishedAt: $later->publishedAt, terms: ['sections' => [$notes->id]]), null, null);
        $this->service()->update($later, new EntryInput('Later', status: EntryStatus::Scheduled, publishedAt: $later->publishedAt, terms: ['sections' => [$guides->id]]), null, null);
        self::assertSame(['Later', 'Fresh again', 'Old', 'Draft', 'New again'], self::titles($this->order()->entries($guides)), 'an article moved in comes first');
    }

    public function testSavesPositionsWithOneAuditRowAndNoRevisions(): void
    {
        $guides = $this->section('Guides');
        $one = $this->story('One', $guides, '-2 days');
        $two = $this->story('Two', $guides, '-1 day');
        $revisions = Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_revisions'));
        $updatedAt = Cast::string($this->db->scalar('SELECT updated_at FROM entries WHERE id = ?', [$one->id]));
        $this->clock->advance('+1 hour');

        $this->order()->reorder($guides, [$one->id, $two->id], TermEntryOrder::version($this->order()->entries($guides)), $this->user(), '127.0.0.1');

        self::assertSame([[$one->id, 1], [$two->id, 2]], array_map(
            static fn(array $row): array => [Cast::int($row['entry_id']), Cast::int($row['entry_position'])],
            $this->db->select('SELECT entry_id, entry_position FROM entry_terms WHERE term_id = ? ORDER BY entry_position', [$guides->id]),
        ));
        self::assertSame($revisions, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_revisions')));
        self::assertSame($updatedAt, Cast::string($this->db->scalar('SELECT updated_at FROM entries WHERE id = ?', [$one->id])), 'the entries are not edited');
        self::assertSame('term.entries_reordered', $this->db->scalar('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
        $row = $this->db->selectOne('SELECT subject_type, subject_id, meta, ip FROM audit_log ORDER BY id DESC LIMIT 1') ?? [];
        self::assertSame(['term', Cast::string($guides->id), '127.0.0.1'], [$row['subject_type'] ?? null, Cast::string($row['subject_id'] ?? ''), $row['ip'] ?? null]);
        self::assertSame(['taxonomy' => 'sections', 'locale' => 'en', 'name' => 'Guides', 'entries' => 2], json_decode(Cast::string($row['meta'] ?? ''), true));
    }

    public function testRefusesAStaleOrderAndAnotherSetOfEntries(): void
    {
        $guides = $this->section('Guides');
        $one = $this->story('One', $guides, '-2 days');
        $two = $this->story('Two', $guides, '-1 day');
        $elsewhere = $this->story('Elsewhere', $this->section('Notes'), '-1 day');
        $version = TermEntryOrder::version($this->order()->entries($guides));

        foreach ([[$one->id], [$one->id, $two->id, $elsewhere->id], [$one->id, $one->id], [$one->id, $one->id, $two->id], [$two->id, 99999]] as $ids) {
            self::assertValidation(['tree' => 'validation.tree.invalid'], fn() => $this->order()->reorder($guides, $ids, $version, null, null));
        }

        $this->story('Three', $guides, '-1 hour');
        self::assertValidation(['tree' => 'validation.tree.stale'], fn() => $this->order()->reorder($guides, [$one->id, $two->id], $version, null, null));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT MAX(entry_position) FROM entry_terms')));
        self::assertNotContains('term.entries_reordered', $this->auditActions());
    }

    private function order(): TermEntryOrder
    {
        return new TermEntryOrder($this->db, $this->entries, $this->terms, $this->collections, new AuditLog($this->db, $this->clock));
    }

    private function section(string $name, string $locale = 'en'): Term
    {
        return $this->termService()->create($this->collections->taxonomies->get('sections'), $locale, new TermInput($name), null, null);
    }

    private function story(string $title, Term $section, ?string $published, EntryStatus $status = EntryStatus::Published, string $locale = 'en'): Entry
    {
        return $this->service()->create($this->collections->get('stories'), $locale, new EntryInput(
            $title,
            status: $status,
            publishedAt: $published === null ? null : $this->clock->now()->modify($published),
            terms: ['sections' => [$section->id]],
        ), null, null);
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<string>
     */
    private static function titles(array $entries): array
    {
        return array_map(static fn(Entry $entry): string => $entry->title, $entries);
    }
}
