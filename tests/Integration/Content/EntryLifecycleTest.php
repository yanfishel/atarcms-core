<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Tests\Support\Cast;

final class EntryLifecycleTest extends ContentTestCase
{
    public function testDeleteRemovesTheEntryAndItsRevisions(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Doomed'), null, null);
        $service->update($entry, new EntryInput('Doomed again'), null, null);

        $service->delete($entry, $this->user(), '203.0.113.7');

        self::assertNull($this->entries->find($entry->id));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entry_revisions')));
        self::assertSame(['entry.created', 'entry.updated', 'entry.deleted'], $this->auditActions());
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'entry.deleted'")), true);
        self::assertSame('doomed', is_array($meta) ? $meta['slug'] : null);
    }

    public function testAPageWithSubpagesCannotBeDeleted(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About'), null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);

        self::assertValidation(['entry' => 'validation.entry.has_children'], fn() => $service->delete($about, null, null));

        $service->delete($team, null, null);
        $service->delete($about, null, null);
        self::assertNull($this->entries->find($about->id));
    }

    public function testParentRules(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About'), null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);
        $people = $service->create($pages, 'en', new EntryInput('People', parentId: $team->id), null, null);
        $russian = $service->create($pages, 'ru', new EntryInput('О нас'), null, null);
        $article = $this->insertEntry(['slug' => 'article']);

        self::assertSame($about->id, $team->parentId);
        foreach ([$russian->id, $article, 999] as $parentId) {
            self::assertValidation(
                ['parent_id' => 'validation.parent.invalid'],
                fn() => $service->create($pages, 'en', new EntryInput('Orphan', parentId: $parentId), null, null),
            );
        }
        self::assertValidation(
            ['parent_id' => 'validation.parent.invalid'],
            fn() => $service->update($about, new EntryInput('About', parentId: $people->id), null, null),
        );
        self::assertValidation(
            ['parent_id' => 'validation.parent.invalid'],
            fn() => $service->update($about, new EntryInput('About', parentId: $about->id), null, null),
        );

        $moved = $service->update($people, new EntryInput('People', parentId: $about->id, position: 2), null, null);
        self::assertSame($about->id, $moved->parentId);
        self::assertSame(2, $moved->position);
    }

    public function testCreateTranslationCopiesAsADraftInTheSameGroup(): void
    {
        $service = $this->service();
        $ann = $this->user();
        $source = $service->create($this->collections->get('articles'), 'en', new EntryInput(
            'Hello',
            status: EntryStatus::Published,
            fields: ['summary' => 'Text', 'rating' => 3],
            metaTitle: 'Meta',
        ), null, null);

        $translation = $service->createTranslation($source, 'ru', $ann, null);

        self::assertSame('ru', $translation->locale);
        self::assertSame($source->translationGroup, $translation->translationGroup);
        self::assertSame(EntryStatus::Draft, $translation->status);
        self::assertNull($translation->publishedAt);
        self::assertSame('hello', $translation->slug);
        self::assertSame('Hello', $translation->title);
        self::assertSame(['summary' => 'Text', 'rating' => 3, 'featured' => false], $translation->data);
        self::assertSame('Meta', $translation->seo->metaTitle);
        self::assertSame($ann->id, $translation->authorId);
        self::assertCount(1, $this->revisions()->forEntry($translation->id));
        self::assertSame(['entry.created', 'entry.translated'], $this->auditActions());

        self::assertValidation(['locale' => 'validation.locale.exists'], fn() => $service->createTranslation($source, 'ru', null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->createTranslation($source, 'en', null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->createTranslation($source, 'de', null, null));
        $note = $service->create($this->collections->get('notes'), 'en', new EntryInput('Note', fields: ['text' => 'x']), null, null);
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->createTranslation($note, 'ru', null, null));
    }

    public function testTranslationSlugIsSuffixedWhenTaken(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');
        $service->create($articles, 'ru', new EntryInput('Hello'), null, null);
        $source = $service->create($articles, 'en', new EntryInput('Hello'), null, null);

        self::assertSame('hello-2', $service->createTranslation($source, 'ru', null, null)->slug);
    }

    public function testTranslationMapsTheParent(): void
    {
        $service = $this->service();
        $pages = $this->collections->get('pages');
        $about = $service->create($pages, 'en', new EntryInput('About'), null, null);
        $aboutRu = $service->createTranslation($about, 'ru', null, null);
        $team = $service->create($pages, 'en', new EntryInput('Team', parentId: $about->id), null, null);
        $orphanParent = $service->create($pages, 'en', new EntryInput('Solo'), null, null);
        $child = $service->create($pages, 'en', new EntryInput('Child', parentId: $orphanParent->id), null, null);

        self::assertSame($aboutRu->id, $service->createTranslation($team, 'ru', null, null)->parentId);
        self::assertNull($service->createTranslation($child, 'ru', null, null)->parentId);
    }

    public function testASingleCannotBeTranslatedIntoALocaleThatHasOne(): void
    {
        $service = $this->service();
        $contact = $this->collections->get('contact');
        $english = $service->create($contact, 'en', new EntryInput('Contact', fields: ['phone' => '1']), null, null);
        $russian = $service->create($contact, 'ru', new EntryInput('Контакты', fields: ['phone' => '2']), null, null);
        self::assertSame($english->translationGroup, $russian->translationGroup, 'a single\'s new entry joins its translations');
        self::assertValidation(['locale' => 'validation.locale.exists'], fn() => $service->createTranslation($english, 'ru', null, null));

        // An entry left outside the group (stored before singles were grouped) still blocks a second one.
        $this->db->execute('UPDATE entries SET translation_group = ? WHERE id = ?', ['unlinked-group', $russian->id]);
        self::assertValidation(['locale' => 'validation.single.exists'], fn() => $service->createTranslation($english, 'ru', null, null));
    }

    public function testRestoreBringsBackContentButKeepsPublishingState(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('First', fields: ['summary' => 'one'], metaTitle: 'Old'), null, null);
        $firstRevision = $this->revisions()->forEntry($entry->id)[0]->id;
        $entry = $service->update($entry, new EntryInput('Second', slug: 'second', status: EntryStatus::Published, fields: ['summary' => 'two']), null, null);

        $restored = $service->restore($entry, $firstRevision, $this->user(), null);

        self::assertSame('First', $restored->title);
        self::assertSame('first', $restored->slug);
        self::assertSame(['summary' => 'one', 'featured' => false], $restored->data);
        self::assertSame('Old', $restored->seo->metaTitle);
        self::assertSame(EntryStatus::Published, $restored->status);
        self::assertEquals($entry->publishedAt, $restored->publishedAt);
        self::assertCount(3, $this->revisions()->forEntry($entry->id));
        self::assertSame(['entry.created', 'entry.updated', 'entry.restored'], $this->auditActions());

        $other = $service->create($this->collections->get('articles'), 'en', new EntryInput('Other'), null, null);
        $foreign = $this->revisions()->forEntry($other->id)[0]->id;
        self::assertValidation(['revision' => 'validation.revision.unknown'], fn() => $service->restore($entry, $foreign, null, null));
        self::assertValidation(['revision' => 'validation.revision.unknown'], fn() => $service->restore($entry, 99999, null, null));
    }

    public function testRestoreDropsRelationsToEntriesDeletedSince(): void
    {
        $service = $this->service();
        $kept = $this->insertEntry(['slug' => 'kept']);
        $gone = $this->insertEntry(['slug' => 'gone']);
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('Related', fields: ['related' => [$kept, $gone]]), null, null);
        $firstRevision = $this->revisions()->forEntry($entry->id)[0]->id;
        $entry = $service->update($entry, new EntryInput('Unrelated'), null, null);
        $this->entries->delete($gone);

        $restored = $service->restore($entry, $firstRevision, null, null);

        self::assertSame('Related', $restored->title);
        self::assertSame([$kept], $restored->field('related'));
    }

    public function testRestoreIgnoresTheCanonicalAddressOfOldSnapshots(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('First'), null, null);
        $firstRevision = $this->revisions()->forEntry($entry->id)[0]->id;
        $this->db->execute("UPDATE entry_revisions SET data = JSON_SET(data, '$.canonical_url', '/elsewhere') WHERE id = ?", [$firstRevision]);
        $entry = $service->update($entry, new EntryInput('Second'), null, null);

        $restored = $service->restore($entry, $firstRevision, null, null);

        self::assertSame('First', $restored->title);
        self::assertArrayNotHasKey('canonical_url', $this->revisions()->forEntry($entry->id)[0]->snapshot, 'new snapshots have no canonical address');
    }
}
