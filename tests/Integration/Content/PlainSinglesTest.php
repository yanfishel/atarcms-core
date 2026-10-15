<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use DateTimeImmutable;

/**
 * A plain() single ("welcome" in the fixture) is titled by its collection's label and always published, whatever the
 * input says; it keeps no SEO texts or image.
 */
final class PlainSinglesTest extends ContentTestCase
{
    public function testEverySaveTitlesAndPublishesThePage(): void
    {
        $image = $this->insertMedia();
        $welcome = $this->collections->get('welcome');

        $entry = $this->service()->create($welcome, 'en', new EntryInput(
            title: 'Ignored',
            status: EntryStatus::Draft,
            publishedAt: new DateTimeImmutable('2030-01-01 00:00:00'),
            fields: ['intro' => '<p>Hi</p>'],
            metaTitle: 'Ignored too',
            metaDescription: 'Not kept',
        ), null, null);

        self::assertSame('Welcome', $entry->title);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertEquals($this->clock->now(), $entry->publishedAt);
        self::assertNull($entry->seo->metaTitle);
        self::assertNull($entry->seo->metaDescription);
        self::assertSame('<p>Hi</p>', $entry->data['intro'] ?? null);

        $this->clock->advance('+1 day');
        $updated = $this->service()->update($entry, new EntryInput('', status: EntryStatus::Draft, fields: ['intro' => '<p>Hello</p>']), null, null);

        self::assertSame('Welcome', $updated->title);
        self::assertSame(EntryStatus::Published, $updated->status);
        self::assertEquals($entry->publishedAt, $updated->publishedAt, 'the first publication date stays');
    }

    public function testATranslationIsPublishedAndTitledInItsLanguage(): void
    {
        $entry = $this->service()->create($this->collections->get('welcome'), 'en', new EntryInput('', fields: ['intro' => '<p>Hi</p>']), null, null);

        $translation = $this->service()->createTranslation($entry, 'ru', null, null);

        self::assertSame('Добро пожаловать', $translation->title);
        self::assertSame(EntryStatus::Published, $translation->status);
        self::assertEquals($this->clock->now(), $translation->publishedAt);
    }

    public function testAPageStoredAsADraftIsPublishedByItsNextSave(): void
    {
        $id = $this->insertEntry(['collection' => 'welcome', 'slug' => 'welcome', 'title' => 'Old', 'status' => 'draft', 'published_at' => null]);
        $entry = $this->entries->find($id) ?? self::fail('No entry');

        $saved = $this->service()->update($entry, EntryInput::fromEntry($entry), null, null);

        self::assertSame(EntryStatus::Published, $saved->status);
        self::assertSame('Welcome', $saved->title);
    }
}
