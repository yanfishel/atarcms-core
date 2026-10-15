<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;

final class RichTextEditorTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([...Roles::DEFAULTS, 'writer' => ['label' => 'Writer', 'permissions' => ['entries.*.*']]]));
    }

    public function testFormsWithRichTextLoadTheEditorItsStylesheetsAndThePicker(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/content/pages/new?locale=ru');
        $body = (string) $response->getBody();
        $csp = $response->getHeaderLine('Content-Security-Policy');
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $csp, $nonce);

        self::assertStringContainsString("style-src 'self' 'sha256-", $csp, 'no style nonce');
        self::assertStringContainsString("; style-src-attr 'unsafe-inline';", $csp, 'the sanitized styles of rich text apply');
        self::assertStringNotContainsString('csp-nonce', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/editor\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($nonce[1] ?? '', '~') . '"></script>~', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/media\.js\?v=[0-9a-f]{12}"~', $body, 'the picker of the media library');
        self::assertMatchesRegularExpression(
            '~<link rel="stylesheet" href="/admin-assets/admin\.css\?v=[0-9a-f]{12}">.*<link rel="stylesheet" href="/admin-assets/vendor/ckeditor5-48\.5\.2\.css\?v=[0-9a-f]{12}">\s*<link rel="stylesheet" href="/theme-assets/editor\.css\?v=[0-9a-f]{12}">~s',
            $body,
            'admin.css, then CKEditor, then the theme',
        );
        self::assertStringContainsString('<form class="admin-entry-form" method="post" action="/admin/content/pages" data-content-locale="ru" data-entry-form data-autosave="/admin/content/pages/autosave">', $body);
        self::assertStringContainsString('<dialog class="admin-dialog admin-media-dialog" data-media-dialog', $body);
        self::assertStringNotContainsString('data-upload-url', $body);
        self::assertStringNotContainsString('rich-text.js', $body);
        self::assertStringNotContainsString('trix', $body);
    }

    public function testWithoutUploadPermissionThereIsNoPicker(): void
    {
        $writer = new Browser($this->app);
        $this->actingAs($this->createUser('wr@example.com', 'writer'), $writer);

        $body = (string) $writer->get('/admin/content/pages/new')->getBody();

        self::assertStringContainsString('data-rich-text', $body);
        self::assertStringContainsString('editor.js', $body);
        self::assertStringNotContainsString('data-media-dialog', $body);
    }

    public function testPagesWithoutRichTextDoNotLoadTheEditor(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach (['/admin', '/admin/content/notes/new', '/admin/media'] as $path) {
            $body = (string) $this->browser->get($path)->getBody();
            self::assertStringNotContainsString('editor.js', $body, $path);
            self::assertStringNotContainsString('ckeditor5', $body, $path);
            self::assertStringNotContainsString('/theme-assets/', $body, $path);
        }
    }

    public function testTheGuestLoginPageDoesNotLoadTheEditor(): void
    {
        $response = $this->browser->get('/admin/login');
        $body = (string) $response->getBody();

        self::assertStringNotContainsString('editor.js', $body);
        self::assertStringNotContainsString('csp-nonce', $body);
        self::assertStringContainsString("style-src 'self' 'sha256-", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testPublicPagesShowTheStylesOfRichText(): void
    {
        $response = $this->browser->get('/');
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertSame(200, $response->getStatusCode(), 'the header of a public page, not of an error page');
        self::assertStringContainsString("; style-src-attr 'unsafe-inline';", $csp);
        self::assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $csp, '<style> elements stay blocked');
    }
}
