<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;

/**
 * Each user picks the language of the admin interface (users.locale); AdminLocale applies it on admin routes only.
 * The fixture site speaks English by default and Russian under /ru.
 */
final class InterfaceLanguageTest extends FunctionalTestCase
{
    public function testAUserWithRussianSeesTheAdminInRussianWhilePublicPagesKeepTheirLocale(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Здравствуйте, Анна!', $dashboard);
        self::assertMatchesRegularExpression(self::navLink('/admin/users', 'Пользователи'), $dashboard);
        self::assertStringContainsString('<li><span aria-current="page">Обзор</span></li>', $dashboard, 'the dashboard crumb');
        foreach (['light' => 'Светлая', 'dark' => 'Тёмная', 'system' => 'Системная'] as $choice => $label) {
            self::assertMatchesRegularExpression('~value="' . $choice . '" data-theme-choice[^>]*><svg[^>]*>.*?</svg>' . $label . '<svg[^>]*><use href="[^"]+#check"></use></svg></label>~', $dashboard, $choice);
        }

        $users = (string) $this->browser->get('/admin/users')->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $users);
        self::assertStringContainsString('Активен', $users);
        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-admin">Администратор</span>', $users, 'role labels are translated');
    }

    public function testRoleLabelsSpeakTheInterfaceLanguage(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->createUser('bob@example.com', 'editor');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<p class="admin-user-meta">Администратор</p>', $dashboard);
        self::assertStringNotContainsString('Administrator', $dashboard);

        self::assertStringContainsString('<span class="admin-chip admin-role admin-role-editor">Редактор</span>', (string) $this->browser->get('/admin/users')->getBody());
        $form = (string) $this->browser->get('/admin/users/new')->getBody();
        self::assertStringContainsString('<option value="editor">Редактор</option>', $form);
        self::assertStringContainsString('<option value="author">Автор</option>', $form);

        $home = (string) $this->browser->get('/')->getBody();
        self::assertStringContainsString('<html lang="en">', $home, 'a public page keeps the locale of its URL');
        self::assertStringContainsString('<p class="greeting">Hello</p>', $home);
        self::assertStringContainsString('<p class="greeting">Привет</p>', (string) $this->browser->get('/ru')->getBody());
    }

    public function testWithoutAChoiceTheAdminSpeaksTheSiteDefault(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann'));

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Hello, Ann!', $dashboard);
    }

    public function testWithoutAChoiceTheBrowserLanguageIsSpokenAndNothingIsStored(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin', ['Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8'])->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Здравствуйте, Анна!', $dashboard);
        self::assertNull($this->user($ann)->locale);

        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', (string) $this->browser->get('/admin', ['Accept-Language' => 'it, es'])->getBody(), 'no catalog: the site default');
        self::assertStringContainsString('404', (string) $this->browser->get('/admin/nope', ['Accept-Language' => 'ru'])->getBody());
        self::assertStringContainsString('Страница не найдена', (string) $this->browser->get('/admin/nope', ['Accept-Language' => 'ru'])->getBody(), 'error pages too');
    }

    public function testASavedChoiceWinsOverTheBrowser(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->users()->setLocale($ann->id, 'en');
        $this->actingAs($ann);

        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', (string) $this->browser->get('/admin', ['Accept-Language' => 'ru'])->getBody());
    }

    public function testTheTopBarHasNoInterfaceLanguageMenuAndTheAccountMenuLinksTheProfile(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringNotContainsString('/admin/preferences/locale', $dashboard);
        self::assertStringNotContainsString('Interface language', $dashboard);
        self::assertMatchesRegularExpression('~<a class="admin-menu-item admin-menu-item-icon" href="/admin/profile"><svg[^>]*>.*?</svg>Profile</a>~', $dashboard);
    }

    public function testTheProfileOffersTheInterfaceLanguagesNamedInThemselves(): void
    {
        $ann = $this->createUser('ann@example.com', 'editor', 'Ann');
        $this->actingAs($ann);

        $page = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('<h1>Profile</h1>', $page);
        self::assertStringContainsString('ann@example.com', $page);
        self::assertStringContainsString('<form class="admin-profile-form" method="post" action="/admin/profile">', $page);
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="en" checked aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="en" dir="ltr">English</span>~', $page, 'the current language is checked');
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="ru" aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="ru" dir="ltr">Русский</span>~', $page);

        $this->users()->setLocale($ann->id, 'ru');
        $russian = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('<h1>Профиль</h1>', $russian);
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="ru" checked aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="ru" dir="ltr">Русский</span>~', $russian);
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="en" aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="en" dir="ltr">English</span>~', $russian, 'each language is named in itself');
        self::assertMatchesRegularExpression('~<input type="radio" class="admin-visually-hidden" name="locale" value="he" aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="he" dir="rtl">עברית</span>~', $russian, 'Hebrew, written right to left');
    }

    public function testHebrewCanBeChosen(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'he']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('he', $this->user($ann)->locale);
        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('<h1>פרופיל</h1>', $profile);
        self::assertStringContainsString('ההעדפות נשמרו.', $profile);
    }

    public function testChoosingALanguageSavesItAndReturnsToTheProfile(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'], ['Referer' => 'https://localhost/admin/users?page=2']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/profile', $response->getHeaderLine('Location'));
        self::assertSame('ru', $this->user($ann)->locale);
        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $profile);
        self::assertStringContainsString('Настройки сохранены.', $profile);
        self::assertSame([], $this->auditActions(), 'a personal preference is not audited');
    }

    public function testAnUnknownLanguageIsRefused(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        foreach (['locale' => 'it', 'empty' => '', 'upper case' => 'RU'] as $case => $locale) {
            $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => $locale]);

            self::assertSame(422, $response->getStatusCode(), $case);
            self::assertNull($this->user($ann)->locale, $case);
        }
        self::assertSame(422, $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken()])->getStatusCode(), 'missing');
        self::assertSame(419, $this->browser->post('/admin/profile', ['locale' => 'ru'])->getStatusCode(), 'without the CSRF token');
        self::assertNull($this->user($ann)->locale);
    }

    private function users(): UserRepository
    {
        return $this->app->container()->service(UserRepository::class);
    }

    private function user(User $user): User
    {
        return $this->users()->find($user->id) ?? self::fail('The user is gone');
    }
}
