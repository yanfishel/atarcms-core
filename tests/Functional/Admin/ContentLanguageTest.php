<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Database\Connection;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The content language of the admin screens (lists, "Add new", the locale tabs of terms and menus) defaults to the
 * user's interface language and then follows the last language the user switched to (users.content_locale).
 */
final class ContentLanguageTest extends ContentFunctionalTestCase
{
    public function testTheInterfaceLanguageIsTheDefaultAndASwitchIsRemembered(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->term('topics', 'Code');
        $this->term('topics', 'Код', ['locale' => 'ru']);
        $this->menuItem('main', 'Home', ['url' => '/']);
        $this->menuItem('main', 'Главная', ['url' => '/ru', 'locale' => 'ru']);
        $ann = $this->createUser('ann@example.com');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $russian = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('Привет', $russian, 'the interface language is the default content language');
        self::assertStringNotContainsString('>Hello<', $russian);
        self::assertStringContainsString('href="/admin/content/articles/new?locale=ru"', $russian);
        self::assertStringContainsString('Код', (string) $this->browser->get('/admin/taxonomies/topics')->getBody());
        self::assertStringContainsString('Главная', (string) $this->browser->get('/admin/menus/main')->getBody());
        self::assertNull($this->user($ann)->contentLocale, 'nothing is stored without a switch');

        $this->browser->get('/admin/content/articles?locale=en');
        self::assertSame('en', $this->user($ann)->contentLocale);

        $english = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('>Hello<', $english);
        self::assertStringNotContainsString('Привет', $english);
        self::assertStringContainsString('href="/admin/content/articles/new?locale=en"', $english);
        $terms = (string) $this->browser->get('/admin/taxonomies/topics')->getBody();
        self::assertStringContainsString('Code', $terms);
        self::assertStringNotContainsString('Код', $terms);
        $menu = (string) $this->browser->get('/admin/menus/main')->getBody();
        self::assertStringContainsString('Home', $menu);
        self::assertStringNotContainsString('Главная', $menu);
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $menu, 'the interface language stays');

        $this->browser->get('/admin/menus/main?locale=ru');
        self::assertSame('ru', $this->user($ann)->contentLocale, 'the locale tabs of every screen switch it');
        self::assertStringContainsString('Привет', (string) $this->browser->get('/admin/content/articles')->getBody());
    }

    public function testTheFormSwitchRemembersTheLanguageOfTheTranslation(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $code = $this->term('topics', 'Code');
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $form = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();
        self::assertStringContainsString('<a href="/admin/content/articles/' . $privet->id . '?locale=ru" hreflang="ru"', $form, 'the Translations line links the translation');
        self::assertNull($this->user($ann)->contentLocale, 'opening an entry without ?locale= stores nothing');

        self::assertSame(200, $this->browser->get('/admin/content/articles/' . $privet->id . '?locale=ru')->getStatusCode());
        self::assertSame('ru', $this->user($ann)->contentLocale);
        self::assertStringContainsString('Привет', (string) $this->browser->get('/admin/content/articles')->getBody());

        $this->browser->get('/admin/content/articles/' . $privet->id . '?locale=en');
        self::assertSame('ru', $this->user($ann)->contentLocale, 'a ?locale= other than the entry\'s own is ignored');
        $this->browser->get('/admin/content/articles/' . $hello->id . '?locale=ru');
        self::assertSame('ru', $this->user($ann)->contentLocale, 'a forged ?locale= on an English entry is ignored');

        $translated = $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $this->browser->get('/admin/taxonomies/topics?locale=en');
        self::assertSame('en', $this->user($ann)->contentLocale);
        $this->browser->get($translated->getHeaderLine('Location'));
        self::assertSame('ru', $this->user($ann)->contentLocale, 'the new translation\'s edit page switches to its language');
        $this->browser->get('/admin/taxonomies/topics/' . $code->id . '?locale=en');
        self::assertSame('en', $this->user($ann)->contentLocale, 'the term switch link to the English term');
    }

    public function testTheBrowsersInterfaceLanguageIsTheDefaultToo(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $russian = (string) $this->browser->get('/admin/content/articles', ['Accept-Language' => 'ru'])->getBody();

        self::assertStringContainsString('Привет', $russian, 'the interface language the admin speaks, not only a saved one');
        self::assertNull($this->user($ann)->contentLocale);
    }

    public function testAnUnsupportedLanguageFallsBackAndStoresNothing(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $ann = $this->createUser('ann@example.com');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        foreach (['xx', 'RU', '', 'ru/'] as $invalid) {
            self::assertStringContainsString('Привет', (string) $this->browser->get('/admin/content/articles?locale=' . rawurlencode($invalid))->getBody(), $invalid);
            self::assertNull($this->user($ann)->contentLocale, $invalid);
        }
    }

    public function testAStaleStoredLanguageIsIgnored(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $ann = $this->createUser('ann@example.com');
        $this->app->container()->service(Connection::class)->execute('UPDATE users SET content_locale = ? WHERE id = ?', ['de', $ann->id]);
        $this->actingAs($ann);

        $body = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertStringContainsString('>Hello<', $body);
        self::assertStringContainsString('href="/admin/content/articles/new?locale=en"', $body);
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
