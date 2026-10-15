<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\LinkedLanguage;

use AtarCms\Admin\LanguageMode;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Tests\Support\Regex;

/**
 * The linked mode's screens: only the top bar changes the language. No Translations line on the entry, term and
 * section forms or the term dialog, plain flags in the entry lists only, no interface language on the Profile; a single without
 * the language's version opens "Add new" in that language with the offer to translate. The separate mode keeps all of
 * it (the older suites, and the checks here).
 */
final class LinkedScreensTest extends LinkedLanguageTestCase
{
    public function testFormsHaveNoTranslationsLine(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $code = $this->term('topics', 'Code');
        $section = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ann@example.com'));

        foreach ([
            '/admin/content/articles/' . $hello->id => [],
            '/admin/taxonomies/topics/' . $code->id => [],
            '/admin/taxonomies/topics/' . $code->id . ' (dialog)' => ['HX-Request' => 'true'],
            '/admin/sections/' . $section->id => [],
        ] as $page => $headers) {
            $response = $this->browser->get(explode(' ', $page)[0], $headers);
            self::assertSame(200, $response->getStatusCode(), $page);
            $body = (string) $response->getBody();
            self::assertStringNotContainsString('admin-translations', $body, $page);
            self::assertStringNotContainsString('/translate"', $body, $page . ': no "create a translation" form either');
        }
    }

    public function testTheSeparateModeKeepsTheTranslationsLine(): void
    {
        $this->mode = LanguageMode::Separate;
        $this->bootApp();
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($this->createUser('ann@example.com'));

        $form = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();

        self::assertStringContainsString('<nav class="admin-translations"', $form);
        self::assertStringContainsString('<a href="/admin/content/articles/' . $privet->id . '?locale=ru" hreflang="ru"', $form);
        self::assertStringContainsString('title="Content language: English"', $form);
        self::assertStringContainsString('class="admin-visually-hidden" name="locale"', (string) $this->browser->get('/admin/profile')->getBody());
    }

    /**
     * The linked mode's entry lists keep the Languages column as plain flags of the translations that exist (user
     * decision, reversing Plan 37's removal for entries: never links, only the top bar switches the language); the
     * term lists have none. The separate mode keeps it on both.
     */
    public function testTheEntryListsHavePlainLanguageFlagsAndTheTermListsNone(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('pages', 'About', ['slug' => 'about']);
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ann@example.com'));

        $articles = (string) $this->browser->get('/admin/content/articles')->getBody();
        self::assertStringContainsString('<th>Languages</th>', $articles);
        $cell = Regex::match('~<td data-label="Languages">(.*?)</td>~s', $articles);
        self::assertStringContainsString('<span class="admin-flags">', $cell[1]);
        self::assertStringContainsString('title="Русский"', $cell[1], 'the existing translation');
        self::assertStringNotContainsString('href', $cell[1], 'plain flags, never links');
        self::assertStringNotContainsString('?locale=ru', $articles, 'no link into the other language');
        self::assertStringContainsString('<th>Languages</th>', (string) $this->browser->get('/admin/content/pages')->getBody(), 'pages too');

        $topics = (string) $this->browser->get('/admin/taxonomies/topics')->getBody();
        self::assertStringNotContainsString('Languages', $topics, 'no column on the term lists');
        self::assertStringNotContainsString('admin-flags', $topics);

        $this->mode = LanguageMode::Separate;
        $this->bootApp();
        $this->actingAs($this->createUser('bob@example.com'));
        foreach (['/admin/content/articles', '/admin/taxonomies/topics'] as $page) {
            $body = (string) $this->browser->get($page)->getBody();
            $cell = Regex::match('~<td data-label="Languages">(.*?)</td>~s', $body, $page);
            self::assertStringContainsString('<span class="admin-flags">', $cell[1], $page);
            self::assertStringNotContainsString('href', $cell[1], $page);
        }
        $this->actingAs($this->createUser('cid@example.com'));
        self::assertStringNotContainsString('/admin/content/articles/' . $privet->id, (string) $this->browser->get('/admin/content/articles')->getBody());
        self::assertStringContainsString('/admin/taxonomies/topics/' . $code->id, (string) $this->browser->get('/admin/taxonomies/topics')->getBody());
    }

    public function testTheTopBarIsTheLanguageSwitch(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $dashboard = (string) $this->browser->get('/admin')->getBody();

        self::assertStringContainsString('<summary class="admin-menu-trigger" title="Language: English" aria-label="Language: English">', $dashboard);
        self::assertStringContainsString('action="/admin/preferences/content-locale"', $dashboard);
        self::assertStringNotContainsString('Content language: English', $dashboard, 'the pills of the content keep their name; the dashboard has none');
    }

    public function testTheProfileHasNoInterfaceLanguageAndIgnoresAPostedOne(): void
    {
        $ann = $this->createUser('ann@example.com');
        $this->users()->setLocale($ann->id, 'ru');
        $this->actingAs($ann);

        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertStringNotContainsString('class="admin-visually-hidden" name="locale"', $profile);
        self::assertStringNotContainsString('Interface language', $profile);
        self::assertStringContainsString('name="accent"', $profile, 'the Interface tab keeps the admin colour');

        $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'he', 'accent' => 'blue']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('ru', $this->fresh($ann)->locale, 'users.locale stays as it is');
        self::assertSame('blue', $this->fresh($ann)->accent?->value);
        self::assertSame(303, $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => 'xx', 'accent' => ''])->getStatusCode(), 'an unknown one is ignored too');
        self::assertSame(422, $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'accent' => 'pink'])->getStatusCode(), 'the colour is still checked');
        self::assertStringContainsString('<html lang="en"', (string) $this->browser->get('/admin/profile')->getBody());
    }

    /**
     * The top bar's switch on a single that lacks the language: today's form of the default language with the offer
     * would speak the default language again, so the linked mode creates the translation right away (the toast's
     * "Create translation") and opens its form, linked to the original.
     */
    public function testTheSwitchCreatesASinglesMissingTranslation(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '123']]);
        $ann = $this->createUser('ann@example.com');
        $this->actingAs($ann);

        $target = $this->switchTo('ru', '/admin/content/contact/' . $contact->id);

        $translations = $this->entries()->translations($contact->translationGroup);
        self::assertCount(2, $translations, 'the translation joins the original\'s group');
        $russian = array_values(array_filter($translations, static fn(Entry $e): bool => $e->locale === 'ru'))[0];
        self::assertSame('/admin/content/contact/' . $russian->id . '?locale=ru&machine=en', $target, 'the form offers the machine translation (Plan 38)');
        $form = (string) $this->browser->get($target)->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr"', $form);
        self::assertStringContainsString('value="123"', $form, 'copied from the original');
        self::assertSame('ru', $this->fresh($ann)->contentLocale);
    }

    /**
     * The sidebar's link of a single (a GET) cannot create: it opens "Add new" in the language with the offer, and
     * saving that form still makes a translation of the single (one group), never an unlinked entry.
     */
    public function testTheSinglesAddNewFormSavesALinkedTranslation(): void
    {
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '123']]);
        $this->actingAs($this->createUser('ann@example.com'));

        $edit = $this->browser->get('/admin/content/contact?locale=ru')->getHeaderLine('Location');
        self::assertSame('/admin/content/contact/' . $contact->id . '?translate=ru', $edit);
        $response = $this->browser->get($edit);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/content/contact/new?locale=ru&translate=' . $contact->id, $response->getHeaderLine('Location'));
        $new = (string) $this->browser->get($response->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('<html lang="ru" dir="ltr"', $new);
        self::assertStringContainsString('<p class="admin-toast-text">Версии «Contact» на языке «Русский» пока нет.</p>', $new);
        self::assertStringContainsString('action="/admin/content/contact/' . $contact->id . '/translate"', $new, 'the "Create translation" form');

        $saved = $this->browser->post('/admin/content/contact', ['_csrf' => $this->csrfToken(), 'locale' => 'ru', 'title' => 'Контакты', 'status' => 'published', 'fields' => ['phone' => '456']]);
        self::assertSame(303, $saved->getStatusCode());
        $locales = array_map(static fn(Entry $e): string => $e->locale, $this->entries()->translations($contact->translationGroup));
        sort($locales);
        self::assertSame(['en', 'ru'], $locales, 'the saved entry is the single\'s translation');
    }

    public function testTheSeparateModesAddNewShowsNoOffer(): void
    {
        $this->mode = LanguageMode::Separate;
        $this->bootApp();
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->actingAs($this->createUser('ann@example.com'));

        $new = (string) $this->browser->get('/admin/content/articles/new?locale=ru&translate=' . $hello->id)->getBody();

        self::assertStringNotContainsString('There is no Русский version', $new);
        self::assertStringNotContainsString('/translate"', $new);
    }

    private function entries(): EntryRepository
    {
        return $this->app->container()->service(EntryRepository::class);
    }
    public function testTheSeparateModeKeepsTheSinglesForm(): void
    {
        $this->mode = LanguageMode::Separate;
        $this->bootApp();
        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '123']]);
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->get('/admin/content/contact/' . $contact->id . '?translate=ru');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('action="/admin/content/contact/' . $contact->id . '/translate"', (string) $response->getBody());
    }
}
