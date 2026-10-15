<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryMedia;
use AtarCms\Content\EntryService;
use AtarCms\Content\Field\Field;
use AtarCms\Content\ValidationErrors;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use PDOException;

final class EntryMediaTest extends ContentTestCase
{
    use TempDirectory;

    public function testStoresMediaIdsAndEveryReference(): void
    {
        $cover = $this->insertMedia();
        $second = $this->insertMedia();
        $pdf = $this->insertMedia(['path' => '2026/09/' . str_repeat('c', 32) . '.pdf', 'mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $inline = $this->insertMedia(['path' => '2026/09/' . str_repeat('d', 32) . '.png', 'mime' => 'image/png']);
        $linked = $this->insertMedia(['path' => '2026/09/' . str_repeat('e', 32) . '.jpg']);
        $body = '<p><img src="/uploads/2026/09/' . str_repeat('d', 32) . '.png" alt=""></p>'
            . '<p><a href="https://localhost/media/medium/2026/09/' . str_repeat('e', 32) . '.jpg.webp?v=abcdef12">Photo</a>'
            . ' <img src="/uploads/2026/09/' . str_repeat('f', 32) . '.jpg" alt=""></p>';

        $entry = $this->service()->create($this->articles(), 'en', new EntryInput(
            title: 'With media',
            fields: ['cover' => (string) $cover, 'images' => $second . ', ' . $cover . ',' . $second, 'attachment' => $pdf, 'body' => $body],
        ), null, null);

        self::assertSame([$cover, [$second, $cover], $pdf], [$entry->data['cover'], $entry->data['images'], $entry->data['attachment']]);
        self::assertSame($cover, $entry->field('cover'));
        self::assertSame([$second, $cover], $entry->field('images'));
        self::assertSame(
            [['attachment', $pdf], ['body', $inline], ['body', $linked], ['cover', $cover], ['images', $cover], ['images', $second]],
            $this->references($entry->id),
            'the unknown rich-text link is ignored',
        );
        self::assertSame([$cover, $second, $pdf], $this->entryMedia()->ids([$entry]), 'fields, not rich text');
    }

    public function testRecordsTheImagesOfTheRichTextEditor(): void
    {
        $path = '2026/09/' . str_repeat('a', 32) . '.png';
        $image = $this->insertMedia(['path' => $path, 'mime' => 'image/png']);
        $inlinePath = '2026/09/' . str_repeat('b', 32) . '.png';
        $inline = $this->insertMedia(['path' => $inlinePath, 'mime' => 'image/png']);

        $entry = $this->service()->create($this->articles(), 'en', new EntryInput('Figure', fields: [
            'body' => '<figure class="image image_resized" style="width:50%;"><a href="/uploads/' . $path . '"><img style="aspect-ratio:4/3;" src="/uploads/' . $path . '" alt="A"></a><figcaption>Caption</figcaption></figure>'
                . '<p>Text <img class="image-style-align-right image_resized" style="width:25%;" src="/uploads/' . $inlinePath . '" alt="B"> text</p>',
        ]), null, null);

        self::assertSame(
            '<figure class="image image_resized" style="width: 50%"><a href="/uploads/' . $path . '"><img style="aspect-ratio: 4/3" src="/uploads/' . $path . '" alt="A" /></a><figcaption>Caption</figcaption></figure>'
            . '<p>Text <img class="image-style-align-right image_resized" style="width: 25%" src="/uploads/' . $inlinePath . '" alt="B" /> text</p>',
            $entry->data['body'],
        );
        self::assertSame([['body', $image], ['body', $inline]], $this->references($entry->id), 'linked, resized and inline images');
    }

    public function testAnUpdateReplacesTheReferences(): void
    {
        $old = $this->insertMedia();
        $new = $this->insertMedia();
        $entry = $this->service()->create($this->articles(), 'en', new EntryInput('Hello', fields: ['cover' => $old]), null, null);

        $updated = $this->service()->update($entry, new EntryInput('Hello', fields: ['images' => [$new]]), null, null);

        self::assertArrayNotHasKey('cover', $updated->data);
        self::assertSame([['images', $new]], $this->references($entry->id));
        self::assertSame(1, $this->mediaRepository()->delete($old), 'the old cover is free again');
    }

    public function testRejectsUnknownMediaAndNonImagesInImageFields(): void
    {
        $photo = $this->insertMedia();
        $pdf = $this->insertMedia(['path' => '2026/09/' . str_repeat('c', 32) . '.pdf', 'mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $create = $this->createArticle(...);

        self::assertValidation(['cover' => 'validation.field.media', 'images' => 'validation.field.media'], fn() => $create(['cover' => 999, 'images' => [$photo, 998]]));
        self::assertValidation(['cover' => 'validation.field.media_type', 'images' => 'validation.field.media_type'], fn() => $create(['cover' => $pdf, 'images' => [$photo, $pdf]]));
        self::assertValidation(['cover' => 'validation.field.media'], fn() => $create(['cover' => 'abc']));
        self::assertValidation(['attachment' => 'validation.field.media'], fn() => $create(['attachment' => 997]));
        self::assertValidation(['images' => 'validation.field.too_many'], fn() => $create(['images' => '1,2,3,4,5']), ['images' => ['max' => 4]]);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));

        self::assertSame($photo, $create(['attachment' => $photo])->data['attachment'], 'a file field takes images too');
    }

    public function testRestoringARevisionDropsDeletedMedia(): void
    {
        $old = $this->insertMedia();
        $new = $this->insertMedia();
        $entry = $this->service()->create($this->articles(), 'en', new EntryInput('Hello', fields: ['cover' => $old, 'images' => [$old, $new]]), null, null);
        $firstRevision = Cast::int($this->db->scalar('SELECT MIN(id) FROM entry_revisions WHERE entry_id = ?', [$entry->id]));
        $updated = $this->service()->update($entry, new EntryInput('Hello', fields: ['images' => [$new]]), null, null);
        self::assertSame(1, $this->mediaRepository()->delete($old));

        $restored = $this->service()->restore($updated, $firstRevision, null, null);

        self::assertArrayNotHasKey('cover', $restored->data);
        self::assertSame([$new], $restored->data['images']);
        self::assertSame([['images', $new]], $this->references($entry->id));
    }

    public function testSlidesKeepTheirShapeAndReferenceTheirImages(): void
    {
        $first = $this->insertMedia();
        $second = $this->insertMedia();

        $entry = $this->service()->create($this->pages(), 'en', new EntryInput('Home', fields: ['hero' => [
            '4' => ['image' => (string) $second, 'title' => 'Second', 'url' => '/about', 'position' => '2'],
            '1' => ['image' => (string) $first, 'title' => 'First', 'position' => '1'],
        ]]), null, null);

        self::assertSame([['image' => $first, 'title' => 'First'], ['image' => $second, 'title' => 'Second', 'url' => '/about']], $entry->data['hero']);
        self::assertSame([['hero', $first], ['hero', $second]], $this->references($entry->id));
        self::assertSame([$first, $second], $this->entryMedia()->ids([$entry]));
    }

    public function testSlidesRefuseUnknownMediaAndNonImages(): void
    {
        $photo = $this->insertMedia();
        $pdf = $this->insertMedia(['path' => '2026/09/' . str_repeat('c', 32) . '.pdf', 'mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $create = fn(array $hero): Entry => $this->service()->create($this->pages(), 'en', new EntryInput('X', fields: ['hero' => $hero]), null, null);

        self::assertValidation(['hero' => 'validation.field.media'], fn() => $create([['image' => $photo], ['image' => 999, 'title' => 'Gone']]));
        self::assertValidation(['hero' => 'validation.field.media_type'], fn() => $create([['image' => $pdf]]));
        self::assertValidation(['hero' => 'validation.slides.image'], fn() => $create([['title' => 'No photo']]), ['hero' => ['slide' => 1]]);
        self::assertValidation(['hero' => 'validation.field.too_many'], fn() => $create([['image' => $photo], ['image' => $photo], ['image' => $photo], ['image' => $photo]]), ['hero' => ['max' => 3]]);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    public function testRestoringARevisionDropsOnlyTheSlidesWhoseImageIsGone(): void
    {
        $old = $this->insertMedia();
        $kept = $this->insertMedia();
        $entry = $this->service()->create($this->pages(), 'en', new EntryInput('Home', fields: ['hero' => [
            ['image' => $old, 'title' => 'Old', 'subtitle' => 'Gone soon'],
            ['image' => $kept, 'title' => 'Kept', 'url' => '/news', 'button' => 'Read'],
        ]]), null, null);
        $firstRevision = Cast::int($this->db->scalar('SELECT MIN(id) FROM entry_revisions WHERE entry_id = ?', [$entry->id]));
        $updated = $this->service()->update($entry, new EntryInput('Home', fields: ['hero' => [['image' => $kept]]]), null, null);
        self::assertSame(1, $this->mediaRepository()->delete($old));

        $restored = $this->service()->restore($updated, $firstRevision, null, null);

        self::assertSame([['image' => $kept, 'title' => 'Kept', 'url' => '/news', 'button' => 'Read']], $restored->data['hero']);
        self::assertSame([['hero', $kept]], $this->references($entry->id));
    }

    public function testASlideVideoIsReferencedAndMustBeAVideo(): void
    {
        $photo = $this->insertMedia();
        $video = $this->insertMedia(['path' => '2026/09/' . str_repeat('a', 32) . '.mp4', 'mime' => 'video/mp4', 'width' => null, 'height' => null]);
        $pdf = $this->insertMedia(['path' => '2026/09/' . str_repeat('c', 32) . '.pdf', 'mime' => 'application/pdf', 'width' => null, 'height' => null]);
        $create = fn(array $hero): Entry => $this->service()->create($this->pages(), 'en', new EntryInput('X', fields: ['hero' => $hero]), null, null);

        // A document (or a picture) is no video: refused by type, naming the slide.
        self::assertValidation(['hero' => 'validation.slides.video'], fn() => $create([['image' => $photo, 'video' => (string) $pdf]]), ['hero' => ['slide' => 1]]);
        self::assertValidation(['hero' => 'validation.slides.video'], fn() => $create([['image' => $photo], ['image' => $photo, 'video' => $photo]]), ['hero' => ['slide' => 2]]);
        self::assertValidation(['hero' => 'validation.field.media_type'], fn() => $create([['image' => $video]])); // a video is no picture
        self::assertValidation(['hero' => 'validation.field.media'], fn() => $create([['image' => $photo, 'video' => 999]]));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));

        $entry = $create([['image' => (string) $photo, 'video' => (string) $video, 'title' => 'Waves']]);

        self::assertSame([['image' => $photo, 'video' => $video, 'title' => 'Waves']], $entry->data['hero']);
        self::assertEquals([new \AtarCms\Content\Field\Slide($photo, 'Waves', videoId: $video)], $entry->field('hero'));
        self::assertSame([['hero', $photo], ['hero', $video]], $this->references($entry->id), 'the video cannot be deleted while the slide uses it');
        self::assertSame([$photo, $video], $this->entryMedia()->ids([$entry]));
    }

    public function testRestoringARevisionClearsOnlyAVideoThatIsGone(): void
    {
        $photo = $this->insertMedia();
        $old = $this->insertMedia(['path' => '2026/09/' . str_repeat('a', 32) . '.mp4', 'mime' => 'video/mp4', 'width' => null, 'height' => null]);
        $entry = $this->service()->create($this->pages(), 'en', new EntryInput('Home', fields: ['hero' => [
            ['image' => $photo, 'video' => $old, 'title' => 'Waves'],
        ]]), null, null);
        $firstRevision = Cast::int($this->db->scalar('SELECT MIN(id) FROM entry_revisions WHERE entry_id = ?', [$entry->id]));
        $updated = $this->service()->update($entry, new EntryInput('Home', fields: ['hero' => [['image' => $photo]]]), null, null);
        self::assertSame(1, $this->mediaRepository()->delete($old));

        $restored = $this->service()->restore($updated, $firstRevision, null, null);

        self::assertSame([['image' => $photo, 'title' => 'Waves']], $restored->data['hero'], 'the slide survives with its picture');
        self::assertSame([['hero', $photo]], $this->references($entry->id));
    }

    public function testRestoringDropsTheWholeValueWhenNoSlideIsLeft(): void
    {
        $old = $this->insertMedia();
        $entry = $this->service()->create($this->pages(), 'en', new EntryInput('Home', fields: ['hero' => [['image' => $old]]]), null, null);
        $firstRevision = Cast::int($this->db->scalar('SELECT MIN(id) FROM entry_revisions WHERE entry_id = ?', [$entry->id]));
        $updated = $this->service()->update($entry, new EntryInput('Home'), null, null);
        self::assertSame(1, $this->mediaRepository()->delete($old));

        $restored = $this->service()->restore($updated, $firstRevision, null, null);

        self::assertArrayNotHasKey('hero', $restored->data);
    }

    public function testARequiredSlidesFieldNeedsASlideWithAnExistingImage(): void
    {
        $collection = Collection::make('showcase')->fields([Field::slides('hero')->required()]);
        $errors = new ValidationErrors();

        $data = $this->entryMedia()->validate($collection, ['hero' => [['image' => 999]]], true, $errors);

        self::assertSame([], $data);
        self::assertValidation(['hero' => 'validation.field.required'], $errors->throwIfAny(...));
    }

    public function testATranslationKeepsTheMedia(): void
    {
        $photo = $this->insertMedia();
        $source = $this->service()->create($this->articles(), 'en', new EntryInput('Hello', fields: ['cover' => $photo]), null, null);

        $translation = $this->service()->createTranslation($source, 'ru', null, null);

        self::assertSame($photo, $translation->data['cover']);
        self::assertSame([['cover', $photo]], $this->references($translation->id));
    }

    public function testMapsAMediumDeletedDuringASaveToAValidationError(): void
    {
        $entry = $this->insertEntry();
        try {
            $this->mediaRepository()->replaceEntryReferences($entry, [['field' => 'cover', 'mediaId' => 999999]]);
            self::fail('The reference was stored');
        } catch (PDOException $e) {
            self::assertSame(['entry' => 'validation.media.gone'], EntryService::mediaGoneError($e)?->errors);
        }
        self::assertNull(EntryService::mediaGoneError(new PDOException('another error')));
    }

    public function testUsedMediaCannotBeDeletedUntilTheEntryDropsIt(): void
    {
        $media = $this->mediaService()->upload(TestImages::uploaded(TestImages::jpeg(), 'x.jpg', 'image/jpeg'), null, null);
        $entry = $this->service()->create($this->articles(), 'en', new EntryInput('Hello', fields: ['body' => '<p><img src="/uploads/' . $media->path . '" alt=""></p>']), null, null);

        self::assertValidation(['media' => 'validation.media.in_use'], fn() => $this->mediaService()->delete($media, null, null), ['media' => ['count' => 1]]);

        $this->service()->update($entry, new EntryInput('Hello', fields: ['body' => '<p>Text only</p>']), null, null);
        $this->mediaService()->delete($media, null, null);
        self::assertNull($this->mediaRepository()->find($media->id));
    }

    public function testPathsFindLinksToStoredFilesOnly(): void
    {
        $hex = str_repeat('a', 32);

        self::assertSame(
            ['2026/09/' . $hex . '.jpg', '2026/10/' . $hex . '.pdf'],
            EntryMedia::paths(
                '<img src="/uploads/2026/09/' . $hex . '.jpg"><img src="/media/thumb/2026/09/' . $hex . '.jpg.webp?v=1">'
                . '<a href="http://example.org/uploads/2026/10/' . $hex . '.pdf">x</a><a href="/uploads/2026/09/x.jpg">bad</a>'
                . '<img src="/elsewhere/2026/09/' . $hex . '.jpg">',
            ),
        );
        // Pasted protocol-relative links count too (the sanitizer keeps them as relative links).
        self::assertSame(
            ['2026/11/' . $hex . '.png', '2026/12/' . $hex . '.webp'],
            EntryMedia::paths('<img src="//example.org/uploads/2026/11/' . $hex . '.png"><a href="//example.org/media/thumb/2026/12/' . $hex . '.webp?v=1">x</a><img src="///uploads/2026/09/' . $hex . '.jpg">'),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createArticle(array $fields): Entry
    {
        return $this->service()->create($this->articles(), 'en', new EntryInput('X', fields: $fields), null, null);
    }

    private function articles(): Collection
    {
        return $this->collections->get('articles');
    }

    private function pages(): Collection
    {
        return $this->collections->get('pages');
    }

    /**
     * @return list<array{string, int}> [field, media id] ordered by field, then media id
     */
    private function references(int $entryId): array
    {
        return array_map(
            static fn(array $row): array => [Cast::string($row['field']), Cast::int($row['media_id'])],
            $this->db->select('SELECT field, media_id FROM entry_media WHERE entry_id = ? ORDER BY field, media_id', [$entryId]),
        );
    }
}
