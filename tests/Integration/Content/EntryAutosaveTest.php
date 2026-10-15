<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Revision;

/**
 * Autosaves go through the normal save path, but keep the revision history readable: between two manual saves an
 * author's autosaves share one revision (flagged "autosave"), which is audited once.
 */
final class EntryAutosaveTest extends ContentTestCase
{
    public function testAnAuthorsAutosavesShareOneRevisionUntilAManualSave(): void
    {
        $articles = $this->collections->get('articles');
        $ann = $this->user();
        $entry = $this->service()->create($articles, 'en', new EntryInput('One'), $ann, null);

        $this->clock->advance('+1 minute');
        $entry = $this->service()->update($entry, new EntryInput('Two'), $ann, null, autosave: true);
        $this->clock->advance('+1 minute');
        $entry = $this->service()->update($entry, new EntryInput('Three'), $ann, null, autosave: true);

        $revisions = $this->revisions()->forEntry($entry->id);
        self::assertSame(['Three', 'One'], array_map(static fn(Revision $r): string => $r->title(), $revisions));
        self::assertSame([true, false], array_map(static fn(Revision $r): bool => $r->isAutosave(), $revisions));
        self::assertEquals($this->clock->now(), $revisions[0]->createdAt, 'the overwritten revision takes the time of the last autosave');
        self::assertSame('Three', $this->entries->find($entry->id)?->title, 'every autosave saves the entry');
        self::assertSame(['entry.created', 'entry.autosaved'], $this->auditActions(), 'audited once per autosave revision');

        $entry = $this->service()->update($entry, new EntryInput('Four'), $ann, null);
        $entry = $this->service()->update($entry, new EntryInput('Five'), $ann, null, autosave: true);
        self::assertSame(['Five', 'Four', 'Three', 'One'], array_map(static fn(Revision $r): string => $r->title(), $this->revisions()->forEntry($entry->id)));
        self::assertSame(['entry.created', 'entry.autosaved', 'entry.updated', 'entry.autosaved'], $this->auditActions());
    }

    /**
     * The form of a new entry keeps an empty slug input after its first autosave: a draft's empty slug follows its
     * title (its own slug never counts as taken), while a published entry's address stays.
     */
    public function testADraftWithoutASlugFollowsItsTitle(): void
    {
        $articles = $this->collections->get('articles');
        $ann = $this->user();
        $this->insertEntry(['slug' => 'final-title']);
        $draft = $this->service()->create($articles, 'en', new EntryInput('Fir'), $ann, null, autosave: true);
        self::assertSame('fir', $draft->slug);

        $draft = $this->service()->update($draft, new EntryInput('First words'), $ann, null, autosave: true);
        self::assertSame('first-words', $draft->slug);
        $draft = $this->service()->update($draft, new EntryInput('First words'), $ann, null, autosave: true);
        self::assertSame('first-words', $draft->slug, 'its own slug is not taken');
        $draft = $this->service()->update($draft, new EntryInput('Final title'), $ann, null, autosave: true);
        self::assertSame('final-title-2', $draft->slug, 'a slug of another entry is skipped');
        $draft = $this->service()->update($draft, new EntryInput('Kept', slug: 'custom'), $ann, null);
        self::assertSame('custom', $draft->slug, 'a typed slug is kept');

        $published = $this->service()->update($draft, new EntryInput('Live', status: EntryStatus::Published), $ann, null);
        self::assertSame('live', $published->slug, 'the save that publishes a draft with an empty slug still follows its title');
        $renamed = $this->service()->update($published, new EntryInput('Renamed', status: EntryStatus::Published), $ann, null);
        self::assertSame('live', $renamed->slug, 'a published entry keeps its address');
    }

    public function testAnotherAuthorsAutosaveStartsItsOwnRevision(): void
    {
        $articles = $this->collections->get('articles');
        $ann = $this->user();
        $bob = $this->user('bob@example.com');
        $entry = $this->service()->create($articles, 'en', new EntryInput('One'), $ann, null);
        $entry = $this->service()->update($entry, new EntryInput('Ann'), $ann, null, autosave: true);
        $entry = $this->service()->update($entry, new EntryInput('Bob'), $bob, null, autosave: true);

        self::assertSame(['Bob', 'Ann', 'One'], array_map(static fn(Revision $r): string => $r->title(), $this->revisions()->forEntry($entry->id)));
    }

    public function testAnEntryCreatedByAnAutosaveStartsWithAnAutosaveRevision(): void
    {
        $articles = $this->collections->get('articles');
        $ann = $this->user();
        $entry = $this->service()->create($articles, 'en', new EntryInput('First'), $ann, null, autosave: true);
        $entry = $this->service()->update($entry, new EntryInput('Second'), $ann, null, autosave: true);

        $revisions = $this->revisions()->forEntry($entry->id);
        self::assertSame(['Second'], array_map(static fn(Revision $r): string => $r->title(), $revisions));
        self::assertTrue($revisions[0]->isAutosave());
        self::assertSame(['entry.created'], $this->auditActions());
    }
}
