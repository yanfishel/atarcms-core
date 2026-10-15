<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\ShareImage\ShareImageOutcome;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Media\Media;
use AtarCms\Media\Share\ShareLayout;
use AtarCms\Settings\SettingsInput;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use AtarCms\View\ThemeConfig;

final class ShareImagesTest extends ContentTestCase
{
    use TempDirectory;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new MemoryLogger();
    }

    public function testASaveWithATitleAndAPhotoMakesTheImageOnce(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('Hello', status: EntryStatus::Published, fields: ['cover' => $photo->id], metaTitle: '  Рыбалка на Оке  '));

        self::assertMatchesRegularExpression('/^' . $entry->id . '-[0-9a-f]{16}\.jpg$/', (string) $entry->shareImage);
        self::assertSame($entry->shareImage, $this->entries->find($entry->id)?->shareImage);
        $file = $this->shareFile((string) $entry->shareImage);
        self::assertSame([1200, 630], array_slice((array) getimagesize($file), 0, 2));
        touch($file, 1000);
        clearstatcache();

        $same = $this->update($entry, new EntryInput('Hello again', status: EntryStatus::Published, fields: ['cover' => $photo->id], metaTitle: 'Рыбалка на Оке'));

        self::assertSame($entry->shareImage, $same->shareImage, 'the picture did not change: same fingerprint');
        self::assertSame(1000, filemtime($file), 'not drawn again');
        self::assertSame([], $this->logger->records);
    }

    public function testAChangedTitleMakesANewFileAndDeletesTheOld(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'First'));
        $old = (string) $entry->shareImage;

        $updated = $this->update($entry, new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Second'));

        self::assertNotSame($old, $updated->shareImage);
        self::assertFileExists($this->shareFile((string) $updated->shareImage));
        self::assertFileDoesNotExist($this->shareFile($old));
    }

    public function testWithoutATitleForSearchEnginesTheCardShowsTheTitle(): void
    {
        $photo = $this->photo();
        $plain = $this->create(new EntryInput('Рыбалка на Оке', fields: ['cover' => $photo->id]));
        self::assertNotNull($plain->shareImage, 'the entry title stands in for the empty title for search engines');
        self::assertFileExists($this->shareFile((string) $plain->shareImage));

        $meta = $this->create(new EntryInput('Hello', slug: 'meta', fields: ['cover' => $photo->id], metaTitle: 'Рыбалка на Оке'));
        $titled = $this->create(new EntryInput('Hello', slug: 'titled', fields: ['cover' => $photo->id]));

        self::assertSame(self::fingerprint($plain), self::fingerprint($meta), 'the title for search engines wins over the title');
        self::assertNotSame(self::fingerprint($meta), self::fingerprint($titled));
    }

    public function testAChangedEntryTitleRedrawsTheCardWithoutATitleForSearchEngines(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('First', fields: ['cover' => $photo->id]));
        $old = (string) $entry->shareImage;

        $renamed = $this->update($entry, new EntryInput('Second', fields: ['cover' => $photo->id]));

        self::assertNotSame(self::fingerprint($entry), self::fingerprint($renamed));
        self::assertFileExists($this->shareFile((string) $renamed->shareImage));
        self::assertFileDoesNotExist($this->shareFile($old));

        $meta = $this->update($renamed, new EntryInput('Second', fields: ['cover' => $photo->id], metaTitle: 'Third'));
        self::assertNotSame(self::fingerprint($renamed), self::fingerprint($meta));
        self::assertSame(self::fingerprint($meta), self::fingerprint($this->update($meta, new EntryInput('Fourth', fields: ['cover' => $photo->id], metaTitle: 'Third'))), 'with a title for search engines the title does not show');
    }

    public function testNoPhotoRemovesTheImage(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Title'));
        $file = $this->shareFile((string) $entry->shareImage);

        $noPhoto = $this->update($entry, new EntryInput('Hello', metaTitle: 'Title'));
        self::assertNull($noPhoto->shareImage);
        self::assertNull($this->entries->find($entry->id)?->shareImage);
        self::assertFileDoesNotExist($file);
    }

    public function testTheGalleryGivesItsFirstPhotoWhenThereIsNoCover(): void
    {
        $first = $this->photo();
        $entry = $this->create(new EntryInput('Hello', fields: ['images' => [$first->id, $this->photo()->id]], metaTitle: 'Title'));

        self::assertNotNull($entry->shareImage);
    }

    public function testTheMainPhotosOfAListComeFromOneQuery(): void
    {
        $cover = $this->insertMedia();
        $galleryFirst = $this->insertMedia();
        $document = $this->insertMedia(['mime' => 'application/pdf', 'original_name' => 'file.pdf', 'width' => null, 'height' => null]);
        $fallback = $this->insertMedia();
        $entries = [];
        foreach ([
            'cover' => ['cover' => $cover, 'images' => [$galleryFirst]],
            'gallery' => ['images' => [$galleryFirst, $cover]],
            'document' => ['cover' => $document, 'images' => [$fallback]],
            'none' => [],
            'gone' => ['cover' => 999999],
        ] as $slug => $data) {
            $entries[$slug] = $this->entries->find($this->insertEntry(['slug' => $slug, 'data' => $data])) ?? self::fail('no entry');
        }
        $images = $this->shareImages();

        $photos = [];
        $queries = QueryCounter::measure($this->db, static function () use ($images, $entries, &$photos): void {
            $photos = $images->mainPhotos(array_values($entries));
        });

        self::assertSame(1, $queries, 'one media query for the whole list');
        self::assertSame([
            $entries['cover']->id => $cover,
            $entries['gallery']->id => $galleryFirst,
            $entries['document']->id => $fallback,
        ], array_map(static fn(Media $media): int => $media->id, $photos), 'the first candidate that is an image; none without one');
        self::assertSame([], $images->mainPhotos([$entries['none']]));
        self::assertSame(0, QueryCounter::measure($this->db, static fn(): array => $images->mainPhotos([$entries['none']])), 'no query without candidates');
    }

    public function testAutosavesMakeNothingButOtherSavesDo(): void
    {
        $photo = $this->photo();
        $draft = $this->service(shareImages: $this->shareImages())->create($this->collections->get('articles'), 'en', new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Title'), null, null, autosave: true);
        self::assertNull($draft->shareImage);
        self::assertNull($this->update($draft, new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Title'), autosave: true)->shareImage);

        $saved = $this->update($draft, new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Title'));
        self::assertNotNull($saved->shareImage);

        $translation = $this->service(shareImages: $this->shareImages())->createTranslation($saved, 'ru', null, null);
        self::assertNotNull($translation->shareImage);
        self::assertNotSame($saved->shareImage, $translation->shareImage, 'each language its own image');

        $revision = Cast::int($this->db->scalar('SELECT MIN(id) FROM entry_revisions WHERE entry_id = ?', [$saved->id]));
        $noPhoto = $this->update($saved, new EntryInput('Hello', metaTitle: 'Title'));
        self::assertNull($noPhoto->shareImage);
        $restored = $this->service(shareImages: $this->shareImages())->restore($noPhoto, $revision, null, null);
        self::assertNotNull($restored->shareImage, 'restoring a revision is a save');
    }

    public function testALostRecordDecidesAgainFromTheFreshRow(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'First'));
        $this->entries->update($entry->id, ['meta_title' => 'Second']);
        $stale = $this->entries->find($entry->id) ?? self::fail('gone');
        // Meanwhile another writer (a rebuild that read the row earlier) records an older card.
        $older = $entry->id . '-0123456789abcdef.jpg';
        $this->mediaStorage()->writeShare($older, 'old card');
        self::assertTrue($this->entries->setShareImage($entry->id, $older, $entry->shareImage));

        $result = $this->shareImages()->sync($stale);

        self::assertSame(ShareImageOutcome::Made, $result->outcome);
        self::assertNotSame($older, $result->name);
        self::assertSame($result->name, $this->entries->find($entry->id)?->shareImage, 'the fresh row decided: the second title wins');
        self::assertFileExists($this->shareFile((string) $result->name));
        self::assertFileDoesNotExist($this->shareFile($older));
    }

    public function testACardThatCannotBeRecordedIsDeleted(): void
    {
        $this->db->execute('ALTER TABLE entries MODIFY share_image VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL');

        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $this->photo()->id], metaTitle: 'Title'));

        self::assertNull($entry->shareImage);
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame([], glob($this->tempDir() . '/uploads/share/*') ?: [], 'no unreferenced card stays on disk');
    }

    public function testATitleOfCharactersTheFontLacksMakesNoCard(): void
    {
        $photo = $this->photo();

        self::assertNull($this->create(new EntryInput('🎣', fields: ['cover' => $photo->id], metaTitle: '🎣🐟'))->shareImage);
        self::assertNotNull($this->create(new EntryInput('Hello', slug: 'other', fields: ['cover' => $photo->id], metaTitle: '🎣 Рыбалка'))->shareImage);
        $fallback = $this->create(new EntryInput('Hello', slug: 'fallback', fields: ['cover' => $photo->id], metaTitle: '🎣🐟'));
        $title = $this->create(new EntryInput('Hello', slug: 'title', fields: ['cover' => $photo->id]));
        self::assertSame(self::fingerprint($title), self::fingerprint($fallback), 'a title for search engines left empty falls back to the title');
    }

    public function testDeletingTheEntryDeletesItsFile(): void
    {
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $this->photo()->id], metaTitle: 'Title'));
        $file = $this->shareFile((string) $entry->shareImage);

        $this->service(shareImages: $this->shareImages())->delete($entry, null, null);

        self::assertFileDoesNotExist($file);
    }

    public function testAFailureSavesTheEntryWithoutAnImageAndIsLogged(): void
    {
        $missing = $this->mediaRepository()->find($this->insertMedia()) ?? self::fail('no media');
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $missing->id], metaTitle: 'Title'));

        self::assertNull($entry->shareImage);
        self::assertSame('Hello', $this->entries->find($entry->id)?->title);
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertStringContainsString('Share image of entry', (string) ($this->logger->records[0]['message'] ?? ''));
    }

    public function testOnlyRoutedCollectionsGetAnImage(): void
    {
        $photo = $this->photo();
        $entry = $this->create(new EntryInput('Hello', fields: ['cover' => $photo->id], metaTitle: 'Title'), 'stories', ['sections' => [$this->insertTerm(['taxonomy' => 'sections', 'name' => 'News', 'slug' => 'news'])]]);
        self::assertNotNull($entry->shareImage, 'stories have a route and a cover');

        $service = $this->shareImages();
        $result = $service->sync($this->entries->find($this->insertEntry(['collection' => 'notes', 'meta_title' => 'Title'])) ?? self::fail('no note'));
        self::assertSame(ShareImageOutcome::None, $result->outcome, 'notes have no route');
    }

    public function testAPlainSingleGetsNoImage(): void
    {
        $article = $this->create(new EntryInput('Hello', fields: ['cover' => $this->photo()->id]));
        $home = Collection::make('home')->route('/')->single()->plain()->fields([Field::image('cover')]);
        $plain = new Entry($article->id, $home, $article->locale, $article->translationGroup, $article->slug, $article->title, $article->status, $article->publishedAt, null, 0, null, $article->createdAt, $article->updatedAt, $article->seo, $article->data, $article->shareImage);

        $result = $this->shareImages()->sync($plain);

        self::assertSame(ShareImageOutcome::Removed, $result->outcome, 'its link previews use the site settings; its title is only the collection label');
    }

    public function testTheBrandOfALanguage(): void
    {
        $this->settingsService()->save('ru', new SettingsInput(siteName: 'Клуб рыбаков', siteShortName: 'Клуб'), null, null);
        $service = $this->shareImages(new ThemeConfig(shareLayout: ShareLayout::Panel), 'https://www.Example.COM:8080');

        $russian = $service->brand('ru');
        self::assertSame(['Клуб рыбаков', 'Клуб', 'example.com', '#2a7d5c', 'atar:green', ShareLayout::Panel], [$russian->name, $russian->shortName, $russian->domain, $russian->accent, $russian->logoSource, $russian->layout]);
        self::assertSame('Клуб', $russian->shownName());
        self::assertFalse($russian->rtl, 'Russian is left to right');
        self::assertTrue($service->brand('he')->rtl, 'the direction of the language, not of the name');
        self::assertSame('AtarCMS', $service->brand('en')->name, 'app.name without a setting or catalog');
        self::assertSame('пример.рф', $this->shareImages(null, 'https://xn--e1afmkfd.xn--p1ai')->brand('en')->domain);
    }

    public function testTheBrandTakesTheSiteColourNearestToTheThemeColour(): void
    {
        $this->settingsService()->save('en', new SettingsInput(themeColor: '#2a60c0'), null, null);

        $brand = $this->shareImages()->brand('en');

        self::assertSame(['#2563b8', 'atar:blue'], [$brand->accent, $brand->logoSource]);
        self::assertStringEndsWith('/logo/atar-blue-192.png', $brand->logoFile);
    }

    /**
     * @param array<string, list<int|string>>|null $terms
     */
    private function create(EntryInput $input, string $collection = 'articles', ?array $terms = null): Entry
    {
        if ($terms !== null) {
            $input = new EntryInput($input->title, fields: $input->fields, metaTitle: $input->metaTitle, terms: $terms);
        }

        return $this->service(shareImages: $this->shareImages())->create($this->collections->get($collection), 'en', $input, null, null);
    }

    private function update(Entry $entry, EntryInput $input, bool $autosave = false): Entry
    {
        return $this->service(shareImages: $this->shareImages())->update($this->entries->find($entry->id) ?? self::fail('gone'), $input, null, null, $autosave);
    }

    private function photo(): Media
    {
        return $this->mediaService()->upload(TestImages::uploaded(TestImages::jpeg(1600, 1000), 'photo.jpg', 'image/jpeg'), null, null);
    }

    private static function fingerprint(Entry $entry): string
    {
        return substr((string) $entry->shareImage, strlen($entry->id . '-'));
    }

    private function shareFile(string $name): string
    {
        return $this->mediaStorage()->share($name);
    }

    private function shareImages(?ThemeConfig $theme = null, string $appUrl = 'https://example.com'): ShareImages
    {
        return $this->shareImageService($theme, $appUrl, $this->logger);
    }
}
