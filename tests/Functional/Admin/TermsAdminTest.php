<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Admin\AdminView;
use AtarCms\Auth\User;
use AtarCms\Content\Taxonomies;
use AtarCms\Http\Security\CspNonce;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\RequestLocale;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use Nyholm\Psr7\ServerRequest;

final class TermsAdminTest extends ContentFunctionalTestCase
{
    public function testUsersWhoCannotManageTaxonomiesGetNoTermScreens(): void
    {
        $topic = $this->term('topics', 'PHP');
        $this->actingAs($this->createUser('au@example.com', 'author'));

        self::assertStringNotContainsString('/admin/taxonomies/', (string) $this->browser->get('/admin')->getBody());
        foreach (['/admin/taxonomies/topics', '/admin/taxonomies/topics/new', '/admin/taxonomies/topics/' . $topic->id, '/admin/taxonomies/ghost'] as $path) {
            self::assertSame(403, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertSame(403, $this->browser->post('/admin/taxonomies/topics', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'name' => 'Go'])->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/taxonomies/topics/' . $topic->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'name' => 'Hacked',
        ])->getStatusCode());
        self::assertNull($this->terms()->findBySlug('topics', 'en', 'go'));
        self::assertSame('PHP', $this->terms()->find($topic->id)?->name);
    }

    public function testUnknownTaxonomiesAndTermsOfOtherTaxonomiesAreNotFound(): void
    {
        $tag = $this->term('tags', 'News');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertSame(404, $this->browser->get('/admin/taxonomies/ghost')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/taxonomies/ghost/new')->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/taxonomies/topics/' . $tag->id)->getStatusCode());
        self::assertSame(404, $this->browser->get('/admin/taxonomies/topics/99999')->getStatusCode());
        self::assertSame(404, $this->browser->post('/admin/taxonomies/topics/' . $tag->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'name' => 'Moved',
        ])->getStatusCode());
        self::assertSame('News', $this->terms()->find($tag->id)?->name);
    }

    public function testListsTheTermsOfOneLanguageAsAnEscapedTree(): void
    {
        $code = $this->term('topics', 'Code <b>');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $this->term('topics', 'Код', ['locale' => 'ru']);
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/topics')->getBody();

        self::assertMatchesRegularExpression(self::navLink('/admin/taxonomies/topics', 'Topics', true), $body);
        self::assertMatchesRegularExpression(self::navLink('/admin/taxonomies/tags', 'Tags'), $body);
        self::assertStringContainsString('<td class="admin-depth-0" data-label="Name"><a class="admin-row-link" href="/admin/taxonomies/topics/' . $code->id . '" data-dialog-open>Code &lt;b&gt;</a></td>', $body);
        self::assertStringContainsString('<td class="admin-depth-1" data-label="Name"><a class="admin-row-link" href="/admin/taxonomies/topics/' . $php->id . '" data-dialog-open>PHP</a></td>', $body);
        self::assertMatchesRegularExpression('~PHP</a></td>\s*<td class="admin-muted" data-label="Address">php</td>\s*<td data-label="Used in"><span class="admin-uses" title="Used in 1 entry">1</span></td>~', $body);
        self::assertStringContainsString('href="/admin/taxonomies/topics/new?locale=en"', $body);
        self::assertStringNotContainsString('Код', $body);
        self::assertStringContainsString('Код', (string) $this->browser->get('/admin/taxonomies/topics?locale=ru')->getBody());
        self::assertStringContainsString('Код', (string) $this->browser->get('/admin/taxonomies/topics?locale=de')->getBody(), 'an unsupported locale falls back to the remembered one');
        self::assertStringContainsString('Code &lt;b&gt;', (string) $this->browser->get('/admin/taxonomies/topics?locale=en')->getBody());
    }

    public function testCreatesAndUpdatesTermsWithAStaleFormCheck(): void
    {
        $code = $this->term('topics', 'Code');
        $this->term('topics', 'Existing', ['parent' => $code]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringContainsString(
            '<input type="hidden" name="locale" value="ru">',
            (string) $this->browser->get('/admin/taxonomies/topics/new?locale=ru')->getBody(),
        );

        $created = $this->browser->post('/admin/taxonomies/topics', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'name' => 'PHP & Co',
            'slug' => '',
            'parent_id' => (string) $code->id,
            'position' => '7',
        ]);
        $term = $this->terms()->findBySlug('topics', 'en', 'php-co');
        self::assertNotNull($term);
        self::assertSame(303, $created->getStatusCode());
        self::assertSame('/admin/taxonomies/topics/' . $term->id, $created->getHeaderLine('Location'));
        self::assertSame([$code->id, 1], [$term->parentId, $term->position], 'a posted position is ignored: the new term goes last');

        $form = (string) $this->browser->get('/admin/taxonomies/topics/' . $term->id)->getBody();
        self::assertStringContainsString('Saved.', $form);
        self::assertStringContainsString('<input type="hidden" name="version" value="2026-09-28 12:00:00">', $form);
        self::assertStringContainsString('<option value="' . $code->id . '" selected>Code</option>', $form);
        self::assertStringContainsString('value="PHP &amp; Co"', $form);

        $this->clock->advance('+1 minute');
        $saved = $this->browser->post('/admin/taxonomies/topics/' . $term->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'name' => 'PHP',
            'slug' => 'php',
            'parent_id' => '',
            'position' => '0',
        ]);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame(1, $this->terms()->find($term->id)?->position, 'moved to the top level: last among its new siblings');
        $reloaded = $this->terms()->find($term->id);
        self::assertSame(['PHP', 'php', null], [$reloaded->name, $reloaded->slug, $reloaded->parentId]);
        self::assertStringContainsString('Changes saved.', (string) $this->browser->get('/admin/taxonomies/topics/' . $term->id)->getBody());

        $stale = $this->browser->post('/admin/taxonomies/topics/' . $term->id, [
            '_csrf' => $this->csrfToken(),
            'version' => '2026-09-28 12:00:00',
            'name' => 'Overwrite',
        ]);
        self::assertSame(422, $stale->getStatusCode());
        $staleBody = (string) $stale->getBody();
        self::assertMatchesRegularExpression('~admin-toast-error.*Someone else saved this item after you opened it\.~s', $staleBody, 'a refusal without a field is a toast');
        self::assertStringNotContainsString('class="admin-alert"', $staleBody);
        self::assertStringContainsString('value="Overwrite"', $staleBody, 'the input is kept');
        self::assertSame('PHP', $this->terms()->find($term->id)?->name);
        self::assertSame(['term.created', 'term.created', 'term.created', 'term.updated'], $this->auditActions());
    }

    /**
     * The parent select must not offer the term itself, nor any of its descendants: TermService refuses a
     * descendant as a parent anyway (it would create a cycle), and un-filtered options invite exactly that.
     */
    public function testParentSelectExcludesTheTermItselfAndItsDescendants(): void
    {
        $code = $this->term('topics', 'Code');
        $php = $this->term('topics', 'PHP', ['parent' => $code]);
        $framework = $this->term('topics', 'Laravel', ['parent' => $php]);
        $go = $this->term('topics', 'Go');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/topics/' . $php->id)->getBody();

        self::assertStringNotContainsString('value="' . $php->id . '"', $body, 'the term itself');
        self::assertStringNotContainsString('value="' . $framework->id . '"', $body, "the term's own child");
        self::assertStringContainsString('value="' . $code->id . '"', $body);
        self::assertStringContainsString('value="' . $go->id . '"', $body);
    }

    public function testTheFormIsOneCardWithTheActionsInTheStickyBar(): void
    {
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $edit = (string) $this->browser->get('/admin/taxonomies/topics/' . $code->id)->getBody();
        $bar = substr($edit, (int) strpos($edit, '<div class="admin-action-bar">'));

        self::assertStringContainsString('<form class="admin-form-card" method="post" action="/admin/taxonomies/topics/' . $code->id . '">', $edit);
        self::assertLessThan(strpos($bar, 'href="/topics/code"'), strpos($bar, 'href="/admin/taxonomies/topics/' . $code->id . '/delete"'), 'Delete… comes first');
        self::assertLessThan(strpos($bar, '<button type="submit" class="admin-button admin-button-primary">'), strpos($bar, 'href="/topics/code"'), 'Save comes last');
        self::assertStringNotContainsString('Back to the list', $edit);
        self::assertStringNotContainsString('/delete"', (string) $this->browser->get('/admin/taxonomies/topics/new')->getBody());
    }

    public function testInvalidInputIsRerenderedEscaped(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/taxonomies/tags', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'name' => ' ',
            'slug' => 'Bad"><script>',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please fix 2 fields.', $body);
        self::assertStringContainsString('This field is required.', $body);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $body);
        self::assertStringContainsString('value="Bad&quot;&gt;&lt;script&gt;"', $body);
        self::assertStringNotContainsString('Bad"><script>', $body);
        self::assertStringNotContainsString('name="parent_id"', $body, 'a flat taxonomy has no parent');
        self::assertSame([], $this->terms()->allOf('tags'));
    }

    /**
     * Each invalid input is marked and names its error line, on the full form and in the Sections dialog alike (they
     * share terms/fields.php).
     */
    public function testInvalidInputsAreMarkedAndDescribed(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->post('/admin/taxonomies/topics', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'name' => ' ',
            'slug' => 'Bad slug!',
            'parent_id' => '999999',
        ])->getBody();

        self::assertStringContainsString('<input id="name" name="name" required maxlength="200" value=" " aria-invalid="true" aria-describedby="name-error" lang="en" dir="ltr">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="name-error">This field is required.</span>', $body);
        self::assertStringContainsString('<input id="slug" name="slug" maxlength="190" dir="ltr" value="Bad slug!" aria-invalid="true" aria-describedby="slug-error">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="slug-error">', $body);
        self::assertStringContainsString('<select id="parent_id" name="parent_id" aria-invalid="true" aria-describedby="parent_id-error">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="parent_id-error">', $body);

        $dialog = (string) $this->browser->post('/admin/sections', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'version' => '',
            'name' => '',
            'slug' => '',
            'parent_id' => '',
        ], ['HX-Request' => 'true'])->getBody();
        self::assertStringContainsString('<input id="name" name="name" required maxlength="200" value="" autofocus aria-invalid="true" aria-describedby="name-error" lang="en" dir="ltr">', $dialog);
        self::assertStringContainsString('<input id="slug" name="slug" maxlength="190" dir="ltr" value="">', $dialog, 'a valid input stays unmarked');
    }

    /**
     * A flat taxonomy's form has no parent or order inputs: an error for one of them (a crafted post) is an error toast
     * instead of vanishing.
     */
    public function testAParentErrorOnAFlatTaxonomyIsShown(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/taxonomies/tags', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'name' => 'PHP',
            'parent_id' => 'x',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Choose a parent of the same list and language, other than this item and the items inside it\.</p>~', $body);
        self::assertStringNotContainsString('class="admin-alert"', $body, 'no field of the form has an error');
        self::assertSame([], $this->terms()->allOf('tags'));
    }

    /**
     * A form-wide error (a stale form) and an error of an input the form does not show are error toasts, each once,
     * beside the summary of a field error. No post reaches this state today (a stale form skips validation), so the
     * template is rendered directly.
     */
    public function testFieldlessErrorsAreToastsBesideTheFieldSummary(): void
    {
        $news = $this->term('tags', 'News');
        $user = $this->createUser('ed@example.com', 'editor');
        $request = new ServerRequest('GET', 'https://localhost/admin/taxonomies/tags/' . $news->id)
            ->withAttribute(User::class, $user)
            ->withAttribute(RequestLocale::class, new RequestLocale('en', false))
            ->withAttribute(CspNonce::class, CspNonce::generate())
            ->withAttribute(Session::class, new Session('test'));

        $body = $this->app->container()->service(AdminView::class)->render('terms/form', $request, [
            'taxonomy' => $this->app->container()->service(Taxonomies::class)->get('tags'),
            'term' => $news,
            'locale' => 'en',
            'locales' => ['en'],
            'translations' => [],
            'values' => ['name' => 'News', 'slug' => 'news', 'parent_id' => 'x'],
            'errors' => ['term' => 'validation.term.stale', 'parent_id' => 'validation.term.parent', 'name' => 'validation.required'],
            'params' => [],
            'version' => 'old',
            'parents' => [],
            'viewUrl' => null,
        ]);

        self::assertMatchesRegularExpression('~<div class="admin-toasts" data-toasts aria-live="polite">\s*<div class="admin-toast admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Someone else saved this item after you opened it\. Reload the page to see their changes; yours were not saved\.</p>.*?<p class="admin-toast-text">Choose a parent of the same list and language, other than this item and the items inside it\.</p>~s', $body);
        self::assertSame(1, substr_count($body, 'Choose a parent of the same list'));
        self::assertMatchesRegularExpression('~<div class="admin-alert" role="alert"><svg[^>]*>.*?</svg><p>Please fix 1 field\.</p></div>~', $body, 'the summary counts the field errors only');
    }

    public function testTheTermFormHasNoOrderField(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/topics/new')->getBody();

        self::assertStringContainsString('name="parent_id"', $body);
        self::assertLessThan((int) strpos($body, 'id="name"'), (int) strpos($body, 'id="parent_id"'), '"Inside" is the first field');
        self::assertStringNotContainsString('name="position"', $body);
    }

    /**
     * Only the sections have texts for search engines: other taxonomies' forms are one card without tabs, and posted
     * texts are dropped.
     */
    public function testOnlySectionsHaveTextsForSearchEngines(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/taxonomies/topics/new')->getBody();
        self::assertStringContainsString('<div class="admin-card admin-form">', $form);
        foreach (['data-tabs', 'meta_title', 'meta_description', 'tabs.js'] as $absent) {
            self::assertStringNotContainsString($absent, $form, $absent);
        }

        $this->browser->post('/admin/taxonomies/topics', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'name' => 'Code', 'slug' => '', 'parent_id' => '', 'meta_title' => 'Code', 'meta_description' => 'All code.']);
        $code = $this->terms()->findBySlug('topics', 'en', 'code') ?? self::fail('Not created');
        self::assertSame([null, null], [$code->seo->metaTitle, $code->seo->metaDescription]);
    }

    public function testCommaInATagNameIsRejected(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/taxonomies/tags', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'name' => 'PHP, web',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Tag names cannot contain commas.', (string) $response->getBody());
        self::assertSame([], $this->terms()->allOf('tags'));
    }
}
