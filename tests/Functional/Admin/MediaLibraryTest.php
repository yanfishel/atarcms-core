<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaConfig;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\Regex;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class MediaLibraryTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
        $container->set(MediaStorage::class, $this->storage);
        $container->set(Roles::class, new Roles([...Roles::DEFAULTS, 'viewer' => ['label' => 'Viewer', 'permissions' => ['entries.*.view']]]));
    }

    public function testListsFilesNewestFirstWithSearchAndPages(): void
    {
        for ($i = 1; $i <= 49; $i++) {
            $this->insertMedia(sprintf('file-%02d.jpg', $i));
        }
        $this->insertMedia('<b>"x".pdf', 'application/pdf');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/media');
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('50 files', $body);
        self::assertStringContainsString('&lt;b&gt;&quot;x&quot;.pdf', $body);
        self::assertStringContainsString('<span class="admin-media-thumb admin-media-file">PDF</span>', $body);
        self::assertStringContainsString('file-03.jpg', $body);
        self::assertStringNotContainsString('file-02.jpg', $body);
        self::assertMatchesRegularExpression('~<img class="admin-media-thumb" src="/media/admin/2026/09/[0-9a-f]{32}\.jpg\.webp\?v=[0-9a-f]{8}"~', $body);
        self::assertStringContainsString('href="/admin/media?page=2"', $body);
        self::assertStringContainsString('href="/admin/media"', $body, 'the admin menu links the library');

        $second = (string) $this->browser->get('/admin/media?page=2')->getBody();
        self::assertStringContainsString('file-01.jpg', $second);
        self::assertStringContainsString('file-02.jpg', $second);

        $found = (string) $this->browser->get('/admin/media?q=' . rawurlencode('<b>'))->getBody();
        self::assertStringContainsString('1 file', $found);
        self::assertStringNotContainsString('file-01.jpg', $found);
    }

    public function testTheLibraryLoadsItsScriptAsANonceModuleWithoutInlineCode(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/media');
        $body = (string) $response->getBody();
        $csp = Regex::match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'));

        self::assertNotEmpty($csp[1]);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/media\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1], '~') . '"></script>~', $body);
        self::assertStringContainsString('data-media-dropzone', $body);
        self::assertStringContainsString('enctype="multipart/form-data"', $body);
        self::assertStringContainsString('hx-post="/admin/media"', $body);
        self::assertStringNotContainsString(' style=', $body);
        self::assertMatchesRegularExpression('~<form class="admin-dropzone" [^>]*>\s*<input type="hidden" name="_csrf" [^>]*>\s*<input type="hidden" name="folder" value="">\s*<svg[^>]*><use href="[^"]+#upload"></use></svg>\s*<p class="admin-dropzone-title">Drag photos here or choose them on your computer</p>~', $body);
        self::assertStringContainsString('<label class="admin-button admin-button-secondary admin-dropzone-label" for="media-files">', $body);
        self::assertStringContainsString('<input type="file" id="media-files" name="files[]" multiple required', $body, 'the plain file input is the no-JS way to upload');
        self::assertStringContainsString('<progress class="admin-progress" max="100" value="0" hidden data-upload-progress>Uploading…</progress>', $body);
        self::assertStringContainsString('data-upload-submit>', $body);
        self::assertMatchesRegularExpression('~<label class="admin-media-search-field" for="media-q">.*?<input type="search" id="media-q" name="q"~s', $body);
    }

    public function testUploadsWithoutJavaScript(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->upload('/admin/media', ['_csrf' => $this->csrfToken()], ['files' => [
            TestImages::uploaded(TestImages::jpeg(), 'Cat <1>.jpg', 'image/jpeg'),
            TestImages::uploaded('<?php phpinfo();', 'shell.jpg', 'image/jpeg'),
        ]]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/media', $response->getHeaderLine('Location'));
        $body = (string) $this->browser->get('/admin/media')->getBody();
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-success" data-toast><svg[^>]*><use href="[^"]+#check"></use></svg><p class="admin-toast-text">1 file uploaded\.</p>~', $body, 'the layout shows the flash as a toast');
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*><use href="[^"]+#alert-circle"></use></svg><p class="admin-toast-text">“shell\.jpg”: this file type is not allowed\.</p>~', $body);
        self::assertStringNotContainsString('admin-alert-success', $body);
        self::assertStringContainsString('<span class="admin-media-name" title="Cat &lt;1&gt;.jpg">Cat &lt;1&gt;.jpg</span>', $body);
        self::assertMatchesRegularExpression('~<span class="admin-media-size">[0-9.]+ KB</span>~', $body);
        self::assertSame(['media.uploaded'], $this->auditActions());
        $path = Cast::string($this->db->scalar('SELECT path FROM media'));
        self::assertFileExists($this->storage->original($path));
    }

    public function testAnEmptyUploadAsksForAFile(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $this->browser->upload('/admin/media', ['_csrf' => $this->csrfToken()], ['files' => [TestImages::uploaded('', '', 'application/octet-stream', UPLOAD_ERR_NO_FILE)]]);

        self::assertStringContainsString('Choose a file to upload.', (string) $this->browser->get('/admin/media')->getBody());
    }

    public function testHtmxUploadsGetTheLibraryFragment(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->upload(
            '/admin/media',
            ['_csrf' => $this->csrfToken()],
            ['files' => [TestImages::uploaded(TestImages::png(), 'sea <x>.png', 'image/png'), TestImages::uploaded('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg', 'image/svg+xml')]],
            ['HX-Request' => 'true'],
        );
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<div id="media-results"', trim($body));
        self::assertStringNotContainsString('<html', $body);
        self::assertMatchesRegularExpression('~<template data-upload-toast><div class="admin-toast admin-toast-success" data-toast><svg[^>]*><use href="[^"]+#check"></use></svg><p class="admin-toast-text">1 file uploaded\.</p><button type="button" class="admin-toast-close" data-toast-close>.*?</button></div><div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*><use href="[^"]+#alert-circle"></use></svg><p class="admin-toast-text">“logo\.svg”: this file type is not allowed\.</p><button type="button" class="admin-toast-close" data-toast-close>.*?</button></div></template>~', $body, 'media.js moves the success and each failure into the toast stack; no whitespace travels with them');
        self::assertStringNotContainsString('admin-alert', $body);
        self::assertStringContainsString('sea &lt;x&gt;.png', $body);
    }

    public function testThePickerUploadsIntoItsOwnFragment(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->upload(
            '/admin/media?context=picker&kind=image',
            ['_csrf' => $this->csrfToken()],
            ['files' => [TestImages::uploaded(TestImages::jpeg(), 'new.jpg', 'image/jpeg')]],
            ['HX-Request' => 'true'],
        )->getBody();
        $id = Cast::int($this->db->scalar('SELECT id FROM media'));

        self::assertStringStartsWith('<div class="admin-media-picker admin-media-browser" data-media-picker', trim($body));
        self::assertStringContainsString('data-media-pick="' . $id . '"', $body);
        self::assertMatchesRegularExpression('~<template data-upload-toast><div class="admin-toast admin-toast-success" data-toast><svg[^>]*><use href="[^"]+#check"></use></svg><p class="admin-toast-text">1 file uploaded\.</p>~', $body);
        self::assertStringNotContainsString('admin-alert-success', $body);
    }

    public function testJsonUploadsForTheEditor(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $headers = ['Accept' => 'application/json', 'X-CSRF-Token' => $this->csrfToken()];

        $response = $this->browser->upload('/admin/media', [], ['files' => [TestImages::uploaded(TestImages::jpeg(), 'photo.jpg', 'image/jpeg')]], $headers);
        /** @var array{media: list<array<string, mixed>>, errors: list<string>} $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $path = Cast::string($this->db->scalar('SELECT path FROM media'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $data['errors']);
        self::assertSame('/uploads/' . $path, $data['media'][0]['url']);
        self::assertSame('photo.jpg', $data['media'][0]['name']);
        self::assertSame([64, 48], [$data['media'][0]['width'], $data['media'][0]['height']]);
        self::assertStringStartsWith('/media/admin/' . $path . '.webp?v=', Cast::string($data['media'][0]['thumb']));

        $failed = $this->browser->upload('/admin/media', [], ['files' => [TestImages::uploaded('<?php', 'shell.jpg', 'image/jpeg')]], $headers);
        self::assertSame(422, $failed->getStatusCode());
        self::assertSame(['media' => [], 'errors' => ['“shell.jpg”: this file type is not allowed.']], json_decode((string) $failed->getBody(), true));
    }

    public function testRejectsOversizedUploads(): void
    {
        $this->app->container()->set(MediaConfig::class, new MediaConfig(maxSize: 104858));
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->upload('/admin/media', [], ['files' => [
            TestImages::uploaded(TestImages::pdf() . str_repeat('x', 200000), 'big.pdf', 'application/pdf'),
            TestImages::uploaded('', 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE),
        ]], ['Accept' => 'application/json', 'X-CSRF-Token' => $this->csrfToken()]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['media' => [], 'errors' => ['“big.pdf” is larger than 0.1 MB.', '“huge.jpg” is larger than 0.1 MB.']],
            json_decode((string) $response->getBody(), true),
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
    }

    public function testThePickerListsImagesOrAllFiles(): void
    {
        $image = $this->insertMedia('photo.jpg');
        $pdf = $this->insertMedia('report.pdf', 'application/pdf');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $images = (string) $this->browser->get('/admin/media/picker?kind=image')->getBody();
        self::assertStringStartsWith('<div class="admin-media-picker admin-media-browser" data-media-picker', trim($images));
        self::assertStringContainsString('data-media-pick="' . $image . '"', $images);
        self::assertStringNotContainsString('data-media-pick="' . $pdf . '"', $images);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp,image/gif"', $images);
        self::assertStringContainsString('data-upload-submit>', $images);
        self::assertMatchesRegularExpression('~<label class="admin-media-search-field" for="picker-q">.*?<input type="search" id="picker-q" name="q"~s', $images);

        $all = (string) $this->browser->get('/admin/media/picker?kind=all&q=report')->getBody();
        self::assertStringContainsString('data-media-pick="' . $pdf . '"', $all);
        self::assertStringNotContainsString('data-media-pick="' . $image . '"', $all);
    }

    /**
     * partials/pagination.php: the library's links are plain links; the picker's (with $target) also load through
     * htmx into the picker, and still work as plain links.
     */
    public function testThePickerPagesThroughHtmxAndTheLibraryWithPlainLinks(): void
    {
        for ($i = 1; $i <= 49; $i++) {
            $this->insertMedia(sprintf('file-%02d.jpg', $i));
        }
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $picker = (string) $this->browser->get('/admin/media/picker?kind=image&locale=en')->getBody();
        $library = (string) $this->browser->get('/admin/media')->getBody();

        self::assertMatchesRegularExpression('~<a class="admin-page-link" href="/admin/media/picker\?folder=0&amp;kind=image&amp;locale=en&amp;page=2" aria-label="Page 2" hx-get="/admin/media/picker\?folder=0&amp;kind=image&amp;locale=en&amp;page=2" hx-target="closest \[data-media-picker\]" hx-swap="outerHTML">2</a>~', $picker);
        self::assertMatchesRegularExpression('~<a class="admin-page-link" href="/admin/media/picker\?folder=0&amp;kind=image&amp;locale=en&amp;page=2" rel="next" title="Next page" hx-get="[^"]+" hx-target="closest \[data-media-picker\]" hx-swap="outerHTML"><svg~', $picker);
        self::assertStringContainsString('<a class="admin-page-link" href="/admin/media?page=2" aria-label="Page 2">2</a>', $library);
        self::assertStringNotContainsString('hx-get="/admin/media?page', $library);
    }

    public function testThePickerTilesCarryTheAddressAndTheAltTextOfTheRequestedLanguage(): void
    {
        $image = $this->insertMedia('cat.jpg', 'image/jpeg', ['en' => 'A "cat"', 'ru' => 'Кот']);
        $path = Cast::string($this->db->scalar('SELECT path FROM media WHERE id = ?', [$image]));
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $tile = static fn(string $alt): string => sprintf('data-url="/uploads/%s" data-alt="%s">', $path, $alt);

        $russian = (string) $this->browser->get('/admin/media/picker?kind=image&locale=ru')->getBody();
        self::assertStringContainsString('data-media-pick="' . $image . '"', $russian);
        self::assertStringContainsString($tile('Кот'), $russian);
        self::assertStringContainsString('<input type="hidden" name="locale" value="ru">', $russian, 'searching keeps the language');
        self::assertStringContainsString('action="/admin/media?context=picker&amp;kind=image&amp;locale=ru"', $russian, 'uploading keeps the language');

        self::assertStringContainsString($tile('A &quot;cat&quot;'), (string) $this->browser->get('/admin/media/picker?kind=image&locale=en')->getBody());
        foreach (['/admin/media/picker?kind=image', '/admin/media/picker?kind=image&locale=de', '/admin/media/picker?kind=image&locale[]=ru'] as $url) {
            $body = (string) $this->browser->get($url)->getBody();
            self::assertStringContainsString($tile(''), $body, $url);
            self::assertStringNotContainsString('name="locale"', $body, $url);
        }

        $uploaded = (string) $this->browser->upload(
            '/admin/media?context=picker&kind=image&locale=ru',
            ['_csrf' => $this->csrfToken()],
            ['files' => [TestImages::uploaded(TestImages::jpeg(), 'new.jpg', 'image/jpeg')]],
            ['HX-Request' => 'true'],
        )->getBody();
        self::assertStringContainsString($tile('Кот'), $uploaded, 'the fragment after an upload keeps the language');
    }

    public function testRequiresTheUploadPermissionAndTheCsrfToken(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $response = $this->browser->upload('/admin/media', [], ['files' => [TestImages::uploaded(TestImages::jpeg(), 'photo.jpg', 'image/jpeg')]]);
        self::assertSame(419, $response->getStatusCode());

        $viewer = new Browser($this->app);
        $this->actingAs($this->createUser('viewer@example.com', 'viewer'), $viewer);
        foreach (['/admin/media', '/admin/media/picker?kind=image'] as $path) {
            self::assertSame(403, $viewer->get($path)->getStatusCode(), $path);
        }
        $denied = $viewer->upload('/admin/media', ['_csrf' => $this->csrfToken($viewer)], ['files' => [TestImages::uploaded(TestImages::jpeg(), 'photo.jpg', 'image/jpeg')]]);
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringNotContainsString('href="/admin/media"', (string) $viewer->get('/admin')->getBody());

        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM media')));
        self::assertSame(302, (new Browser($this->app))->get('/admin/media')->getStatusCode(), 'guests are sent to the login page');
    }

    /**
     * @param array<string, string> $alt locale => alt text
     */
    private function insertMedia(string $name, string $mime = 'image/jpeg', array $alt = []): int
    {
        $now = $this->clock->now();

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . ($mime === 'application/pdf' ? '.pdf' : '.jpg'),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $mime === 'application/pdf' ? null : 64,
            'height' => $mime === 'application/pdf' ? null : 48,
            'alt' => $alt,
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
