<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\View\Http\AssetFileController;

/**
 * The theme's and the admin's assets are served by PHP when the web server has no rules for them (Apache with only
 * .htaccess, shared hosting, php -S): only files inside the asset directory, only known types, the headers nginx
 * sends, no session.
 */
final class AssetFilesTest extends FunctionalTestCase
{
    public function testTheThemeStylesheetIsServedWithTheStaticHeaders(): void
    {
        $response = $this->browser->get('/theme-assets/app.css?v=123');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(AssetFileController::CACHE, $response->getHeaderLine('Cache-Control'));
        self::assertSame('public, max-age=31536000, immutable', AssetFileController::CACHE);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame((string) file_get_contents(self::SITE . '/themes/test/assets/app.css'), (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
    }

    public function testAnAdminAssetIsServed(): void
    {
        $response = $this->browser->get('/admin-assets/admin.css');
        $sprite = $this->browser->get('/admin-assets/icons.svg');
        $module = $this->browser->get('/admin-assets/vendor/htmx.min.js');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('image/svg+xml', $sprite->getHeaderLine('Content-Type'));
        self::assertSame('text/javascript; charset=utf-8', $module->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testAnUnchangedFileAnswers304(): void
    {
        $etag = $this->browser->get('/theme-assets/app.css')->getHeaderLine('ETag');

        $response = $this->browser->get('/theme-assets/app.css', ['If-None-Match' => $etag]);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testNothingOutsideTheAssetDirectoryAndNoUnknownTypeIsServed(): void
    {
        foreach ([
            '/theme-assets/../theme.php',
            '/theme-assets/%2e%2e/theme.php',
            '/theme-assets/..%2ftheme.php',
            '/theme-assets/css/../../theme.php',
            '/theme-assets/.hidden.css',
            '/theme-assets/app.css%00.png',
            '/theme-assets/a\\b.css',
            '/theme-assets/missing.css',
            '/theme-assets/app.php',
            '/admin-assets/../templates/layout.php',
            '/admin-assets/vendor/htmx.LICENSE',
            '/admin-assets/vendor',
            '/admin-assets/logo',
        ] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
    }
}
