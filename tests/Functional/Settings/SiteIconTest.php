<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Settings;

use AtarCms\Kernel\Container;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\AtarLogo;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

final class SiteIconTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testWithoutAnIconTheLogoInTheSiteColourIsServed(): void
    {
        $this->settings()->save('en', new SettingsInput(themeColor: '#1e5e55'), null, null);
        $logo = $this->app->container()->service(AtarLogo::class);

        $manifest = $this->browser->get('/manifest.webmanifest');
        $favicon = $this->browser->get('/favicon.ico');

        self::assertSame(200, $manifest->getStatusCode());
        self::assertSame('public, max-age=3600', $manifest->getHeaderLine('Cache-Control'));
        $manifestJson = json_decode((string) $manifest->getBody(), true);
        self::assertIsArray($manifestJson);
        self::assertSame([
            ['src' => $logo->png(AdminAccent::Teal, 192), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $logo->png(AdminAccent::Teal, 512), 'sizes' => '512x512', 'type' => 'image/png'],
        ], $manifestJson['icons'] ?? null);
        self::assertSame(302, $favicon->getStatusCode());
        self::assertSame($logo->png(AdminAccent::Teal, 32), $favicon->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('~^/admin-assets/logo/atar-teal-32\.png\?v=[0-9a-f]{12}$~', $favicon->getHeaderLine('Location'));
        self::assertSame('public, max-age=3600', $favicon->getHeaderLine('Cache-Control'));
        self::assertFalse($favicon->hasHeader('Set-Cookie'));
        self::assertSame(404, $this->browser->get('/ru/favicon.ico')->getStatusCode(), 'the default language only');
    }

    public function testTheManifestDescribesTheSiteInTheDefaultLanguage(): void
    {
        $icon = $this->icon();
        $this->settings()->save('en', new SettingsInput(siteName: 'Fishing <club>', siteShortName: 'Club', iconId: $icon->id, themeColor: '#1a2b3c'), null, null);
        $this->settings()->save('ru', new SettingsInput(siteName: 'Клуб', iconId: $icon->id, themeColor: '#1a2b3c'), null, null);

        $response = $this->browser->get('/manifest.webmanifest');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/manifest+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([
            'name' => 'Fishing <club>',
            'short_name' => 'Club',
            'start_url' => 'https://localhost/',
            'display' => 'browser',
            'theme_color' => '#1a2b3c',
            'background_color' => '#1a2b3c',
            'icons' => [
                ['src' => '/media/icon_192/' . $icon->path . '.png?v=' . $this->version('icon_192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/media/icon_512/' . $icon->path . '.png?v=' . $this->version('icon_512'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testTheFaviconRedirectsToTheSmallIcon(): void
    {
        $icon = $this->icon();
        $this->settings()->save('en', new SettingsInput(iconId: $icon->id), null, null);

        $response = $this->browser->get('/favicon.ico');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/media/icon_32/' . $icon->path . '.png?v=' . $this->version('icon_32'), $response->getHeaderLine('Location'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $png = $this->browser->get($response->getHeaderLine('Location'));
        self::assertSame('image/png', $png->getHeaderLine('Content-Type'));
        $manifest = json_decode((string) $this->browser->get('/manifest.webmanifest')->getBody(), true);
        self::assertIsArray($manifest);
        self::assertSame('Fixture', $manifest['name'], 'without a name: the theme catalog, else app.name');
        self::assertArrayNotHasKey('theme_color', $manifest);
    }

    private function icon(): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(600, 600), 'icon.jpg', 'image/jpeg'), null, null);
    }

    private function settings(): SettingsService
    {
        return $this->app->container()->service(SettingsService::class);
    }

    private function version(string $variant): string
    {
        return (string) $this->app->container()->service(ImageVariants::class)->get($variant)?->version();
    }
}
