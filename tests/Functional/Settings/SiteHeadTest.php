<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Settings;

use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\AtarLogo;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SocialLink;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

final class SiteHeadTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testTheLogoIsTheDefaultIconAndNothingElseIsPrintedWhileNothingIsSet(): void
    {
        $logo = $this->app->container()->service(AtarLogo::class);
        self::assertSame(implode("\n", [
            '<link rel="icon" type="image/svg+xml" href="' . $logo->svg(AdminAccent::Green) . '">',
            '<link rel="icon" type="image/png" sizes="32x32" href="' . $logo->png(AdminAccent::Green, 32) . '">',
            '<link rel="apple-touch-icon" href="' . $logo->png(AdminAccent::Green, 180) . '">',
            '<link rel="manifest" href="/manifest.webmanifest">',
            '<meta name="apple-mobile-web-app-title" content="Fixture">',
        ]), $this->helper('siteHead'));
        self::assertMatchesRegularExpression('~^/admin-assets/logo/atar-green\.svg\?v=[0-9a-f]{12}$~', $logo->svg(AdminAccent::Green));
        self::assertMatchesRegularExpression('~^/admin-assets/logo/atar-green-180\.png\?v=[0-9a-f]{12}$~', $logo->png(AdminAccent::Green, 180));
        self::assertSame('Fixture', $this->helper('siteName'));
        self::assertSame([], $this->helper('socialLinks'));
        self::assertSame([], $this->helper('messengers'));
        self::assertNull($this->helper('siteLogo'));
    }

    public function testTheDefaultIconTakesTheSiteColourNearestToTheThemeColour(): void
    {
        $this->settings()->save('en', new SettingsInput(siteShortName: 'Club', themeColor: '#d62839'), null, null);
        $logo = $this->app->container()->service(AtarLogo::class);

        $head = $this->helper('siteHead');
        self::assertIsString($head);

        self::assertStringContainsString('<link rel="icon" type="image/svg+xml" href="' . $logo->svg(AdminAccent::Cherry) . '">', $head);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="' . $logo->png(AdminAccent::Cherry, 180) . '">', $head);
        self::assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Club">', $head);
        self::assertStringNotContainsString('atar-green', $head);
        $page = (string) $this->browser->get('/articles')->getBody();
        self::assertStringContainsString('<link rel="icon" type="image/svg+xml" href="' . $logo->svg(AdminAccent::Cherry) . '">', $page, 'the fixture layout prints them');
    }

    public function testTheLogoIsAMediaForImg(): void
    {
        $logo = $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(), 'logo.jpg', 'image/jpeg'), null, null);
        $this->settings()->save('en', new SettingsInput(logoId: $logo->id), null, null);

        $siteLogo = $this->helper('siteLogo');
        self::assertInstanceOf(Media::class, $siteLogo);
        self::assertEquals($logo->id, $siteLogo->id);
    }

    public function testTheHeadTagsOfTheSettings(): void
    {
        $icon = $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::png(512, 512), 'icon.png', 'image/png'), null, null);
        $this->settings()->save('en', new SettingsInput(
            siteName: 'Fishing club',
            siteShortName: 'Club & co',
            iconId: $icon->id,
            themeColor: '#1a2b3c',
            yandexVerification: '0123abcd',
            googleVerification: 'g00gle_-code',
        ), null, null);
        $variants = $this->app->container()->service(ImageVariants::class);

        self::assertSame(implode("\n", [
            '<link rel="icon" type="image/png" sizes="32x32" href="/media/icon_32/' . $icon->path . '?v=' . $variants->get('icon_32')?->version() . '">',
            '<link rel="apple-touch-icon" href="/media/icon_180/' . $icon->path . '?v=' . $variants->get('icon_180')?->version() . '">',
            '<link rel="manifest" href="/manifest.webmanifest">',
            '<meta name="theme-color" content="#1a2b3c">',
            '<meta name="apple-mobile-web-app-title" content="Club &amp; co">',
            '<meta name="yandex-verification" content="0123abcd">',
            '<meta name="google-site-verification" content="g00gle_-code">',
        ]), $this->helper('siteHead'));

        $page = (string) $this->browser->get('/articles')->getBody();
        self::assertStringContainsString('<meta name="google-site-verification" content="g00gle_-code">', $page, 'the fixture layout prints them');
    }

    public function testTitlesNamesAndLinksFollowTheLanguage(): void
    {
        $this->settings()->save('en', new SettingsInput(
            siteName: 'Club',
            titlePattern: '{title} | {site}',
            telegram: 'fishing_club',
            social: ['youtube' => 'https://youtube.com/@club', 'vk' => 'https://vk.com/club'],
        ), null, null);
        $this->settings()->save('ru', new SettingsInput(siteName: 'Клуб', titlePattern: '{title} | {site}', telegram: 'fishing_club', social: ['youtube' => 'https://youtube.com/@club', 'vk' => 'https://vk.com/club']), null, null);

        self::assertSame('Boats | Club', $this->helper('pageTitle', ['Boats']));
        self::assertSame('Club', $this->helper('pageTitle', [null]));
        self::assertSame('Лодки | Клуб', $this->helper('pageTitle', ['Лодки'], 'ru'));
        self::assertSame('Клуб', $this->helper('siteName', [], 'ru'));
        self::assertEquals([new SocialLink('youtube', 'https://youtube.com/@club'), new SocialLink('vk', 'https://vk.com/club')], $this->helper('socialLinks'));
        self::assertEquals([new SocialLink('telegram', 'https://t.me/fishing_club')], $this->helper('messengers'));
        self::assertStringContainsString('<meta property="og:site_name" content="Club">', (string) $this->browser->get('/articles')->getBody());
        self::assertStringContainsString('<title>Articles — Club</title>', (string) $this->browser->get('/articles/feed.xml')->getBody(), 'the feed is named after the site');
    }

    /**
     * @param list<mixed> $arguments
     */
    private function helper(string $name, array $arguments = [], string $locale = 'en'): mixed
    {
        return $this->app->container()->service(Renderer::class)->callHelper($name, $arguments, $this->request($locale));
    }

    private function request(string $locale): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/x')->withAttribute(RequestLocale::class, new RequestLocale($locale, $locale === 'en'));
    }

    private function settings(): SettingsService
    {
        return $this->app->container()->service(SettingsService::class);
    }
}
