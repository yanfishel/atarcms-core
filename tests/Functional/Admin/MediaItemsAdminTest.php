<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Kernel\Container;
use AtarCms\Media\Media;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class MediaItemsAdminTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
        $container->set(MediaStorage::class, $this->storage);
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'viewer' => ['label' => 'Viewer', 'permissions' => ['entries.*.view']],
            'illustrator' => ['label' => 'Illustrator', 'permissions' => ['media.upload', 'entries.articles.view']],
            'cleaner' => ['label' => 'Cleaner', 'permissions' => ['media.delete', 'entries.articles.view']],
        ]));
    }

    /**
     * Deleting needs "media.delete", the file's page "media.upload": without the latter the confirmation's Cancel and
     * a refused delete lead to the dashboard (the library needs "media.upload" too) instead of a page the user may not open.
     */
    public function testWithoutTheUploadRightCancelAndARefusalLeadToTheDashboard(): void
    {
        $media = $this->upload('used.jpg');
        $this->entry('articles', 'Uses it', ['slug' => 'uses-it', 'fields' => ['images' => [$media->id]]]);
        $cleaner = $this->createUser('cl@example.com', 'cleaner');
        $this->actingAs($cleaner);

        $confirm = (string) $this->browser->get('/admin/media/' . $media->id . '/delete')->getBody();
        self::assertStringContainsString('<a class="admin-button admin-button-secondary" href="/admin">', $confirm, 'the library needs the upload right too: the dashboard');
        self::assertStringNotContainsString('href="/admin/media/' . $media->id . '"', $confirm);

        $refused = $this->browser->post('/admin/media/' . $media->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame('/admin', $refused->getHeaderLine('Location'));
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
    }

    public function testTheLibraryLinksToTheEditPage(): void
    {
        $media = $this->upload('photo.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringContainsString('href="/admin/media/' . $media->id . '"', (string) $this->browser->get('/admin/media')->getBody());
    }

    public function testShowsTheFileItsAltTextsAndUsages(): void
    {
        $media = $this->upload('Sea <b>.jpg');
        $media = $this->service()->updateAlt($media, ['ru' => 'Море "синее"'], MediaService::version($media), null, null);
        $this->entry('articles', 'Trip <i>', ['slug' => 'trip', 'fields' => ['cover' => $media->id]]);
        $this->entry('pages', 'Hidden event', ['slug' => 'hidden', 'fields' => ['body' => '<p><img src="/uploads/' . $media->path . '" alt=""></p>']]);
        $this->actingAs($this->createUser('il@example.com', 'illustrator'));

        $response = $this->browser->get('/admin/media/' . $media->id);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Sea &lt;b&gt;.jpg</h1>', $body);
        self::assertStringContainsString('src="/uploads/' . $media->path . '"', $body);
        self::assertStringContainsString('name="alt[en]"', $body);
        self::assertStringContainsString('name="alt[ru]" maxlength="255" value="Море &quot;синее&quot;"', $body);
        self::assertStringContainsString('name="version" value="' . MediaService::version($media) . '"', $body);
        self::assertStringContainsString('Trip &lt;i&gt;</a>', $body);
        self::assertStringContainsString('An entry you cannot view', $body);
        self::assertStringNotContainsString('Hidden event', $body, 'titles of collections the user cannot view stay hidden');
        self::assertStringNotContainsString('/delete', $body, 'deleting needs media.delete');
        self::assertMatchesRegularExpression('~<div class="admin-action-bar">\s*<p class="admin-action-bar-status"></p>\s*<div class="admin-action-bar-buttons">\s*<button type="submit" class="admin-button admin-button-primary">~', $body);
        self::assertMatchesRegularExpression('~<nav class="admin-breadcrumbs"[^>]*>\s*<ol>\s*<li><a href="/admin">Dashboard</a></li>\s*<li><a href="/admin/media">Photos and files</a></li>\s*<li><span aria-current="page">Sea &lt;b&gt;.jpg</span></li>~', $body);
    }

    public function testShowsWhoUploadedTheFile(): void
    {
        $ann = $this->createUser('ann@example.com', 'editor', 'Ann <i>');
        $media = $this->service()->upload(TestImages::uploaded(TestImages::jpeg(), 'sky.jpg', 'image/jpeg'), $ann, null);
        $this->actingAs($ann);

        $body = (string) $this->browser->get('/admin/media/' . $media->id)->getBody();

        self::assertMatchesRegularExpression('~<dt>Uploaded by</dt>\s*<dd>Ann &lt;i&gt;</dd>~', $body);
        self::assertStringNotContainsString('Uploaded by', (string) $this->browser->get('/admin/media/' . $this->upload('anonymous.jpg')->id)->getBody());
    }

    public function testSavesAltTexts(): void
    {
        $media = $this->upload('sea.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/media/' . $media->id, [
            '_csrf' => $this->csrfToken(),
            'version' => MediaService::version($media),
            'alt' => ['en' => 'The sea', 'ru' => ''],
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media/' . $media->id, $response->getHeaderLine('Location'));
        self::assertStringContainsString('Changes saved.', (string) $this->browser->get('/admin/media/' . $media->id)->getBody());
        self::assertSame('{"en":"The sea"}', Cast::string($this->db->scalar('SELECT alt FROM media')));
        self::assertSame(['media.uploaded', 'media.updated'], $this->auditActions());
    }

    public function testRejectsLongAltTextsStaleFormsAndMissingTokens(): void
    {
        $media = $this->upload('sea.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $long = $this->browser->post('/admin/media/' . $media->id, ['_csrf' => $this->csrfToken(), 'version' => MediaService::version($media), 'alt' => ['en' => str_repeat('x', 256)]]);
        self::assertSame(422, $long->getStatusCode());
        self::assertStringContainsString('Use at most 255 characters.', (string) $long->getBody());

        $stale = $this->browser->post('/admin/media/' . $media->id, ['_csrf' => $this->csrfToken(), 'version' => '2020-01-01 00:00:00', 'alt' => ['en' => 'x']]);
        self::assertSame(422, $stale->getStatusCode());
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Someone else changed this file after you opened it\.~s', (string) $stale->getBody(), 'a refusal without a field is a toast');
        self::assertStringNotContainsString('admin-alert', (string) $stale->getBody());

        self::assertSame(419, $this->browser->post('/admin/media/' . $media->id, ['version' => MediaService::version($media), 'alt' => ['en' => 'x']])->getStatusCode());
        self::assertSame('{}', Cast::string($this->db->scalar('SELECT alt FROM media')));
    }

    public function testDeletesUnusedFiles(): void
    {
        $media = $this->upload('old.jpg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $confirm = (string) $this->browser->get('/admin/media/' . $media->id . '/delete')->getBody();
        self::assertStringContainsString('Delete “old.jpg”? This cannot be undone.', $confirm);
        self::assertStringContainsString('action="/admin/media/' . $media->id . '/delete"', $confirm);
        self::assertStringContainsString('<section class="admin-card admin-card-danger admin-confirm">', $confirm);
        self::assertMatchesRegularExpression('~<button type="submit" class="admin-button admin-button-danger"><svg[^>]*>.*?</svg>Delete permanently</button>~s', $confirm);

        $response = $this->browser->post('/admin/media/' . $media->id . '/delete', ['_csrf' => $this->csrfToken()]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media', $response->getHeaderLine('Location'));
        self::assertStringContainsString('File deleted.', (string) $this->browser->get('/admin/media')->getBody());
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
        self::assertFileDoesNotExist($this->storage->original($media->path));
        self::assertSame(['media.uploaded', 'media.deleted'], $this->auditActions());
    }

    public function testUsedFilesCannotBeDeleted(): void
    {
        $media = $this->upload('used.jpg');
        $this->entry('articles', 'Uses it', ['slug' => 'uses-it', 'fields' => ['images' => [$media->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $confirm = (string) $this->browser->get('/admin/media/' . $media->id . '/delete')->getBody();
        self::assertStringContainsString('This file cannot be deleted while it is used.', $confirm);
        self::assertStringNotContainsString('action="/admin/media/' . $media->id . '/delete"', $confirm);

        $response = $this->browser->post('/admin/media/' . $media->id . '/delete', ['_csrf' => $this->csrfToken()]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media/' . $media->id, $response->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~admin-toast-error.*1 entry uses this file\.~s', (string) $this->browser->get('/admin/media/' . $media->id)->getBody());
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
        self::assertFileExists($this->storage->original($media->path));
    }

    public function testPermissionsAndUnknownIds(): void
    {
        $media = $this->upload('x.jpg');
        $author = new Browser($this->app);
        $this->actingAs($this->createUser('au@example.com', 'author'), $author);
        self::assertSame(403, $author->get('/admin/media/' . $media->id . '/delete')->getStatusCode());
        self::assertSame(403, $author->post('/admin/media/' . $media->id . '/delete', ['_csrf' => $this->csrfToken($author)])->getStatusCode());
        self::assertSame(403, $author->get('/admin/media/999999/delete')->getStatusCode(), 'the permission is checked first');

        $viewer = new Browser($this->app);
        $this->actingAs($this->createUser('viewer@example.com', 'viewer'), $viewer);
        self::assertSame(403, $viewer->get('/admin/media/' . $media->id)->getStatusCode());
        self::assertSame(403, $viewer->post('/admin/media/' . $media->id, ['_csrf' => $this->csrfToken($viewer), 'version' => MediaService::version($media), 'alt' => ['en' => 'x']])->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(404, $this->browser->get('/admin/media/999999')->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/media/999999/delete', ['_csrf' => $this->csrfToken()])->getStatusCode());
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
    }

    public function testTheEditPageShowsTheAbsoluteAddressFolderAndAHiddenCopyButton(): void
    {
        $folders = $this->app->container()->service(MediaFolderService::class);
        $photos = $folders->create('Photos', null, null, null);
        $summer = $folders->create('Summer', $photos->id, null, null);
        $media = $this->service()->upload(TestImages::uploaded(TestImages::jpeg(), 'beach.jpg', 'image/jpeg'), null, null, $summer->id);
        $this->actingAs($this->createUser('il@example.com', 'illustrator'));

        $body = (string) $this->browser->get('/admin/media/' . $media->id)->getBody();
        $address = 'https://localhost/uploads/' . $media->path;

        self::assertStringContainsString('<a href="/uploads/' . $media->path . '" target="_blank" rel="noopener">' . $address . '</a>', $body);
        self::assertStringContainsString('<button type="button" class="admin-button admin-button-secondary admin-media-copy" hidden data-copy-link="' . $address . '">', $body);
        self::assertStringContainsString('<template data-copy-toast><div class="admin-toast admin-toast-success" data-toast>', $body);
        self::assertStringContainsString('<dd><a href="/admin/media?folder=' . $summer->id . '">All files › Photos › Summer</a></dd>', $body);
        self::assertStringContainsString('<a href="/admin/media?folder=' . $photos->id . '">Photos</a>', $body, 'the breadcrumbs follow the folders');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/media\.js\?v=~', $body);
    }

    public function testTheSiteSharingImageIsListedAsAUse(): void
    {
        $media = $this->upload('logo.jpg');
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(ogImageId: $media->id), null, null);

        $this->actingAs($this->createUser('ad@example.com', 'admin'));
        $body = (string) $this->browser->get('/admin/media/' . $media->id)->getBody();
        self::assertStringContainsString('<a href="/admin/settings">Site settings</a>', $body);
        self::assertStringNotContainsString('No entry uses this file.', $body);
        $confirm = (string) $this->browser->get('/admin/media/' . $media->id . '/delete')->getBody();
        self::assertStringContainsString('This file cannot be deleted while it is used.', $confirm);
        self::assertStringNotContainsString('Delete permanently', $confirm);

        $this->actingAs($this->createUser('il@example.com', 'illustrator'));
        self::assertStringContainsString('<span>Site settings</span>', (string) $this->browser->get('/admin/media/' . $media->id)->getBody(), 'no link without the right to open the settings');
    }

    public function testTheSiteSettingsUsesAreNamed(): void
    {
        $media = $this->upload('logo.jpg');
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(ogImageId: $media->id, logoId: $media->id), null, null);

        $this->actingAs($this->createUser('ad@example.com', 'admin'));
        $body = (string) $this->browser->get('/admin/media/' . $media->id)->getBody();

        self::assertSame(2, substr_count($body, '<a href="/admin/settings">Site settings</a>'), 'one line per use');
        self::assertStringContainsString('<span class="admin-muted">— Logo</span>', $body);
        self::assertStringContainsString('<span class="admin-muted">— Image for social sharing</span>', $body);
    }

    private function service(): MediaService
    {
        return $this->app->container()->service(MediaService::class);
    }

    private function upload(string $name): Media
    {
        return $this->service()->upload(TestImages::uploaded(TestImages::jpeg(), $name, 'image/jpeg'), null, null);
    }
}
