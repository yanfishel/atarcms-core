<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Media\Media;
use AtarCms\Media\MediaQuery;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use PDOException;

final class MediaRepositoryTest extends ContentTestCase
{
    public function testInsertsAndFindsMedia(): void
    {
        $id = $this->insertMedia([
            'path' => '2026/09/' . str_repeat('a', 32) . '.pdf',
            'original_name' => 'Отчёт <b>.pdf',
            'mime' => 'application/pdf',
            'size' => 99,
            'width' => null,
            'height' => null,
            'alt' => ['ru' => 'Отчёт', 'en' => ''],
            'uploaded_by' => $this->user()->id,
        ]);

        $media = $this->mediaRepository()->find($id) ?? self::fail('not found');
        self::assertSame('2026/09/' . str_repeat('a', 32) . '.pdf', $media->path);
        self::assertSame('Отчёт <b>.pdf', $media->originalName);
        self::assertSame(['application/pdf', 99, null, null], [$media->mime, $media->size, $media->width, $media->height]);
        self::assertSame('Отчёт', $media->alt('ru'));
        self::assertNull($media->alt('en'));
        self::assertNull($media->alt('de'));
        self::assertFalse($media->isImage());
        self::assertSame('pdf', $media->extension());
        self::assertNotNull($media->uploadedBy);
        self::assertSame('2026-09-28 12:00:00', $media->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($this->mediaRepository()->find($id + 1));
        self::assertNotNull($this->mediaRepository()->find($id, true), 'a locking read outside a transaction still returns the row');
    }

    public function testLoadsByIdsAndPaths(): void
    {
        $b = $this->insertMedia(['path' => '2026/09/' . str_repeat('b', 32) . '.png', 'mime' => 'image/png']);
        $a = $this->insertMedia(['path' => '2026/09/' . str_repeat('a', 32) . '.jpg']);
        $repository = $this->mediaRepository();

        self::assertSame([$b, $a], array_keys($repository->byIds([$a, 999, $b, $a])));
        self::assertSame([], $repository->byIds([]));
        self::assertSame(
            ['2026/09/' . str_repeat('a', 32) . '.jpg' => $a, '2026/09/' . str_repeat('b', 32) . '.png' => $b],
            $repository->idsByPaths(['2026/09/' . str_repeat('b', 32) . '.png', '2026/09/' . str_repeat('a', 32) . '.jpg', '2026/09/missing.jpg']),
        );
        self::assertSame([], $repository->idsByPaths([]));
    }

    public function testPagesNewestFirstWithSearchAndKind(): void
    {
        $repository = $this->mediaRepository();
        $pdf = $this->insertMedia(['original_name' => 'price 100%.pdf', 'mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $sea = $this->insertMedia(['original_name' => 'catx1.jpg', 'alt' => ['ru' => 'Море', 'en' => 'Sea']]);
        $cat = $this->insertMedia(['original_name' => 'cat_1.jpg']);

        $all = $repository->page(new MediaQuery());
        self::assertSame([$cat, $sea, $pdf], array_map(static fn(Media $m): int => $m->id, $all->items));
        self::assertSame([3, 1, 48, 1], [$all->total, $all->number, $all->perPage, $all->lastPage()]);
        self::assertSame([$cat, $sea], array_map(static fn(Media $m): int => $m->id, $repository->page(new MediaQuery(type: MediaQuery::TYPE_IMAGE))->items));
        self::assertSame([$sea], array_map(static fn(Media $m): int => $m->id, $repository->page(new MediaQuery(search: 'море'))->items));
        self::assertSame([$sea], array_map(static fn(Media $m): int => $m->id, $repository->page(new MediaQuery(search: 'sea'))->items));
        self::assertSame([$pdf], array_map(static fn(Media $m): int => $m->id, $repository->page(new MediaQuery(search: '100%'))->items), '% is literal');
        self::assertSame([$cat], array_map(static fn(Media $m): int => $m->id, $repository->page(new MediaQuery(search: 'cat_1'))->items), '_ is literal (it would also match "catx1")');

        $second = $repository->page(new MediaQuery(page: 2, perPage: 2));
        self::assertSame([$pdf], array_map(static fn(Media $m): int => $m->id, $second->items));
        self::assertTrue($second->hasPrevious());
        self::assertFalse($second->hasNext());
        self::assertSame(2, $second->lastPage());
    }

    public function testSearchMatchesAltValuesNotLocaleCodes(): void
    {
        $repository = $this->mediaRepository();
        $this->insertMedia(['original_name' => 'photo.jpg', 'alt' => ['en' => 'Harbour', 'ru' => 'Порт']]);

        self::assertSame([], $repository->page(new MediaQuery(search: 'en'))->items, 'a locale code is not alt text');
        self::assertSame([], $repository->page(new MediaQuery(search: 'ru'))->items);
        self::assertSame([], $repository->page(new MediaQuery(search: '":"'))->items, 'JSON syntax is not alt text');
        self::assertCount(1, $repository->page(new MediaQuery(search: 'harb'))->items);
    }

    public function testUpdatesAndDeletes(): void
    {
        $id = $this->insertMedia();
        $repository = $this->mediaRepository();

        $repository->update($id, ['alt' => ['en' => 'A cat'], 'updated_at' => $this->clock->now()->modify('+1 minute')]);
        $media = $repository->find($id) ?? self::fail('not found');
        self::assertSame(['en' => 'A cat'], $media->alt);
        self::assertSame('2026-09-28 12:01:00', $media->updatedAt->format('Y-m-d H:i:s'));

        self::assertSame(1, $repository->delete($id));
        self::assertSame(0, $repository->delete($id));
    }

    public function testEntryReferencesAndUsages(): void
    {
        $repository = $this->mediaRepository();
        $photo = $this->insertMedia();
        $other = $this->insertMedia();
        $first = $this->insertEntry(['slug' => 'first']);
        $second = $this->insertEntry(['slug' => 'second']);
        $repository->replaceEntryReferences($second, [['field' => 'cover', 'mediaId' => $photo]]);

        $repository->replaceEntryReferences($first, [['field' => 'cover', 'mediaId' => $photo], ['field' => 'images', 'mediaId' => $photo], ['field' => 'images', 'mediaId' => $other]]);
        $repository->replaceEntryReferences($first, [['field' => 'cover', 'mediaId' => $photo], ['field' => 'body', 'mediaId' => $photo]]);

        self::assertSame(
            [['entryId' => $first, 'field' => 'body'], ['entryId' => $first, 'field' => 'cover'], ['entryId' => $second, 'field' => 'cover']],
            $repository->usages($photo),
        );
        self::assertSame(3, $repository->usageCount($photo));
        self::assertSame([], $repository->usages($other));
        self::assertSame(0, $repository->usageCount($other));

        $repository->replaceEntryReferences($first, []);
        self::assertSame(1, $repository->usageCount($photo));
    }

    public function testForeignKeysKeepUsedMedia(): void
    {
        $repository = $this->mediaRepository();
        $used = $this->insertMedia();
        $entry = $this->insertEntry();
        $repository->replaceEntryReferences($entry, [['field' => 'cover', 'mediaId' => $used]]);

        foreach ([$used] as $id) {
            try {
                $repository->delete($id);
                self::fail('a used medium was deleted');
            } catch (PDOException $e) {
                self::assertSame(1451, $e->errorInfo[1] ?? null);
            }
        }

        $this->entries->delete($entry);
        self::assertSame(0, $repository->usageCount($used), 'references go with their entry');
        self::assertSame(1, $repository->delete($used));

        try {
            $repository->replaceEntryReferences($this->insertEntry(['slug' => 'other']), [['field' => 'cover', 'mediaId' => 999999]]);
            self::fail('a reference to a missing medium was stored');
        } catch (PDOException $e) {
            self::assertSame(1452, $e->errorInfo[1] ?? null);
        }
    }
}
