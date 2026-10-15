<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Kernel\Container;
use AtarCms\Media\MediaFolder;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * The library's folders, views, filters and sorting (the picker's in MediaPickerTest).
 */
final class MediaBrowsingTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testTheRootListsUnfiledFilesAndTheTreeShowsEveryFolder(): void
    {
        $photos = $this->folder('Photos <1>');
        $summer = $this->folder('Summer', $photos->id);
        $this->insertMedia('loose.jpg');
        $this->insertMedia('beach.jpg', $summer->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/media')->getBody();

        self::assertStringContainsString('loose.jpg', $body);
        self::assertStringNotContainsString('beach.jpg', $body, 'files in folders stay in their folders');
        self::assertStringContainsString('1 file', $body);
        self::assertMatchesRegularExpression('~<a class="admin-media-folder" href="/admin/media" aria-current="page"><svg[^>]*>.*?</svg><span>All files</span></a><ul class="admin-media-tree"><li><div class="admin-media-folder-row"><a class="admin-media-folder" href="/admin/media\?folder=' . $photos->id . '"><svg[^>]*>.*?</svg><span>Photos &lt;1&gt;</span></a><a class="admin-media-folder-edit" href="/admin/media/folders/' . $photos->id . '" title="Rename or move “Photos &lt;1&gt;”" data-dialog-open>.*?</a></div><ul class="admin-media-tree"><li><div class="admin-media-folder-row"><a class="admin-media-folder" href="/admin/media\?folder=' . $summer->id . '">~s', $body);
        self::assertStringContainsString('href="/admin/media/folders/new"', $body);
        self::assertStringContainsString('<li><span aria-current="page">All files</span></li>', $body);
        self::assertStringContainsString('<input type="hidden" name="folder" value="">', $body);
    }

    public function testAFolderShowsItsTrailAndOwnFiles(): void
    {
        $photos = $this->folder('Photos');
        $summer = $this->folder('Summer', $photos->id);
        $this->insertMedia('beach.jpg', $summer->id);
        $this->insertMedia('top.jpg', $photos->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/media?folder=' . $summer->id)->getBody();

        self::assertStringContainsString('beach.jpg', $body);
        self::assertStringNotContainsString('top.jpg', $body);
        self::assertMatchesRegularExpression('~<ol>\s*<li><a href="/admin/media">All files</a></li>\s*<li><span class="admin-media-trail-sep"><svg[^>]*><use href="[^"]+#chevron-right"></use></svg></span><a href="/admin/media\?folder=' . $photos->id . '">Photos</a></li>\s*<li><span class="admin-media-trail-sep">.*?</span><span aria-current="page">Summer</span></li>\s*</ol>~s', $body);
        self::assertStringContainsString('href="/admin/media/folders/' . $summer->id . '"', $body, 'the folder can be renamed or moved');
        self::assertStringContainsString('href="/admin/media/folders/new?parent=' . $summer->id . '"', $body);
        self::assertStringContainsString('<input type="hidden" name="folder" value="' . $summer->id . '">', $body, 'uploads go into the folder');
    }

    public function testUnknownFolderFallsBackToTheRoot(): void
    {
        $this->insertMedia('loose.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/media?folder=999');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('loose.jpg', (string) $response->getBody());
    }

    public function testListViewShowsTypeSizeDimensionsDateAndUsageAndIsRemembered(): void
    {
        $used = $this->insertMedia('used.jpg');
        $settings = $this->insertMedia('logo.jpg');
        $this->insertMedia('report.pdf', null, 'application/pdf');
        $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $used]]);
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(ogImageId: $settings), null, null);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/media?view=list');
        $body = (string) $response->getBody();

        self::assertStringContainsString('<table class="admin-table admin-media-table">', $body);
        self::assertStringContainsString('<td data-label="Type">PDF</td>', $body);
        self::assertStringContainsString('<td data-label="Dimensions">64 × 48 px</td>', $body);
        self::assertStringContainsString('<td data-label="Size">0.1 KB</td>', $body);
        self::assertMatchesRegularExpression('~<td class="admin-media-cell-date" data-label="Uploaded"><span class="admin-table-date">[A-Z][a-z]{2} \d{1,2}, \d{4}</span><span class="admin-table-time">\d{2}:\d{2}</span></td>~', $body, 'a readable date in the interface language');
        self::assertStringContainsString('<td data-label="Used"></td>', $body, 'an unused file has an empty cell');
        self::assertSame(2, substr_count($body, '<td data-label="Used"><span class="admin-media-uses" title="1 use">1</span></td>'), 'an entry field and the site settings each count as a use');
        self::assertStringContainsString('<label class="admin-media-name" title="used.jpg" for="media-check-' . $used . '">used.jpg</label>', $body);
        self::assertStringContainsString('<input type="radio" class="admin-visually-hidden" name="view" value="list" checked>', $body);
        self::assertStringContainsString('title="View: Show as a list" aria-label="View: Show as a list"', $body);
        self::assertStringContainsString('admin_media_view=list; Path=/admin; Max-Age=31536000; HttpOnly; SameSite=Lax; Secure', $response->getHeaderLine('Set-Cookie'));

        $again = $this->browser->get('/admin/media');
        self::assertStringContainsString('<table class="admin-table admin-media-table">', (string) $again->getBody(), 'the cookie keeps the list view');
        self::assertSame('', $again->getHeaderLine('Set-Cookie'), 'an unchanged choice is not written again');
        self::assertStringContainsString('<ul class="admin-media-grid">', (string) $this->browser->get('/admin/media?view=grid')->getBody());
        self::assertStringContainsString('<ul class="admin-media-grid">', (string) $this->browser->get('/admin/media?view=bogus')->getBody());
    }

    public function testFiltersSortAndFolderSurviveLinks(): void
    {
        $photos = $this->folder('Photos');
        for ($i = 1; $i <= 49; $i++) {
            $this->insertMedia(sprintf('file-%02d.jpg', $i), $photos->id);
        }
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/media?folder=' . $photos->id . '&sort=name&type=image')->getBody();

        self::assertStringContainsString('href="/admin/media?folder=' . $photos->id . '&amp;type=image&amp;sort=name&amp;page=2"', $body);
        self::assertStringContainsString('<input type="radio" class="admin-visually-hidden" name="sort" value="name" checked>', $body);
        self::assertStringContainsString('<input type="radio" class="admin-visually-hidden" name="type" value="image" checked>', $body);
        self::assertStringContainsString('title="Order: By name" aria-label="Order: By name"', $body);
        self::assertStringContainsString('hx-get="/admin/media" hx-target="#media-results" hx-select="#media-results" hx-swap="outerHTML" hx-push-url="true"', $body, 'the toolbar swaps only the results');
        self::assertStringContainsString('placeholder="Name or description"', $body);
        self::assertStringContainsString('<button type="submit" class="admin-button admin-button-secondary admin-media-apply">', $body, 'the no-JS submit button');
        self::assertStringContainsString('<input type="hidden" name="folder" value="' . $photos->id . '">', $body);
        self::assertStringContainsString('file-01.jpg', $body, 'by name');
        self::assertStringNotContainsString('file-49.jpg', $body);
    }

    public function testSearchCoversSubfoldersUnlessEverywhere(): void
    {
        $photos = $this->folder('Photos');
        $summer = $this->folder('Summer', $photos->id);
        $other = $this->folder('Other');
        $this->insertMedia('sea-deep.jpg', $summer->id);
        $this->insertMedia('sea-top.jpg', $photos->id);
        $this->insertMedia('sea-other.jpg', $other->id);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $inPhotos = (string) $this->browser->get('/admin/media?folder=' . $photos->id . '&q=sea')->getBody();
        self::assertStringContainsString('sea-deep.jpg', $inPhotos);
        self::assertStringContainsString('sea-top.jpg', $inPhotos);
        self::assertStringNotContainsString('sea-other.jpg', $inPhotos);

        $everywhere = (string) $this->browser->get('/admin/media?folder=' . $photos->id . '&q=sea&everywhere=1')->getBody();
        self::assertStringContainsString('sea-other.jpg', $everywhere);
        self::assertStringContainsString('name="everywhere" value="1" checked', $everywhere);
        self::assertStringContainsString('3 files found in all folders', $everywhere, 'the results say they come from every folder');
        self::assertStringContainsString('sea-other.jpg', (string) $this->browser->get('/admin/media?q=sea')->getBody(), 'a search from the root covers every folder');

        $noSearch = (string) $this->browser->get('/admin/media?folder=' . $photos->id . '&everywhere=1')->getBody();
        self::assertStringContainsString('sea-top.jpg', $noSearch);
        self::assertStringNotContainsString('sea-other.jpg', $noSearch, 'without a search the folder shown is listed, whatever the scope');
        self::assertStringNotContainsString('found in all folders', $noSearch);
    }

    public function testOnlyUnusedFilter(): void
    {
        $used = $this->insertMedia('used.jpg');
        $this->insertMedia('free.jpg');
        $logo = $this->insertMedia('logo.jpg');
        $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $used]]);
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(logoId: $logo), null, null);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/media?unused=1')->getBody();

        self::assertStringContainsString('free.jpg', $body);
        self::assertStringNotContainsString('used.jpg', $body);
        self::assertStringNotContainsString('logo.jpg', $body, 'the site logo is in use');
        self::assertStringContainsString('name="unused" value="1" checked', $body);
    }

    public function testUploadGoesIntoTheCurrentFolder(): void
    {
        $photos = $this->folder('Photos');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->upload('/admin/media', ['_csrf' => $this->csrfToken(), 'folder' => (string) $photos->id], ['files' => [TestImages::uploaded(TestImages::jpeg(), 'cat.jpg', 'image/jpeg')]]);

        self::assertSame('/admin/media?folder=' . $photos->id, $response->getHeaderLine('Location'));
        self::assertSame($photos->id, Cast::int($this->db->scalar('SELECT folder_id FROM media')));

        // htmx sends the toolbar's fields along (hx-include): the results keep its view and order.
        $fragment = (string) $this->browser->upload('/admin/media', ['_csrf' => $this->csrfToken(), 'folder' => (string) $photos->id, 'view' => 'list', 'sort' => 'name'], ['files' => [TestImages::uploaded(TestImages::jpeg(), 'dog.jpg', 'image/jpeg')]], ['HX-Request' => 'true'])->getBody();
        self::assertStringStartsWith('<div id="media-results"', trim($fragment));
        self::assertStringContainsString('<table class="admin-table admin-media-table">', $fragment);
        self::assertLessThan(strpos($fragment, 'dog.jpg'), (int) strpos($fragment, 'cat.jpg'), 'by name');
    }

    private function folder(string $name, ?int $parent = null): MediaFolder
    {
        return $this->app->container()->service(MediaFolderService::class)->create($name, $parent, null, null);
    }

    private function insertMedia(string $name, ?int $folder = null, string $mime = 'image/jpeg'): int
    {
        $now = $this->clock->now();

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . ($mime === 'application/pdf' ? '.pdf' : '.jpg'),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $mime === 'application/pdf' ? null : 64,
            'height' => $mime === 'application/pdf' ? null : 48,
            'alt' => [],
            'uploaded_by' => null,
            'folder_id' => $folder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
