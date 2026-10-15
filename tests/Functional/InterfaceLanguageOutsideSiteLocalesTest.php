<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

/**
 * The interface languages are the admin catalogs, not the site's content languages: an English-only site still
 * offers a Russian interface.
 */
final class InterfaceLanguageOutsideSiteLocalesTest extends FunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        $container->set(Locales::class, new Locales('en', ['en']));
    }

    public function testARussianInterfaceOnAnEnglishOnlySite(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->actingAs($ann);

        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="ru" aria-describedby="locale-help">~', $profile);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('ru', $this->user($ann)->locale);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Здравствуйте, Анна!', $dashboard);
    }

    /**
     * The admin names the site in a language the site has (the default one), not in an interface language it lacks:
     * the saved English name, not the catalog's or app.name.
     */
    public function testTheSiteNameIsTheSavedOneInAnInterfaceLanguageTheSiteLacks(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(siteName: 'Acme'), null, null);
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<html lang="ru"', $dashboard);
        self::assertMatchesRegularExpression('~<title>[^<]* · Acme</title>~u', $dashboard);
        self::assertStringContainsString('<span class="admin-brand-name">Acme</span>', $dashboard);
    }

    private function user(User $user): User
    {
        return $this->app->container()->service(UserRepository::class)->find($user->id) ?? self::fail('The user is gone');
    }
}
