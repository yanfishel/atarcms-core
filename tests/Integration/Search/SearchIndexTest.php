<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Search;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Database\Row;
use AtarCms\Search\SearchRepository;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\Cast;

/**
 * Every write through EntryService keeps search_index current, in the entry's own transaction.
 */
final class SearchIndexTest extends ContentTestCase
{
    public function testCreatingAnEntryIndexesItsTitleAndTextFields(): void
    {
        $entry = $this->service()->create($this->collections->get('articles'), 'en', new EntryInput(
            title: 'Running news',
            status: EntryStatus::Draft,
            fields: ['summary' => 'Connected readers', 'body' => '<p>First</p><p>Second&nbsp;paragraph</p>', 'kind' => 'report'],
        ), null, null);

        self::assertSame(['articles', 'en', 'run news', 'connect reader first second paragraph'], $this->row($entry->id));
    }

    public function testVideosAndClassesAddNothingToTheIndex(): void
    {
        $entry = $this->service()->create($this->collections->get('articles'), 'en', new EntryInput(
            title: 'Clips',
            fields: ['body' => '<p class="lead">Watching</p><figure class="media"><oembed url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></oembed></figure>'],
        ), null, null);

        self::assertSame(['articles', 'en', 'clip', 'watch'], $this->row($entry->id));
    }

    public function testSlideTextsStayOutOfTheIndex(): void
    {
        $photo = $this->insertMedia();
        $entry = $this->service()->create($this->collections->get('pages'), 'en', new EntryInput(
            title: 'Home',
            fields: ['body' => '<p>Welcome</p>', 'hero' => [['image' => $photo, 'title' => 'Showcase', 'subtitle' => 'Gardening', 'button' => 'Visit']]],
        ), null, null);

        self::assertSame(['pages', 'en', 'home', 'welcom'], $this->row($entry->id), 'a hero is a showcase, not content');
    }

    public function testUpdatingAndRestoringReplaceTheRow(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Old title', fields: ['summary' => 'Old text']), null, null);
        $first = Row::int($this->db->selectOne('SELECT MIN(id) AS id FROM entry_revisions WHERE entry_id = ?', [$entry->id]) ?? [], 'id');

        $entry = $service->update($entry, new EntryInput('New title', slug: $entry->slug, fields: ['summary' => 'New text']), null, null);
        self::assertSame(['articles', 'en', 'new titl', 'new text'], $this->row($entry->id));

        $service->restore($entry, $first, null, null);
        self::assertSame(['articles', 'en', 'old titl', 'old text'], $this->row($entry->id));
    }

    public function testDeletingAnEntryDeletesItsRow(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Gone soon'), null, null);

        $service->delete($entry, null, null);

        self::assertNull($this->row($entry->id));
    }

    public function testATranslationIsIndexedInItsOwnLocale(): void
    {
        $service = $this->service();
        $source = $service->create($this->collections->get('pages'), 'en', new EntryInput('Новости', fields: ['body' => '<p>Новостями</p>']), null, null);

        $translation = $service->createTranslation($source, 'ru', null, null);

        self::assertSame(['pages', 'en', 'новости', 'новостями'], $this->row($source->id));
        self::assertSame(['pages', 'ru', 'новост', 'новост'], $this->row($translation->id));
    }

    public function testCollectionsOutsideSearchAreNotIndexed(): void
    {
        $service = $this->service();
        $event = $service->create($this->collections->get('events'), 'en', new EntryInput('Concert', fields: ['venue' => 'Hall']), null, null);
        $note = $service->create($this->collections->get('notes'), 'en', new EntryInput('Note', fields: ['text' => 'Private']), null, null);

        self::assertNull($this->row($event->id));
        self::assertNull($this->row($note->id));
    }

    public function testSavingAnEntryOfACollectionOutsideSearchRemovesItsStaleRow(): void
    {
        $service = $this->service();
        $event = $service->create($this->collections->get('events'), 'en', new EntryInput('Concert'), null, null);
        // A row written before the collection got ->searchable(false).
        (new SearchRepository($this->db))->replace($event->id, 'events', 'en', 'concert', '');

        $service->update($event, new EntryInput('Concert', slug: $event->slug), null, null);

        self::assertNull($this->row($event->id));
    }

    /**
     * @return list<string>|null collection, locale, title and body of the entry's row
     */
    private function row(int $entryId): ?array
    {
        $row = $this->db->selectOne('SELECT collection, locale, title, body FROM search_index WHERE entry_id = ?', [$entryId]);

        return $row === null ? null : array_map(static fn(mixed $value): string => Cast::string($value), array_values($row));
    }
}
