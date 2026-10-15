<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\RichText;
use InvalidArgumentException;
use PDOException;

final class EntryRepositoryTest extends ContentTestCase
{
    public function testInsertFindAndReadFields(): void
    {
        $id = $this->insertEntry([
            'title' => 'Привет <b>',
            'slug' => 'privet',
            'meta_title' => 'Meta',
            'data' => ['summary' => 'Сводка', 'rating' => 5.0, 'related' => [2, 3]],
        ]);

        $entry = $this->entries->find($id);

        self::assertNotNull($entry);
        self::assertSame('articles', $entry->collection->name);
        self::assertSame('en', $entry->locale);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $entry->translationGroup);
        self::assertSame('privet', $entry->slug);
        self::assertSame('Привет <b>', $entry->title);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertSame('2026-09-28 11:00:00', $entry->publishedAt?->format('Y-m-d H:i:s'));
        self::assertSame(['summary' => 'Сводка', 'rating' => 5.0, 'related' => [2, 3]], $entry->data);
        self::assertSame('Meta', $entry->seo->metaTitle);
        self::assertNull($this->db->selectOne("SHOW COLUMNS FROM entries LIKE 'canonical\\_url'"), 'entries have no canonical address');
        self::assertSame('Сводка', $entry->field('summary'));
        self::assertSame(5.0, $entry->field('rating'));
        self::assertSame([2, 3], $entry->field('related'));
        self::assertNull($entry->field('video'));
        self::assertFalse($entry->field('featured'));
        $body = $entry->field('body');
        self::assertInstanceOf(RichText::class, $body);
        self::assertTrue($body->isEmpty());
        self::assertNull($this->entries->find(999));
    }

    public function testTheShareImageIsSetByCompareAndSetWithoutTouchingTheVersion(): void
    {
        $id = $this->insertEntry();
        $before = $this->entries->find($id);
        self::assertNotNull($before);
        self::assertNull($before->shareImage);
        self::assertNull($this->db->selectOne("SHOW COLUMNS FROM entries LIKE 'og\\_image\\_id'"), 'the manual SEO image is gone');

        self::assertTrue($this->entries->setShareImage($id, $id . '-0123456789abcdef.jpg', null));
        self::assertFalse($this->entries->setShareImage($id, $id . '-fedcba9876543210.jpg', null), 'a stale expectation loses');
        $after = $this->entries->find($id);
        self::assertSame($id . '-0123456789abcdef.jpg', $after?->shareImage);
        self::assertEquals($before->updatedAt, $after->updatedAt, 'the form version stays');

        self::assertTrue($this->entries->setShareImage($id, null, $id . '-0123456789abcdef.jpg'));
        self::assertNull($this->entries->find($id)?->shareImage);
    }

    public function testListsEveryRecordedShareImageName(): void
    {
        $first = $this->insertEntry(['slug' => 'a']);
        $this->insertEntry(['slug' => 'b']);
        $removed = $this->insertEntry(['collection' => 'removed', 'slug' => 'c']);
        self::assertSame([], $this->entries->shareImageNames());

        $this->entries->setShareImage($first, $first . '-0123456789abcdef.jpg', null);
        $this->entries->setShareImage($removed, $removed . '-fedcba9876543210.jpg', null);

        $names = $this->entries->shareImageNames();
        sort($names);
        self::assertSame([$first . '-0123456789abcdef.jpg', $removed . '-fedcba9876543210.jpg'], $names, 'entries of removed collections still own theirs');
    }

    public function testUnknownFieldIsAnError(): void
    {
        $entry = $this->entries->find($this->insertEntry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection "articles" has no field "ghost"');
        $entry?->field('ghost');
    }

    public function testLookups(): void
    {
        $group = EntryRepository::newTranslationGroup();
        $en = $this->insertEntry(['slug' => 'hello', 'translation_group' => $group]);
        $ru = $this->insertEntry(['slug' => 'privet', 'locale' => 'ru', 'translation_group' => $group]);
        $this->insertEntry(['slug' => 'other']);

        self::assertSame($ru, $this->entries->findBySlug('articles', 'ru', 'privet')?->id);
        self::assertNull($this->entries->findBySlug('articles', 'en', 'privet'));
        self::assertNull($this->entries->findBySlug('pages', 'en', 'hello'));
        self::assertSame(['en', 'ru'], array_map(static fn(Entry $e): string => $e->locale, $this->entries->translations($group)));
        self::assertTrue($this->entries->slugExists('articles', 'en', 'hello'));
        self::assertFalse($this->entries->slugExists('articles', 'en', 'hello', $en));
        self::assertFalse($this->entries->slugExists('articles', 'ru', 'hello'));
        self::assertSame($en, $this->entries->first('articles', 'en')?->id);
        self::assertNull($this->entries->first('pages', 'en'));
        self::assertSame([$en], $this->entries->existingIds('articles', [$en, 999]));
        self::assertSame([], $this->entries->existingIds('pages', [$en]));
        self::assertSame([], $this->entries->existingIds('articles', []));
    }

    public function testHierarchyUpdateAndDelete(): void
    {
        $parent = $this->insertEntry(['collection' => 'pages', 'slug' => 'about']);
        $child = $this->insertEntry(['collection' => 'pages', 'slug' => 'team', 'parent_id' => $parent]);

        self::assertTrue($this->entries->hasChildren($parent));
        self::assertFalse($this->entries->hasChildren($child));

        $this->entries->update($child, ['title' => 'Team', 'status' => 'draft', 'data' => ['body' => '<p>x</p>']]);
        $updated = $this->entries->find($child);
        self::assertSame('Team', $updated?->title);
        self::assertSame(EntryStatus::Draft, $updated->status);
        self::assertSame(['body' => '<p>x</p>'], $updated->data);
        self::assertSame($parent, $updated->parentId);

        $this->entries->delete($child);
        self::assertNull($this->entries->find($child));
    }

    public function testDatabaseEnforcesUniqueSlugsAndParents(): void
    {
        $this->insertEntry(['slug' => 'same']);
        try {
            $this->insertEntry(['slug' => 'same']);
            self::fail('A duplicate slug was stored');
        } catch (PDOException $e) {
            self::assertStringContainsString('entries_collection_locale_slug_unique', $e->getMessage());
        }

        $parent = $this->insertEntry(['collection' => 'pages', 'slug' => 'root']);
        $this->insertEntry(['collection' => 'pages', 'slug' => 'leaf', 'parent_id' => $parent]);

        $this->expectException(PDOException::class);
        $this->entries->delete($parent);
    }

    public function testRowsOfUnconfiguredCollectionsAreSkipped(): void
    {
        self::assertNull($this->entries->find($this->insertEntry(['collection' => 'removed'])));
    }

    public function testIsPublicAt(): void
    {
        $now = $this->clock->now();
        $published = $this->entries->find($this->insertEntry(['slug' => 'a']));
        $future = $this->entries->find($this->insertEntry(['slug' => 'b', 'status' => 'scheduled', 'published_at' => $now->modify('+1 day')]));
        $draft = $this->entries->find($this->insertEntry(['slug' => 'c', 'status' => 'draft']));
        $undated = $this->entries->find($this->insertEntry(['slug' => 'd', 'published_at' => null]));

        self::assertTrue($published?->isPublicAt($now));
        self::assertFalse($future?->isPublicAt($now));
        self::assertTrue($future->isPublicAt($now->modify('+2 days')));
        self::assertFalse($draft?->isPublicAt($now));
        self::assertFalse($undated?->isPublicAt($now));
    }
}
