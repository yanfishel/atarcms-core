<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The top bar's content language switch: on every admin page, the site's languages named in themselves as plain POST
 * forms; switching remembers the language and keeps the screen (ContentLocaleSwitch).
 */
final class ContentLocaleSwitchTest extends ContentFunctionalTestCase
{
    public function testEveryAdminPageHasTheSwitchWithTheSiteLanguagesNamedInThemselves(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        foreach (['/admin', '/admin/media', '/admin/users?page=1', '/admin/profile'] as $page) {
            $topbar = self::topbar((string) $this->browser->get($page)->getBody());
            self::assertMatchesRegularExpression(
                '~<details class="admin-menu admin-content-locale" data-menu>\s*<summary class="admin-menu-trigger" title="Content language: English" aria-label="Content language: English"><svg[^>]*><use href="[^"]+#languages"></use></svg><span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span></summary>~',
                $topbar,
                $page,
            );
            self::assertSame(['en' => true, 'ru' => false], self::choices($topbar, $this->csrfToken(), $page), $page);
        }
        $topbar = self::topbar((string) $this->browser->get('/admin')->getBody());
        // The name carries its language; the item itself follows the interface, so every item lines up the same way.
        self::assertMatchesRegularExpression('~<button type="submit" class="admin-menu-item" aria-current="true"><span lang="en" dir="ltr">English</span><svg[^>]*><use href="[^"]+#check"></use></svg></button>~', $topbar);
        self::assertStringContainsString('<button type="submit" class="admin-menu-item"><span lang="ru" dir="ltr">Русский</span></button>', $topbar);
    }

    public function testThePageLanguageIsMarkedAsTheCurrentOne(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        self::assertSame(['en' => false, 'ru' => true], self::choices(self::topbar((string) $this->browser->get('/admin/content/articles?locale=ru')->getBody()), $this->csrfToken(), 'list'));
        self::assertSame(['en' => false, 'ru' => true], self::choices(self::topbar((string) $this->browser->get('/admin')->getBody()), $this->csrfToken(), 'remembered'));
    }

    public function testVersionsAndDeletePagesMarkTheLanguageOfTheirItem(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $kod = $this->term('topics', 'Код', ['locale' => 'ru']);
        $glavnaya = $this->menuItem('main', 'Главная', ['url' => '/', 'locale' => 'ru']);
        $this->actingAs($this->createUser('ann@example.com'));

        foreach ([
            '/admin/content/articles/' . $privet->id . '/revisions',
            '/admin/content/articles/' . $privet->id . '/delete',
            '/admin/taxonomies/topics/' . $kod->id . '/delete',
            '/admin/menus/main/' . $glavnaya->id . '/delete',
        ] as $page) {
            $body = (string) $this->browser->get($page)->getBody();
            self::assertSame(['en' => false, 'ru' => true], self::choices(self::topbar($body), $this->csrfToken(), $page), $page);
            self::assertMatchesRegularExpression('~<h1>[^<]+ <span class="admin-locale-badge" title="Content language: Русский" aria-hidden="true">RU</span>~u', $body, $page);
        }
    }

    public function testAScreenInOneLanguageLeavesTheSwitchOnTheRememberedLanguage(): void
    {
        $note = $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);
        $this->actingAs($this->createUser('ann@example.com'));
        $this->switchTo('ru', '/admin');

        foreach (['/admin/content/notes', '/admin/content/notes/' . $note->id, '/admin/content/notes/' . $note->id . '/delete'] as $page) {
            $body = (string) $this->browser->get($page)->getBody();
            self::assertSame(['en' => false, 'ru' => true], self::choices(self::topbar($body), $this->csrfToken(), $page), $page);
            self::assertStringNotContainsString('<h1>Note <span class="admin-locale-badge"', $body, $page);
        }
    }

    public function testSwitchingRemembersTheLanguageAndReloadsAListWithoutItsLanguageBoundParameters(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $response = $this->switchTo('ru', '/admin/content/articles?locale=en&status=draft&q=x&page=3&term=5&sort=title&dir=asc');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/content/articles?status=draft&q=x&sort=title&dir=asc', $response->getHeaderLine('Location'));
        self::assertSame('ru', $this->user($ann)->contentLocale);
        self::assertSame([], $this->auditActions(), 'a personal preference is not audited');

        foreach ([
            '/admin' => '/admin',
            '/admin/media?folder=3&view=list' => '/admin/media?folder=3&view=list',
            '/admin/sections?locale=en' => '/admin/sections',
            '/admin/menus/main?locale=en' => '/admin/menus/main',
            '/admin/settings?locale=en' => '/admin/settings',
            '/admin/content/articles/new?locale=en' => '/admin/content/articles/new',
            '/admin/taxonomies/topics/new?locale=en&parent=4' => '/admin/taxonomies/topics/new',
            '/admin/content/pages/order?locale=en' => '/admin/content/pages/order',
        ] as $return => $location) {
            self::assertSame($location, $this->switchTo('en', $return)->getHeaderLine('Location'), $return);
        }
    }

    public function testTheReturnAddressIsAnAdminPageOfThisSiteOnly(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        foreach (['https://evil.example/admin', '//evil.example/admin', '/\\evil.example', '/%2F%2Fevil.example', '/news/hello', '/administrator', '', 'admin', '/admin/sections/order', '/admin/nope'] as $return) {
            self::assertSame('/admin', $this->switchTo('ru', $return)->getHeaderLine('Location'), $return);
        }
        self::assertSame('/admin', $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'locale' => 'ru'])->getHeaderLine('Location'), 'none');
    }

    public function testAnUnknownLanguageIsRefusedAndNothingIsStored(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        foreach (['de', '', 'RU'] as $locale) {
            self::assertSame(422, $this->switchTo($locale, '/admin')->getStatusCode(), $locale);
        }
        self::assertSame(422, $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'return' => '/admin'])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/preferences/content-locale', ['locale' => 'ru', 'return' => '/admin'])->getStatusCode());
        self::assertNull($this->user($ann)->contentLocale);
    }

    public function testAnEntryFormGoesToItsTranslationOrToTheListOfTheNewLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $alone = $this->entry('articles', 'Alone', ['slug' => 'alone']);
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertSame('/admin/content/articles/' . $privet->id . '?locale=ru', $this->switchTo('ru', '/admin/content/articles/' . $hello->id)->getHeaderLine('Location'));
        self::assertSame('/admin/content/articles/' . $hello->id . '?locale=en', $this->switchTo('en', '/admin/content/articles/' . $privet->id . '/revisions')->getHeaderLine('Location'));
        self::assertSame('/admin/content/articles?locale=ru&translate=' . $alone->id, $this->switchTo('ru', '/admin/content/articles/' . $alone->id)->getHeaderLine('Location'));
        self::assertSame('/admin/content/articles/' . $alone->id, $this->switchTo('en', '/admin/content/articles/' . $alone->id)->getHeaderLine('Location'), 'its own language');
        self::assertSame('/admin/content/notes/999', $this->switchTo('ru', '/admin/content/notes/999')->getHeaderLine('Location'), 'no such entry');
        self::assertSame('/admin/content/pages/' . $hello->id, $this->switchTo('ru', '/admin/content/pages/' . $hello->id)->getHeaderLine('Location'), 'an entry of another collection');
    }

    public function testATranslationTheUserCannotEditIsNotLinked(): void
    {
        $bob = $this->createUser('bob@example.com', 'author');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'author' => $bob]);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($bob);

        self::assertSame('/admin/content/articles?locale=ru&translate=' . $hello->id, $this->switchTo('ru', '/admin/content/articles/' . $hello->id)->getHeaderLine('Location'));
    }

    public function testASingleStaysOnItsFormToOfferTheTranslation(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertSame('/admin/content/contact/' . $contact->id . '?translate=ru', $this->switchTo('ru', '/admin/content/contact/' . $contact->id)->getHeaderLine('Location'));
    }

    public function testATermOrSectionFormGoesToItsTranslationOrToItsList(): void
    {
        $code = $this->term('topics', 'Code');
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertSame('/admin/taxonomies/topics?locale=ru&translate=' . $code->id, $this->switchTo('ru', '/admin/taxonomies/topics/' . $code->id)->getHeaderLine('Location'));
        self::assertSame('/admin/sections?locale=ru&translate=' . $news->id, $this->switchTo('ru', '/admin/sections/' . $news->id)->getHeaderLine('Location'));
        self::assertSame('/admin/taxonomies/tags/' . $code->id, $this->switchTo('ru', '/admin/taxonomies/tags/' . $code->id)->getHeaderLine('Location'), 'a term of another taxonomy');

        $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $kod = $this->terms()->translations($code->translationGroup);
        $ru = array_values(array_filter($kod, static fn($term): bool => $term->locale === 'ru'))[0];
        self::assertSame('/admin/taxonomies/topics/' . $ru->id . '?locale=ru', $this->switchTo('ru', '/admin/taxonomies/topics/' . $code->id . '/delete')->getHeaderLine('Location'));
    }

    public function testWithoutTheRightToManageTermsNothingIsLookedUp(): void
    {
        $code = $this->term('topics', 'Code');
        // The translation exists, yet a user without the right to manage terms may not learn its id.
        $this->app->container()->service(\AtarCms\Content\TermService::class)->createTranslation($code, 'ru', null, null);
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertSame('/admin/taxonomies/topics/' . $code->id, $this->switchTo('ru', '/admin/taxonomies/topics/' . $code->id)->getHeaderLine('Location'));
        self::assertSame('/admin/sections/' . $code->id, $this->switchTo('ru', '/admin/sections/' . $code->id)->getHeaderLine('Location'));
    }

    public function testAMenuItemFormGoesToItsMenu(): void
    {
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $this->actingAs($this->createUser('ann@example.com'));

        self::assertSame('/admin/menus/main?locale=ru', $this->switchTo('ru', '/admin/menus/main/' . $home->id)->getHeaderLine('Location'));
    }

    public function testTheListOffersToCreateTheMissingTranslation(): void
    {
        $alone = $this->entry('articles', 'Alone <b>', ['slug' => 'alone']);
        $this->actingAs($this->createUser('ann@example.com'));

        $list = (string) $this->browser->get($this->switchTo('ru', '/admin/content/articles/' . $alone->id)->getHeaderLine('Location'))->getBody();

        self::assertMatchesRegularExpression(
            '~<div class="admin-toast admin-toast-info" data-toast><svg[^>]*><use href="[^"]+#languages"></use></svg><div class="admin-toast-body"><p class="admin-toast-text">There is no Русский version of “Alone &lt;b&gt;” yet\.</p>'
            . '<form class="admin-toast-form" method="post" action="/admin/content/articles/' . $alone->id . '/translate"><input type="hidden" name="_csrf" value="' . preg_quote($this->csrfToken(), '~') . '"><input type="hidden" name="locale" value="ru"><button type="submit" class="admin-button admin-button-primary admin-button-compact" aria-label="Translate \(machine translation\)" title="Translate \(machine translation\)" name="machine" value="1" data-machine-offer data-machine-from="en" data-machine-to="ru" hidden><svg[^>]*>.*?</svg>Translate</button><button type="submit" class="admin-button admin-button-primary admin-button-compact admin-toast-copy" aria-label="Copy for translation" title="Copy for translation"><svg[^>]*>.*?</svg>Copy</button></form></div>~',
            $list,
        );
        $created = $this->browser->post('/admin/content/articles/' . $alone->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        self::assertSame(303, $created->getStatusCode());

        $again = (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $alone->id)->getBody();
        self::assertStringNotContainsString('admin-toast-info', $again, 'the translation exists now');
        foreach (['abc', '0', '999999', (string) $this->entry('pages', 'Page', ['slug' => 'page'])->id] as $forged) {
            self::assertStringNotContainsString('admin-toast-info', (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $forged)->getBody(), $forged);
        }
    }

    public function testWithoutTheRightToTranslateTheOfferIsOnlyText(): void
    {
        $bob = $this->createUser('bob@example.com', 'author');
        $foreign = $this->entry('articles', 'Foreign', ['slug' => 'foreign']);
        $this->actingAs($bob);

        $list = (string) $this->browser->get('/admin/content/articles?locale=ru&translate=' . $foreign->id)->getBody();

        self::assertStringContainsString('There is no Русский version of “Foreign” yet.', $list);
        self::assertStringNotContainsString('admin-toast-form', $list);
    }

    public function testASingleOpensTheEntryOfTheContentLanguageOrOffersItsTranslation(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        self::assertSame('/admin/content/contact/' . $contact->id . '?translate=ru', $this->browser->get('/admin/content/contact?locale=ru')->getHeaderLine('Location'));
        $form = (string) $this->browser->get('/admin/content/contact/' . $contact->id . '?translate=ru')->getBody();
        self::assertStringContainsString('There is no Русский version of “Contact” yet.', $form);
        self::assertStringContainsString('<form class="admin-toast-form" method="post" action="/admin/content/contact/' . $contact->id . '/translate">', $form);

        $this->browser->post('/admin/content/contact/' . $contact->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $kontakt = array_values(array_filter($this->app->container()->service(\AtarCms\Content\EntryRepository::class)->translations($contact->translationGroup), static fn($entry): bool => $entry->locale === 'ru'))[0];
        self::assertSame('/admin/content/contact/' . $kontakt->id, $this->browser->get('/admin/content/contact')->getHeaderLine('Location'), 'the remembered language');
    }

    public function testAForgedLanguageOffersNothing(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $this->actingAs($this->createUser('ann@example.com'));

        foreach (['de', 'RU', '<b>x</b>', 'ru/'] as $forged) {
            $form = (string) $this->browser->get('/admin/content/contact/' . $contact->id . '?translate=' . rawurlencode($forged))->getBody();
            self::assertStringNotContainsString('admin-toast-info', $form, $forged);
        }
    }

    public function testTheTermAndSectionListsOfferTheTranslation(): void
    {
        $code = $this->term('topics', 'Code');
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ann@example.com'));

        $topics = (string) $this->browser->get('/admin/taxonomies/topics?locale=ru&translate=' . $code->id)->getBody();
        self::assertStringContainsString('There is no Русский version of “Code” yet.', $topics);
        self::assertStringContainsString('<form class="admin-toast-form" method="post" action="/admin/taxonomies/topics/' . $code->id . '/translate">', $topics);

        $sections = (string) $this->browser->get('/admin/sections?locale=ru&translate=' . $news->id)->getBody();
        self::assertStringContainsString('There is no Русский version of “News” yet.', $sections);
        self::assertStringContainsString('<form class="admin-toast-form" method="post" action="/admin/sections/' . $news->id . '/translate">', $sections);
        self::assertStringNotContainsString('admin-toast-info', (string) $this->browser->get('/admin/sections?locale=ru&translate=' . $code->id)->getBody(), 'a term of another taxonomy');
    }

    private function switchTo(string $locale, string $return): ResponseInterface
    {
        return $this->browser->post('/admin/preferences/content-locale', ['_csrf' => $this->csrfToken(), 'locale' => $locale, 'return' => $return]);
    }

    private static function topbar(string $body): string
    {
        $start = strpos($body, '<header class="admin-topbar">');
        $end = strpos($body, '</header>');

        return $start === false || $end === false ? '' : substr($body, $start, $end - $start);
    }

    /**
     * @return array<string, bool> locale => whether it is marked as the current one
     */
    private static function choices(string $topbar, string $token, string $message): array
    {
        preg_match('~name="return" value="([^"]*)"~', $topbar, $return);
        preg_match_all(
            '~<form class="admin-menu-form" method="post" action="/admin/preferences/content-locale">\s*<input type="hidden" name="_csrf" value="' . preg_quote($token, '~') . '">\s*<input type="hidden" name="locale" value="([a-z]+)">\s*<input type="hidden" name="return" value="[^"]*">\s*<button type="submit" class="admin-menu-item"( aria-current="true")?><span lang="\1" dir="ltr">~',
            $topbar,
            $forms,
        );
        self::assertNotEmpty($return, $message);

        return array_combine($forms[1], array_map(static fn(string $current): bool => $current !== '', $forms[2]));
    }

    private function user(User $user): User
    {
        return $this->app->container()->service(UserRepository::class)->find($user->id) ?? self::fail('The user is gone');
    }
}
