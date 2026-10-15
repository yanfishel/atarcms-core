<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\LinkedLanguage;

use AtarCms\I18n\Locales;

/**
 * The linked mode: one language for the whole admin. The interface always speaks the content language of the screen:
 * the item's own language or a valid ?locale= (both remembered on a GET), else the remembered users.content_locale,
 * else the browser's language among the site's, else the site default. users.locale plays no part.
 */
final class LinkedLanguageTest extends LinkedLanguageTestCase
{
    public function testWithoutAChoiceTheSiteDefaultIsSpokenAndNothingIsStored(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<html lang="en" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Hello, Ann!', $dashboard);
        self::assertNull($this->fresh($ann)->contentLocale);
    }

    public function testTheBrowserLanguageAmongTheSiteLanguagesComesNext(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin', ['Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8'])->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr" data-accent="green">', $dashboard);
        self::assertStringContainsString('Здравствуйте, Анна!', $dashboard);
        $list = (string) $this->browser->get('/admin/content/articles', ['Accept-Language' => 'ru'])->getBody();
        self::assertStringContainsString('Привет', $list, 'the content follows the same language');
        self::assertStringNotContainsString('>Hello<', $list);
        self::assertNull($this->fresh($ann)->contentLocale, 'the browser is no choice: nothing is stored');

        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin', ['Accept-Language' => 'he'])->getBody(), 'Hebrew is no site language here: the site default');
    }

    public function testTheProfilesInterfaceLanguageIsNotUsed(): void
    {
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->users()->setLocale($ann->id, 'he');
        $this->actingAs($ann);

        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin', ['Accept-Language' => 'he'])->getBody());
        $this->users()->setContentLocale($ann->id, 'ru');
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin')->getBody());
    }

    public function testTheTopBarSwitchChangesTheInterfaceAndTheContentTogether(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->actingAs($ann);

        $target = $this->switchTo('ru', '/admin/content/articles/' . $hello->id);
        self::assertSame('/admin/content/articles/' . $privet->id . '?locale=ru', $target, 'today\'s switch: the same item\'s translation');
        self::assertSame('ru', $this->fresh($ann)->contentLocale);
        $form = (string) $this->browser->get($target)->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr"', $form);
        self::assertStringContainsString('value="Привет"', $form);

        foreach (['/admin', '/admin/media', '/admin/users', '/admin/audit', '/admin/profile'] as $page) {
            self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get($page)->getBody(), $page . ': the remembered language');
        }
        $list = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('Привет', $list);
        self::assertStringNotContainsString('>Hello<', $list);

        $this->switchTo('en', '/admin');
        self::assertStringContainsString('Hello, Анна!', (string) $this->browser->get('/admin')->getBody());
    }

    public function testOpeningAnItemOfAnotherLanguageSwitchesTheWholeScreenAndIsRemembered(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $kod = $this->term('topics', 'Код', ['locale' => 'ru']);
        $glavnaya = $this->menuItem('main', 'Главная', ['url' => '/', 'locale' => 'ru']);
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        foreach ([
            '/admin/content/articles/' . $privet->id,
            '/admin/content/articles/' . $privet->id . '/revisions',
            '/admin/content/articles/' . $privet->id . '/delete',
            '/admin/taxonomies/topics/' . $kod->id,
            '/admin/taxonomies/topics/' . $kod->id . '/delete',
            '/admin/menus/main/' . $glavnaya->id,
            '/admin/menus/main/' . $glavnaya->id . '/delete',
        ] as $page) {
            $this->users()->setContentLocale($ann->id, 'en');
            $body = (string) $this->browser->get($page)->getBody();
            self::assertStringContainsString('<html lang="ru" dir="ltr"', $body, $page);
            self::assertStringContainsString('title="Язык: Русский"', $body, $page . ': the top bar names the screen\'s language');
            self::assertSame('ru', $this->fresh($ann)->contentLocale, $page . ': remembered');
        }

        $this->users()->setContentLocale($ann->id, 'en');
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin/content/articles/' . $privet->id . '?locale=en')->getBody(), 'the item\'s own language wins over ?locale=');
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin')->getBody(), 'and the next screen keeps it');
    }

    public function testAValidLocaleQueryChoosesTheScreensLanguage(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin/content/articles?locale=ru')->getBody());
        self::assertSame('ru', $this->fresh($ann)->contentLocale);
        foreach (['xx', 'RU', '', 'ru/'] as $invalid) {
            $this->users()->setContentLocale($ann->id, 'en');
            self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/content/articles?locale=' . rawurlencode($invalid))->getBody(), $invalid);
            self::assertSame('en', $this->fresh($ann)->contentLocale, $invalid);
        }
    }

    public function testAPostSpeaksItsItemsLanguageButStoresNothing(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $ann = $this->createUser('ann@example.com');
        $this->users()->setContentLocale($ann->id, 'en');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/content/articles/' . $privet->id, ['_csrf' => $this->csrfToken(), 'title' => '', 'version' => 'stale']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $response->getBody());
        self::assertSame('en', $this->fresh($ann)->contentLocale);
    }

    /**
     * A collection that is not translatable keeps its entries in the default language only: opening one keeps the
     * remembered language, as the screens without content do.
     */
    public function testEntriesOfAnUntranslatedCollectionKeepTheRememberedLanguage(): void
    {
        $note = $this->entry('notes', 'A note', ['fields' => ['text' => 'Text']]);
        $ann = $this->createUser('ann@example.com');
        $this->users()->setContentLocale($ann->id, 'ru');
        $this->actingAs($ann);

        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin/content/notes/' . $note->id)->getBody());
        self::assertSame('ru', $this->fresh($ann)->contentLocale);
    }

    public function testAnItemTheUserMayNotOpenLendsItNoLanguage(): void
    {
        $kod = $this->term('topics', 'Код', ['locale' => 'ru']);
        $author = $this->createUser('bob@example.com', 'author');
        $this->users()->setContentLocale($author->id, 'en');
        $this->actingAs($author);

        $response = $this->browser->get('/admin/taxonomies/topics/' . $kod->id);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $response->getBody());
        self::assertSame('en', $this->fresh($author)->contentLocale);
    }

    public function testAHebrewScreenIsRightToLeftAndTheSwitchFlipsTheDirection(): void
    {
        $this->locales = new Locales('he', ['he', 'en']);
        $this->bootApp();
        $shalom = $this->entry('articles', 'שלום', ['slug' => 'shalom', 'locale' => 'he']);
        $hello = $this->translation($shalom, 'en', 'Hello', 'hello');
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($ann);

        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl"', $dashboard, 'the site default');
        self::assertStringContainsString("שלום, \u{2068}Ann\u{2069}!", $dashboard);

        $target = $this->switchTo('en', '/admin/content/articles/' . $shalom->id);
        self::assertSame('/admin/content/articles/' . $hello->id . '?locale=en', $target);
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get($target)->getBody());
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin')->getBody());

        $form = (string) $this->browser->get('/admin/content/articles/' . $shalom->id)->getBody();
        self::assertStringContainsString('<html lang="he" dir="rtl"', $form);
        self::assertStringContainsString('lang="he" dir="rtl"', $form);
    }

    /**
     * A content language without an admin catalog: the interface falls back to English (and <html> names the catalog
     * used), while the content inputs keep the content language and its direction.
     */
    public function testALanguageWithoutACatalogGivesAnEnglishInterface(): void
    {
        $this->locales = new Locales('he', ['he', 'it']);
        $this->bootApp();
        $shalom = $this->entry('articles', 'שלום', ['slug' => 'shalom', 'locale' => 'he']);
        $ciao = $this->translation($shalom, 'it', 'Ciao', 'ciao');
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($ann);

        $form = (string) $this->browser->get('/admin/content/articles/' . $ciao->id)->getBody();

        self::assertStringContainsString('<html lang="en" dir="ltr"', $form);
        self::assertStringContainsString('data-content-locale="it"', $form);
        self::assertMatchesRegularExpression('~<input[^>]*name="title"[^>]*lang="it" dir="ltr"~', $form);
        self::assertSame('it', $this->fresh($ann)->contentLocale);
        $dashboard = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<html lang="en" dir="ltr"', $dashboard);
        self::assertStringContainsString('title="Language: Italiano"', $dashboard, 'the switch still shows Italian');
        self::assertStringContainsString('Hello, Ann!', $dashboard);

        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin', ['Accept-Language' => 'it'])->getBody());
    }

    public function testSignedInErrorPagesSpeakTheRememberedLanguage(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->users()->setLocale($ann->id, 'en');
        $this->users()->setContentLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $page = $this->browser->get('/admin/nope');

        self::assertSame(404, $page->getStatusCode());
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $page->getBody());
        self::assertStringContainsString('Страница не найдена', (string) $page->getBody());
    }

    /**
     * Guests (the login page, guest admin error pages): the browser's language among the site languages the admin
     * speaks, else the site default when the admin speaks it, else English.
     */
    public function testGuestsGetTheBrowsersSiteLanguageWithACatalog(): void
    {
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin/login', ['Accept-Language' => 'ru'])->getBody());
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/login', ['Accept-Language' => 'he'])->getBody(), 'Hebrew is no site language');
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/login')->getBody());
        $error = (string) $this->browser->get('/admin/nope', ['Accept-Language' => 'ru'])->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr"', $error);
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/nope', ['Accept-Language' => 'he'])->getBody());

        $this->locales = new Locales('it', ['it', 'he']);
        $this->bootApp();
        self::assertStringContainsString('<html lang="he" dir="rtl"', (string) $this->browser->get('/admin/login', ['Accept-Language' => 'he-IL'])->getBody());
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/login', ['Accept-Language' => 'it'])->getBody(), 'Italian has no catalog: English');
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $this->browser->get('/admin/nope', ['Accept-Language' => 'it'])->getBody());

        $this->locales = new Locales('he', ['he', 'en']);
        $this->bootApp();
        self::assertStringContainsString('<html lang="he" dir="rtl"', (string) $this->browser->get('/admin/login', ['Accept-Language' => 'ru'])->getBody(), 'the site default when the admin speaks it');
    }

    /**
     * A site with one language has nothing to link: the admin behaves as before (the Profile's interface language).
     */
    public function testAOneLanguageSiteKeepsTheProfilesInterfaceLanguage(): void
    {
        $this->locales = new Locales('en', ['en']);
        $this->bootApp();
        $ann = $this->createUser('ann@example.com', 'admin', 'Анна');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $this->browser->get('/admin')->getBody());
        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringContainsString('class="admin-visually-hidden" name="locale"', $profile, 'the Profile keeps its interface language');
        self::assertStringNotContainsString('admin-content-locale', $profile, 'and no language switch, as before');
    }

    /**
     * A screen that refuses (403) may speak the requested language, but nothing is remembered.
     */
    public function testARefusedScreenRemembersNothing(): void
    {
        $ann = $this->createUser('ann@example.com');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'author' => $ann]);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $author = $this->createUser('bob@example.com', 'author');
        $this->users()->setContentLocale($author->id, 'en');
        $this->actingAs($author);

        self::assertSame(403, $this->browser->get('/admin/content/articles/' . $privet->id)->getStatusCode(), 'another author\'s entry');
        self::assertSame('en', $this->fresh($author)->contentLocale);
        self::assertSame(403, $this->browser->get('/admin/users?locale=ru')->getStatusCode());
        self::assertSame('en', $this->fresh($author)->contentLocale);
        self::assertSame(200, $this->browser->get('/admin/content/articles?locale=ru')->getStatusCode());
        self::assertSame('ru', $this->fresh($author)->contentLocale, 'a screen that answers remembers');
    }

    /**
     * A form without an item of its own re-renders in the language it was filled in, remembering nothing.
     */
    public function testARefusedNewFormSpeaksItsPostedLanguage(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->users()->setContentLocale($ann->id, 'en');
        $this->actingAs($ann);

        $response = $this->browser->post('/admin/content/articles', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'title' => '']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<html lang="ru" dir="ltr"', (string) $response->getBody());
        self::assertStringContainsString('title="Язык: Русский"', (string) $response->getBody());
        self::assertSame('en', $this->fresh($ann)->contentLocale);
    }
}
