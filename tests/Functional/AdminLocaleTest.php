<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\UserRepository;

final class AdminLocaleTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        putenv('APP_LOCALE=ru');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_LOCALE');
        parent::tearDown();
    }

    public function testLoginPageAndLoginErrorsUseTheDefaultLocale(): void
    {
        $page = (string) $this->browser->get('/admin/login?error=disabled')->getBody();

        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $page);
        self::assertStringContainsString('<h1>Вход</h1>', $page);
        self::assertStringContainsString('Ваша учётная запись отключена.', $page);
    }

    public function testShellAndUsersScreenAreTranslated(): void
    {
        $admin = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->createUser('bob@example.com', 'editor');
        $this->createUser('eve@example.com', 'author');
        $this->actingAs($admin);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('Здравствуйте, Анна!', $dashboard);
        self::assertMatchesRegularExpression(self::navLink('/admin/users', 'Пользователи'), $dashboard);
        self::assertStringContainsString('>Выйти</button>', $dashboard);

        $users = (string) $this->browser->get('/admin/users')->getBody();
        self::assertStringContainsString('3 пользователя', $users);
        self::assertStringContainsString('Активен', $users);
    }

    public function testAUserWhoChoseEnglishSeesTheAdminInEnglishAndTheSiteInItsDefault(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'en');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Hello, Ann!', $dashboard);
        self::assertStringContainsString('>Sign out</button>', $dashboard);

        $home = (string) $this->browser->get('/')->getBody();
        self::assertStringContainsString('<html lang="ru">', $home);
        self::assertStringContainsString('<p class="greeting">Привет</p>', $home);
    }

    public function testValidationErrorsAreTranslated(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->post('/admin/users', ['_csrf' => $this->csrfToken(), 'email' => 'nope', 'role' => 'ghost']);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Введите корректный адрес почты.', $body);
        self::assertStringContainsString('Выберите одну из настроенных ролей.', $body);
    }

    public function testAdminErrorPageIsTranslated(): void
    {
        self::assertStringContainsString('404 Страница не найдена', (string) $this->browser->get('/admin/nope')->getBody());
    }

    /**
     * ErrorMiddleware renders a 403 or 404 with the request from before AdminLocale; the admin error renderer looks the
     * signed-in user up again, so the page speaks their interface language. Public error pages keep the site's.
     */
    public function testAdminErrorPagesSpeakTheUsersInterfaceLanguage(): void
    {
        $ann = $this->createUser('ann@example.com', 'author', 'Ann');
        $this->app->container()->service(UserRepository::class)->setLocale($ann->id, 'en');
        $this->actingAs($ann);

        $missing = $this->browser->get('/admin/nope');
        $forbidden = $this->browser->get('/admin/users');

        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', (string) $missing->getBody());
        self::assertStringContainsString('<h1>Not Found</h1>', (string) $missing->getBody());
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertStringContainsString('<h1>Forbidden</h1>', (string) $forbidden->getBody());
        self::assertStringContainsString('<html lang="ru">', (string) $this->browser->get('/nope')->getBody(), 'a public error page keeps the site language');
    }

    public function testAnAdminErrorPageOfAUserWithoutAChoiceUsesTheSiteLanguage(): void
    {
        $this->actingAs($this->createUser('bob@example.com', 'author'));

        self::assertStringContainsString('<h1>Страница не найдена</h1>', (string) $this->browser->get('/admin/nope')->getBody());
    }
}
