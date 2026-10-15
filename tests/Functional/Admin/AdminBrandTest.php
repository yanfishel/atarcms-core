<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteIcon;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use RuntimeException;

/**
 * The admin brand: the default language's short name (else its site name) beside a mark that is the site icon when
 * one is chosen, else the AtarCMS logo in the admin colour (the user's own, else the site colour nearest to the theme
 * colour), which <html data-accent> applies to the palette; on the shell, the login page, the error pages and when
 * the settings cannot be read.
 */
final class AdminBrandTest extends FunctionalTestCase
{
    use TempDirectory;

    private bool $brokenSettings = false;

    protected function configureContainer(Container $container): void
    {
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
        if ($this->brokenSettings) {
            $container->factory(SiteSettings::class, static fn(): SiteSettings => throw new RuntimeException('database down'));
        }
    }

    public function testTheBrandIsTheSiteNameOfTheDefaultLanguageBesideTheGreenLogo(): void
    {
        $service = $this->app->container()->service(SettingsService::class);
        $service->save('en', new SettingsInput(siteName: 'Fishing club'), null, null);
        $service->save('ru', new SettingsInput(siteName: 'Клуб рыбаков'), null, null);
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();

        self::assertStringContainsString('<span class="admin-brand-name">Fishing club</span>', $page);
        self::assertStringContainsString('<title>Site settings · Fishing club</title>', $page);
        self::assertMatchesRegularExpression('~<html lang="en" dir="ltr" data-accent="green">~', $page);
        self::assertMatchesRegularExpression('~<a class="admin-brand" href="/admin" data-rail-title="Fishing club">\s*<img class="admin-brand-mark" src="/admin-assets/logo/atar-green\.svg\?v=[0-9a-f]{12}" alt="" width="36" height="36">\s*<span class="admin-brand-name">~', $page);
    }

    public function testTheBrandPrefersTheShortName(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(siteName: 'Quiet Backwater Fishing Club', siteShortName: 'Backwater'), null, null);
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<span class="admin-brand-name">Backwater</span>', $page);
    }

    public function testTheSiteColourNearestToTheThemeColourColoursTheLoginAndUsersWithoutAChoice(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(themeColor: '#8e44ad'), null, null);

        $login = (string) $this->browser->get('/admin/login')->getBody();
        self::assertStringContainsString('data-accent="violet"', $login);
        self::assertMatchesRegularExpression('~<p class="admin-login-site"><img class="admin-brand-mark" src="/admin-assets/logo/atar-violet\.svg\?v=[0-9a-f]{12}" alt="" width="36" height="36">Fixture</p>~', $login);
        self::assertMatchesRegularExpression('~<link rel="icon" type="image/svg\+xml" href="/admin-assets/logo/atar-violet\.svg\?v=[0-9a-f]{12}">~', $login, 'the browser tab shows the logo too');

        $this->actingAs($this->createUser('admin@example.com'));
        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="violet">', $page);
        self::assertMatchesRegularExpression('~<img class="admin-brand-mark" src="/admin-assets/logo/atar-violet\.svg\?v=[0-9a-f]{12}"~', $page);
        self::assertMatchesRegularExpression('~<link rel="icon" type="image/svg\+xml" href="/admin-assets/logo/atar-violet\.svg\?v=[0-9a-f]{12}">~', $page);
        self::assertStringNotContainsString('style=', $page);
    }

    public function testAUsersOwnColourColoursTheirAdminOnly(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(themeColor: '#8e44ad'), null, null);
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setAccent($ann->id, AdminAccent::Cherry);
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="cherry">', $page);
        self::assertMatchesRegularExpression('~<img class="admin-brand-mark" src="/admin-assets/logo/atar-cherry\.svg\?v=[0-9a-f]{12}"~', $page, 'the mark in their colour');
        $missing = $this->browser->get('/admin/no-such-screen');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('data-accent="cherry"', (string) $missing->getBody(), 'their error pages too');

        $guest = new Browser($this->app);
        self::assertStringContainsString('data-accent="violet"', (string) $guest->get('/admin/login')->getBody(), 'a guest keeps the site colour');
        self::assertStringContainsString('data-accent="violet"', (string) $guest->get('/admin/no-such-screen')->getBody(), 'and so do a guest\'s error pages');
        $bob = $this->createUser('bob@example.com');
        $this->actingAs($bob, $guest);
        self::assertStringContainsString('data-accent="violet"', (string) $guest->get('/admin')->getBody(), 'another user without a choice');
    }

    public function testAChosenSiteIconIsTheMark(): void
    {
        $container = $this->app->container();
        $icon = $container->service(MediaService::class)->upload(TestImages::uploaded(TestImages::png(512, 512), 'icon.png', 'image/png'), null, null);
        $container->service(SettingsService::class)->save('en', new SettingsInput(iconId: $icon->id, themeColor: '#2a60c0'), null, null);
        $url = $container->service(SiteIcon::class)->url($icon, 'icon_180');
        $mark = '<img class="admin-brand-mark admin-brand-icon" src="' . htmlspecialchars($url) . '" alt="" width="36" height="36">';

        self::assertStringContainsString($mark, (string) $this->browser->get('/admin/login')->getBody());
        $this->actingAs($this->createUser('admin@example.com'));
        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString($mark, $page);
        self::assertStringContainsString('data-accent="blue"', $page, 'the colour still applies to the palette');
        self::assertStringContainsString('<link rel="icon" href="' . htmlspecialchars($url) . '">', $page, 'the tab shows the site icon');
        self::assertStringNotContainsString('/admin-assets/logo/', $page);
    }

    public function testUnreadableSettingsFallBackToTheAppNameAndTheGreenLogo(): void
    {
        $this->brokenSettings = true;
        $this->bootApp();

        $page = $this->browser->get('/admin/login');
        $body = (string) $page->getBody();

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<title>Sign in · Fixture</title>', $body);
        self::assertStringContainsString('data-accent="green"', $body);
        self::assertMatchesRegularExpression('~<img class="admin-brand-mark" src="/admin-assets/logo/atar-green\.svg\?v=[0-9a-f]{12}"~', $body);
    }
}
