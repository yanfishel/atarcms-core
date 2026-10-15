<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Admin\AdminView;
use AtarCms\Content\EntryStatus;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The "Translations" line of a saved entry or term form: one item per site language in the site's order, the form's
 * own language as its pill, existing translations as links (or plain text without the right to edit them), missing
 * ones as a small "create" form (or "—" without the right), each named for screen readers.
 */
final class TranslationsLineTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en', 'ru', 'he']));
    }

    public function testTheEntryFormListsEveryLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $line = self::line((string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody());

        self::assertMatchesRegularExpression(
            '~^<nav class="admin-translations" aria-labelledby="translations-title">\s*<span class="admin-visually-hidden" id="translations-title">Translations:</span>\s*<ul class="admin-translations-list">\s*'
            . '<li class="admin-translations-item">\s*<span class="admin-translations-current" title="Content language: English"><span aria-hidden="true">EN</span><span class="admin-visually-hidden">\(Content language: English\)</span></span>\s*</li>\s*'
            . '<li class="admin-translations-item">\s*<a href="/admin/content/articles/' . $privet->id . '\?locale=ru" hreflang="ru" title="Русский"><span aria-hidden="true">RU</span><svg[^>]*><use href="[^"]+#check"></use></svg><span class="admin-visually-hidden">Русский: open the translation</span></a>\s*</li>\s*'
            . '<li class="admin-translations-item">\s*<form class="admin-translations-form" method="post" action="/admin/content/articles/' . $hello->id . '/translate">\s*<input type="hidden" name="_csrf" value="[^"]+">\s*<input type="hidden" name="locale" value="he">\s*'
            . '<button type="submit" class="admin-translations-create" title="עברית: copy for translation"><span aria-hidden="true">HE</span><svg[^>]*><use href="[^"]+#plus"></use></svg><span class="admin-visually-hidden">עברית: copy for translation</span></button>\s*'
            . '<button type="submit" class="admin-translations-create" name="machine" value="1" title="עברית: machine translation" data-machine-offer data-machine-from="en" data-machine-to="he" hidden><span aria-hidden="true">HE</span><svg[^>]*><use href="[^"]+#languages"></use></svg><span class="admin-visually-hidden">עברית: machine translation</span></button>\s*</form>\s*</li>\s*</ul>\s*</nav>$~',
            $line,
        );
    }

    public function testTheLineSitsInThePageHeaderRightOfTheTitle(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach (['/admin/content/articles/' . $hello->id, '/admin/taxonomies/topics/' . $code->id] as $url) {
            $body = (string) $this->browser->get($url)->getBody();
            $header = substr($body, (int) strpos($body, '<div class="admin-page-header">'));
            $header = substr($header, 0, (int) strpos($header, '</nav>'));
            self::assertMatchesRegularExpression('~^<div class="admin-page-header">\s*<h1>.*</h1>\s*<nav class="admin-translations"~s', $header, $url);
        }
    }

    public function testWithoutRightsTheItemsArePlainText(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'author' => $author, 'status' => EntryStatus::Draft]);
        $privet = $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->actingAs($author);

        $own = self::line((string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody());
        self::assertStringNotContainsString('/admin/content/articles/' . $privet->id, $own, 'the translation of another author is not linked');
        self::assertStringContainsString('<span class="admin-translations-exists" title="Русский">', $own);
        self::assertStringContainsString('action="/admin/content/articles/' . $hello->id . '/translate"', $own, 'an author translates own entries');
    }

    public function testAMissingTranslationWithoutTheRightToCreateItIsADash(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/admin')->withAttribute(RequestLocale::class, new RequestLocale('en', false));

        $line = $this->app->container()->service(AdminView::class)->renderer()->render('partials/translations', [
            'locale' => 'en',
            'locales' => ['en', 'ru', 'he'],
            'translations' => ['ru' => null],
            'create' => null,
        ], $request);

        self::assertStringContainsString('<span class="admin-translations-missing" title="עברית"><span aria-hidden="true">HE —</span><span class="admin-visually-hidden">עברית: no translation</span></span>', $line);
        self::assertStringNotContainsString('<form', $line);
        self::assertStringNotContainsString('<a ', $line);
    }

    public function testANewEntryAndAnUntranslatableCollectionHaveNoLine(): void
    {
        $note = $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringNotContainsString('admin-translations', (string) $this->browser->get('/admin/content/articles/new')->getBody());
        self::assertStringNotContainsString('admin-translations', (string) $this->browser->get('/admin/content/notes/' . $note->id)->getBody());
    }

    public function testTheTermFormAndTheTermDialogHaveTheLine(): void
    {
        $code = $this->term('topics', 'Code');
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $term = self::line((string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody());
        self::assertStringContainsString('action="/admin/taxonomies/topics/' . $code->id . '/translate"', $term);
        self::assertStringContainsString('<input type="hidden" name="locale" value="ru">', $term);
        self::assertStringContainsString('<input type="hidden" name="locale" value="he">', $term);

        $this->browser->post('/admin/taxonomies/topics/' . $code->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);
        $kod = array_values(array_filter($this->terms()->translations($code->translationGroup), static fn($t): bool => $t->locale === 'ru'))[0];
        $dialog = (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id, ['HX-Request' => 'true'])->getBody();
        $header = substr($dialog, 0, (int) strpos($dialog, '<form class="admin-dialog-form"'));
        self::assertStringContainsString('<a href="/admin/taxonomies/topics/' . $kod->id . '?locale=ru" hreflang="ru" title="Русский" data-dialog-open>', $header, 'in the header, outside the dialog form; the link opens in the dialog');
        self::assertStringContainsString('action="/admin/taxonomies/topics/' . $code->id . '/translate"', $header);
    }

    /**
     * The section dialog's header holds only the title (with the language's pill) and the close button (a user
     * decision of Plan 32); the full section page keeps the line.
     */
    public function testTheSectionsDialogHasNoLine(): void
    {
        $news = $this->term('sections', 'News');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $this->browser->post('/admin/sections/' . $news->id . '/translate', ['_csrf' => $this->csrfToken(), 'locale' => 'ru']);

        $dialog = (string) $this->browser->get('/admin/sections/' . $news->id, ['HX-Request' => 'true'])->getBody();
        self::assertStringNotContainsString('admin-translations', $dialog);
        self::assertStringNotContainsString('/translate"', $dialog);
        self::assertMatchesRegularExpression('~<h2 id="dialog-title">News <span class="admin-locale-badge"[^>]*>EN</span>~', $dialog);
        self::assertStringNotContainsString('admin-translations', (string) $this->browser->get('/admin/sections/new', ['HX-Request' => 'true'])->getBody());
        self::assertStringContainsString('admin-translations', (string) $this->browser->get('/admin/sections/' . $news->id)->getBody(), 'the full page keeps it');
    }

    private static function line(string $body): string
    {
        $start = strpos($body, '<nav class="admin-translations"');
        if ($start === false) {
            return '';
        }

        return substr($body, $start, (int) strpos($body, '</nav>', $start) + 6 - $start);
    }
}
