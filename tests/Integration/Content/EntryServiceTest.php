<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Tests\Support\Cast;
use PDOException;

final class EntryServiceTest extends ContentTestCase
{
    public function testCreateNormalizesInputAndRecordsRevisionAndAudit(): void
    {
        $ann = $this->user();

        $entry = $this->service()->create($this->collections->get('articles'), 'en', new EntryInput(
            title: "  Привет,\n  мир! ",
            fields: [
                'summary' => " Short\r\nsummary ",
                'body' => '<p>Hi<script>alert(1)</script></p>',
                'rating' => '7',
                'featured' => 'on',
                'ghost' => 'ignored',
            ],
        ), $ann, '203.0.113.7');

        self::assertSame('Привет, мир!', $entry->title);
        self::assertSame('privet-mir', $entry->slug);
        self::assertSame(EntryStatus::Draft, $entry->status);
        self::assertNull($entry->publishedAt);
        self::assertSame($ann->id, $entry->authorId);
        self::assertSame(['summary' => "Short\nsummary", 'body' => '<p>Hi</p>', 'rating' => 7, 'featured' => true], $entry->data);
        self::assertSame('2026-09-28 12:00:00', $entry->createdAt->format('Y-m-d H:i:s'));

        $revisions = $this->revisions()->forEntry($entry->id);
        self::assertCount(1, $revisions);
        self::assertSame('Привет, мир!', $revisions[0]->title());
        self::assertSame($ann->id, $revisions[0]->authorId);

        $audit = $this->db->selectOne('SELECT action, user_id, subject_type, subject_id, meta, ip FROM audit_log');
        self::assertSame('entry.created', $audit['action'] ?? null);
        self::assertSame((string) $entry->id, $audit['subject_id'] ?? null);
        self::assertSame('203.0.113.7', $audit['ip'] ?? null);
        self::assertSame(
            ['collection' => 'articles', 'locale' => 'en', 'title' => 'Привет, мир!', 'status' => 'draft'],
            json_decode(Cast::string($audit['meta'] ?? ''), true),
        );

        $controls = $this->service()->create($this->collections->get('articles'), 'en', new EntryInput(
            title: "Bad\x01\x02title\x7F",
            metaTitle: "Meta\x00title",
            metaDescription: "Line\x1B one \x08",
        ), null, null);
        self::assertSame('Bad title', $controls->title);
        self::assertSame('Meta title', $controls->seo->metaTitle);
        self::assertSame('Line one', $controls->seo->metaDescription);
    }

    public function testPublicationDates(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');
        $now = $this->clock->now();

        $published = $service->create($articles, 'en', new EntryInput('A', status: EntryStatus::Published), null, null);
        self::assertSame('2026-09-28 12:00:00', $published->publishedAt?->format('Y-m-d H:i:s'));

        $dated = $service->create($articles, 'en', new EntryInput('B', status: EntryStatus::Published, publishedAt: $now->modify('-3 days')), null, null);
        self::assertSame('2026-09-25 12:00:00', $dated->publishedAt?->format('Y-m-d H:i:s'));

        $scheduled = $service->create($articles, 'en', new EntryInput('C', status: EntryStatus::Scheduled, publishedAt: $now->modify('+1 day')), null, null);
        self::assertSame(EntryStatus::Scheduled, $scheduled->status);

        self::assertValidation(
            ['published_at' => 'validation.published_at.required'],
            fn() => $service->create($articles, 'en', new EntryInput('D', status: EntryStatus::Scheduled), null, null),
        );

        $this->clock->advance('+1 hour');
        $kept = $service->update($dated, new EntryInput('B2', status: EntryStatus::Published), null, null);
        self::assertSame('2026-09-25 12:00:00', $kept->publishedAt?->format('Y-m-d H:i:s'));
    }

    public function testSlugs(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');

        $first = $service->create($articles, 'en', new EntryInput('Hello'), null, null);
        $second = $service->create($articles, 'en', new EntryInput('Hello'), null, null);
        $russian = $service->create($articles, 'ru', new EntryInput('Hello'), null, null);
        $symbols = $service->create($articles, 'en', new EntryInput('!!!'), null, null);

        self::assertSame(['hello', 'hello-2', 'hello', 'entry'], [$first->slug, $second->slug, $russian->slug, $symbols->slug]);
        self::assertValidation(
            ['slug' => 'validation.slug.taken'],
            fn() => $service->create($articles, 'en', new EntryInput('Other', slug: 'hello'), null, null),
        );
        self::assertValidation(
            ['slug' => 'validation.slug.invalid'],
            fn() => $service->create($articles, 'en', new EntryInput('Other', slug: 'Hello World'), null, null),
        );

        // A draft's empty slug follows its title (EntryAutosaveTest); a published entry keeps its address.
        $published = $service->update($first, new EntryInput('Hello', slug: 'hello', status: EntryStatus::Published), null, null);
        $renamed = $service->update($published, new EntryInput('A new title', status: EntryStatus::Published), null, null);
        self::assertSame('hello', $renamed->slug);
        $moved = $service->update($renamed, new EntryInput('A new title', slug: 'new-title'), null, null);
        self::assertSame('new-title', $moved->slug);
        $same = $service->update($moved, new EntryInput('A new title', slug: 'new-title'), null, null);
        self::assertSame('new-title', $same->slug);
    }

    public function testErrorsAreCollectedWithParamsAndNothingIsWritten(): void
    {
        self::assertValidation(
            [
                'title' => 'validation.field.required',
                'summary' => 'validation.field.too_long',
                'rating' => 'validation.field.too_large',
                'kind' => 'validation.field.option',
                'meta_description' => 'validation.field.too_long',
            ],
            fn() => $this->service()->create($this->collections->get('articles'), 'en', new EntryInput(
                title: '  ',
                fields: ['summary' => str_repeat('a', 501), 'rating' => 11, 'kind' => 'essay'],
                metaDescription: str_repeat('a', 501),
            ), null, null),
            ['summary' => ['max' => 500], 'rating' => ['max' => 10], 'meta_description' => ['max' => 500]],
        );

        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
        self::assertSame([], $this->auditActions());
    }

    public function testLocales(): void
    {
        $service = $this->service();

        self::assertValidation(
            ['locale' => 'validation.locale.unsupported'],
            fn() => $service->create($this->collections->get('notes'), 'ru', new EntryInput('Note', fields: ['text' => 'x']), null, null),
        );
        self::assertValidation(
            ['locale' => 'validation.locale.unsupported'],
            fn() => $service->create($this->collections->get('articles'), 'de', new EntryInput('Hallo'), null, null),
        );
        self::assertSame('en', $service->create($this->collections->get('notes'), 'en', new EntryInput('Note', fields: ['text' => 'x']), null, null)->locale);
    }

    public function testSingles(): void
    {
        $service = $this->service();
        $contact = $this->collections->get('contact');

        $entry = $service->create($contact, 'en', new EntryInput('Contact', slug: 'ignored', fields: ['phone' => '+1 555']), null, null);
        self::assertSame('contact', $entry->slug);
        self::assertValidation(
            ['locale' => 'validation.single.exists'],
            fn() => $service->create($contact, 'en', new EntryInput('Again', fields: ['phone' => '1']), null, null),
        );
        self::assertValidation(
            ['phone' => 'validation.field.required'],
            fn() => $service->create($contact, 'ru', new EntryInput('Контакты'), null, null),
        );
        self::assertSame('ru', $service->create($contact, 'ru', new EntryInput('Контакты', fields: ['phone' => '1']), null, null)->locale);
    }

    public function testRelationsMustPointToExistingEntriesOfTheTargetCollection(): void
    {
        $service = $this->service();
        $articles = $this->collections->get('articles');
        $target = $this->insertEntry(['slug' => 'target']);
        $page = $this->insertEntry(['collection' => 'pages', 'slug' => 'page']);

        $entry = $service->create($articles, 'en', new EntryInput('With relation', fields: ['related' => [$target]]), null, null);
        self::assertSame([$target], $entry->field('related'));
        self::assertValidation(
            ['related' => 'validation.field.relation'],
            fn() => $service->create($articles, 'en', new EntryInput('Broken', fields: ['related' => [$target, 999]]), null, null),
        );
        self::assertValidation(
            ['related' => 'validation.field.relation'],
            fn() => $service->create($articles, 'en', new EntryInput('Wrong collection', fields: ['related' => [$page]]), null, null),
        );
    }

    public function testNewRelationIdsMustBeViewableByTheActor(): void
    {
        $service = $this->service(access: $this->entryAccess(['events_editor' => ['label' => 'Events editor', 'permissions' => ['entries.events.*']]]));
        $events = $this->collections->get('events');
        $speaker = $this->insertEntry(['collection' => 'notes', 'slug' => 'speaker']);
        $other = $this->insertEntry(['collection' => 'notes', 'slug' => 'other']);
        $editor = $this->user('eve@example.com', 'events_editor');

        self::assertValidation(
            ['speakers' => 'validation.field.relation'],
            fn() => $service->create($events, 'en', new EntryInput('Talk', fields: ['speakers' => [$speaker]]), $editor, null),
        );

        // An admin attached the note; the events editor keeps it on save but cannot add another one.
        $entry = $service->create($events, 'en', new EntryInput('Talk', fields: ['speakers' => [$speaker]]), $this->user(), null);
        self::assertSame([$speaker], $service->update($entry, EntryInput::fromEntry($entry), $editor, null)->field('speakers'));
        self::assertValidation(
            ['speakers' => 'validation.field.relation'],
            fn() => $service->update($entry, new EntryInput('Talk', fields: ['speakers' => [$speaker, $other]]), $editor, null),
        );
    }

    public function testRestoreByARestrictedActorKeepsRelationIdsTheyCannotView(): void
    {
        $service = $this->service(access: $this->entryAccess(['events_editor' => ['label' => 'Events editor', 'permissions' => ['entries.events.*']]]));
        $speaker = $this->insertEntry(['collection' => 'notes', 'slug' => 'speaker']);
        $other = $this->insertEntry(['collection' => 'notes', 'slug' => 'other']);
        $entry = $service->create($this->collections->get('events'), 'en', new EntryInput('Talk', fields: ['speakers' => [$speaker]]), $this->user(), null);
        $firstRevision = $this->revisions()->forEntry($entry->id)[0]->id;
        $entry = $service->update($entry, new EntryInput('Talk', fields: ['speakers' => [$other]]), $this->user('bob@example.com'), null);

        $restored = $service->restore($entry, $firstRevision, $this->user('eve@example.com', 'events_editor'), null);

        self::assertSame([$speaker], $restored->field('speakers'));
    }

    public function testUpdateDropsRelationsToEntriesDeletedSince(): void
    {
        $service = $this->service();
        $kept = $this->insertEntry(['slug' => 'kept']);
        $gone = $this->insertEntry(['slug' => 'gone']);
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('With relations', fields: ['related' => [$kept, $gone]]), null, null);

        $this->entries->delete($gone);
        $updated = $service->update($entry, EntryInput::fromEntry($entry), null, null);
        self::assertSame([$kept], $updated->field('related'));

        $this->entries->delete($kept);
        $updated = $service->update($updated, EntryInput::fromEntry($updated), null, null);
        self::assertArrayNotHasKey('related', $updated->data);
    }

    public function testParentNeedsAHierarchicalCollection(): void
    {
        $parent = $this->insertEntry(['slug' => 'parent']);

        self::assertValidation(
            ['parent_id' => 'validation.parent.invalid'],
            fn() => $this->service()->create($this->collections->get('articles'), 'en', new EntryInput('Child', parentId: $parent), null, null),
        );
    }

    public function testUpdateWritesRevisionAndAudit(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('First', fields: ['summary' => 'one']), null, null);
        $this->clock->advance('+1 hour');

        $updated = $service->update($entry, new EntryInput('Second', fields: ['summary' => 'two'], metaTitle: ' Meta '), null, null);

        self::assertSame('Second', $updated->title);
        // "featured" is a boolean field: BooleanType::normalize() never returns null, so an unchecked
        // checkbox is always stored as false (see BooleanType's doc comment), not merely omitted.
        self::assertSame(['summary' => 'two', 'featured' => false], $updated->data);
        self::assertSame('Meta', $updated->seo->metaTitle);
        self::assertSame('2026-09-28 13:00:00', $updated->updatedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-28 12:00:00', $updated->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(['Second', 'First'], array_map(static fn($r) => $r->title(), $this->revisions()->forEntry($entry->id)));
        self::assertSame(['entry.created', 'entry.updated'], $this->auditActions());
    }

    public function testFromEntryRoundTripsTheStoredState(): void
    {
        $service = $this->service();
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput(
            'Round trip',
            status: EntryStatus::Published,
            fields: ['body' => '<p>A &amp; B</p>', 'related' => [$this->insertEntry(['slug' => 'rel'])]],
            metaTitle: 'Meta',
        ), null, null);

        $again = $service->update($entry, EntryInput::fromEntry($entry), null, null);

        self::assertSame($entry->data, $again->data);
        self::assertSame($entry->slug, $again->slug);
        self::assertEquals($entry->seo, $again->seo);
        self::assertEquals($entry->publishedAt, $again->publishedAt);
    }

    public function testRevisionRetention(): void
    {
        $service = $this->service(keepRevisions: 3);
        $entry = $service->create($this->collections->get('articles'), 'en', new EntryInput('v1'), null, null);
        foreach (['v2', 'v3', 'v4', 'v5'] as $title) {
            $entry = $service->update($entry, new EntryInput($title), null, null);
        }

        self::assertSame(['v5', 'v4', 'v3'], array_map(static fn($r) => $r->title(), $this->revisions(3)->forEntry($entry->id)));
    }

    public function testDuplicateKeyViolationsBecomeValidationErrors(): void
    {
        $articles = $this->collections->get('articles');
        $contact = $this->collections->get('contact');
        $this->insertEntry(['slug' => 'taken', 'translation_group' => str_repeat('a', 32)]);

        $slug = $this->violation(['slug' => 'taken']);
        self::assertSame(['slug' => 'validation.slug.taken'], EntryService::duplicateError($slug, $articles)?->errors);
        self::assertSame(['locale' => 'validation.single.exists'], EntryService::duplicateError($slug, $contact)?->errors);

        $group = $this->violation(['slug' => 'free', 'translation_group' => str_repeat('a', 32)]);
        self::assertSame(['locale' => 'validation.locale.exists'], EntryService::duplicateError($group, $articles)?->errors);

        self::assertNull(EntryService::duplicateError($this->violation(['slug' => 'orphan', 'parent_id' => 999]), $articles));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function violation(array $overrides): PDOException
    {
        try {
            $this->insertEntry($overrides);
        } catch (PDOException $e) {
            return $e;
        }
        self::fail('The insert succeeded');
    }
}
