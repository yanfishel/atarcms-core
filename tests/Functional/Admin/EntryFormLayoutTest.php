<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryStatus;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

final class EntryFormLayoutTest extends ContentFunctionalTestCase
{
    public function testTheFormHasTwoColumnsOfTabsAndWithoutJavaScriptEveryPanelUnderItsHeading(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->get('/admin/content/articles/new');
        $body = (string) $response->getBody();
        preg_match("~script-src 'self' 'nonce-([A-Za-z0-9+/=]+)'~", $response->getHeaderLine('Content-Security-Policy'), $csp);

        self::assertStringContainsString('<div class="admin-form-column" data-tabs="admin.tabs.articles.main">', $body);
        self::assertStringContainsString('<div class="admin-form-column" data-tabs="admin.tabs.articles.side">', $body);
        preg_match_all('~<button type="button" class="admin-tab" role="tab" id="tab-([a-z]+)" aria-controls="panel-\1" aria-selected="(true|false)"~', $body, $tabs);
        self::assertSame(['content', 'media', 'settings', 'seo'], $tabs[1], 'no Social tab: the sharing image is made by the site');
        self::assertSame(['true', 'false', 'true', 'false'], $tabs[2], 'the first tab of each column is open');
        self::assertStringContainsString('<div class="admin-tab-list" role="tablist" aria-label="Content sections">', $body);
        self::assertStringContainsString('<div class="admin-tab-list" role="tablist" aria-label="Settings sections">', $body, 'each tab list has its own name');
        foreach (['content' => 'Content', 'media' => 'Media', 'settings' => 'Settings', 'seo' => 'SEO'] as $tab => $heading) {
            $open = in_array($tab, ['content', 'settings'], true) ? ' admin-tab-panel-open' : '';
            self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-card' . $open . '" role="tabpanel" id="panel-' . $tab . '" aria-labelledby="tab-' . $tab . '">\s*<h2 class="admin-tab-heading">' . $heading . '</h2>~', $body, 'the open panel is marked, so the others stay hidden until tabs.js runs');
        }
        self::assertStringNotContainsString('role="tabpanel" hidden', $body, 'panels are only hidden by tabs.js');

        $panel = static fn(string $tab): int => (int) strpos($body, 'id="panel-' . $tab . '"');
        $at = static fn(string $needle): int => (int) strpos($body, $needle);
        self::assertTrue($panel('content') < $at('name="title"') && $at('name="title"') < $at('name="fields[summary]"') && $at('name="fields[summary]"') < $panel('media'));
        self::assertTrue($panel('media') < $at('name="fields[cover]"') && $at('name="fields[attachment]"') < $panel('settings'), 'media fields on the media tab');
        self::assertTrue($panel('settings') < $at('id="terms-topics"') && $at('id="terms-topics"') < $at('name="status"'), 'the categories open the Settings tab');
        self::assertTrue($at('name="status"') < $at('name="fields[event_date]"') && $at('name="fields[event_date]"') < $panel('seo'));
        self::assertTrue($panel('seo') < $at('name="meta_title"') && $at('name="meta_title"') < $at('name="meta_description"') && $at('name="meta_description"') < $at('name="slug"'), 'the address last');
        self::assertStringNotContainsString('og_image', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=[0-9a-f]{12}" nonce="' . preg_quote($csp[1] ?? '', '~') . '"></script>~', $body);
    }

    public function testATagsFieldOnTheSettingsTabSitsAboveTheSectionAndTheStatus(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/stories/new')->getBody();

        $at = static fn(string $needle): int => (int) strpos($body, $needle);
        self::assertTrue(0 < $at('id="field-tags"') && $at('id="field-tags"') < $at('id="terms-sections"'), 'the tags open the Settings tab');
        self::assertTrue($at('id="terms-sections"') < $at('name="status"'), 'the section picker sits above the status');
    }

    public function testTheStatusIsARadioListAndTheDateFieldIsVisibleWithoutJavaScript(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/new')->getBody();

        preg_match_all('~<label class="admin-radio"><input type="radio" name="status" value="([a-z]+)"( checked)? data-status-choice><span class="admin-radio-text"><span class="admin-radio-title">([^<]+)</span><span class="admin-help">([^<]+)</span>~', $body, $radios);
        self::assertSame(['published', 'draft', 'scheduled'], $radios[1]);
        self::assertSame(['', ' checked', ''], $radios[2], 'a new entry is a draft');
        self::assertSame(['Visible on the site', 'Visible only in the admin', 'Goes live on the date below'], $radios[4]);
        self::assertMatchesRegularExpression('~<div class="admin-field" data-status-date>\s*<label for="published_at">~', $body);
        self::assertStringContainsString('<input type="datetime-local" id="published_at" name="published_at" value="" data-now="2026-09-28T12:00">', $body, 'the current site time, for choosing "Published"');
    }

    public function testErrorsAreCountedOnTheirTabs(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => '',
            'meta_title' => str_repeat('x', 256),
            'fields' => ['rating' => '11', 'event_date' => 'soon'],
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please fix 4 fields.', $body);
        self::assertStringContainsString('data-tab="content" data-errors="2">', $body);
        self::assertStringContainsString('data-tab="media" data-errors="0">', $body);
        self::assertStringContainsString('data-tab="settings" data-errors="1">', $body);
        self::assertStringContainsString('data-tab="seo" data-errors="1">', $body);
        self::assertStringContainsString('<span class="admin-tab-badge" aria-hidden="true">2</span><span class="admin-visually-hidden">2 errors</span>', $body);
        self::assertStringContainsString('<input id="title" name="title" required maxlength="255" value="" aria-invalid="true" aria-describedby="title-error" lang="en" dir="ltr">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="title-error">This field is required.</span>', $body);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $body);
        self::assertStringContainsString('aria-describedby="meta_title-error"', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="meta_title-error">', $body);
    }

    /**
     * The server opens the first tab with errors of each column (tabs.js does the same), so nothing jumps when the
     * script starts.
     */
    public function testTheFirstTabWithErrorsIsOpenFromTheStart(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'meta_title' => str_repeat('x', 256),
        ])->getBody();

        preg_match_all('~id="tab-([a-z]+)" aria-controls="panel-\1" aria-selected="(true|false)" tabindex="(0|-1)"~', $body, $tabs);
        self::assertSame(['true', 'false', 'false', 'true'], $tabs[2]);
        self::assertSame(['0', '-1', '-1', '0'], $tabs[3]);
        self::assertStringContainsString('<section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-seo"', $body);
        self::assertStringContainsString('<section class="admin-tab-panel admin-card" role="tabpanel" id="panel-settings"', $body);
    }

    public function testInvalidStatusAndTermsMarkTheirInputs(): void
    {
        $code = $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'bogus',
            'terms' => ['topics' => ['x']],
        ])->getBody();

        self::assertStringContainsString('<fieldset class="admin-radio-list admin-field-invalid">', $body, 'aria-invalid is not for a group (ARIA 1.2)');
        self::assertStringContainsString('<input type="radio" name="status" value="draft" data-status-choice aria-invalid="true" aria-describedby="status-error">', $body, 'every radio, none checked: the posted status is none of them');
        self::assertStringContainsString('<span class="admin-field-error" id="status-error">', $body);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid" role="group" aria-labelledby="terms-topics">', $body);
        self::assertStringContainsString('<input type="checkbox" name="terms[topics][]" value="' . $code->id . '" aria-invalid="true" aria-describedby="terms-topics-error">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="terms-topics-error">Choose existing categories or tags of this language.</span>', $body);
    }

    /**
     * A crafted post can carry an error of an input this form does not have (no parent on a flat collection): it
     * is an error toast and counts on no tab.
     */
    public function testAnErrorOfAnInputTheFormDoesNotShowIsListedAboveTheTabs(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'position' => 'abc',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('~admin-toast-error" role="alert" data-toast><svg[^>]*>.*?</svg><p class="admin-toast-text">Enter a number\.</p>~', $body);
        self::assertStringNotContainsString('class="admin-alert"', $body, 'no field of the form has an error');
        self::assertStringNotContainsString('data-errors="1"', $body);
        self::assertStringNotContainsString('name="position"', $body);
    }

    /**
     * A form-wide error (here: the single entry of this language exists) and the errors of inputs the form does not
     * show are error toasts, each once; no summary.
     */
    public function testFormWideAndUnplacedErrorsAreToasts(): void
    {
        $this->entry('settings', 'Site settings', ['slug' => 'settings']);
        $this->actingAs($this->createUser('ed@example.com', 'admin'));

        $response = $this->browser->post('/admin/content/settings', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Again',
            'parent_id' => '5',
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('~<p class="admin-toast-text">This section holds one entry per language, and it already exists\.</p>.*?<p class="admin-toast-text">Choose a parent page of the same section and language, other than this page and its subpages\.</p>~s', $body);
        self::assertStringNotContainsString('class="admin-alert"', $body);
        self::assertSame(1, substr_count($body, 'Choose a parent page'));
        self::assertStringNotContainsString('name="parent_id"', $body);
        self::assertStringNotContainsString('Please fix', $body);
    }

    /**
     * Field widgets mark their control as the core inputs do: aria-invalid plus aria-describedby naming the error.
     */
    public function testInvalidFieldValuesMarkTheirWidgets(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'fields' => ['event_date' => 'someday', 'video' => 'not a video', 'rating' => '99', 'kind' => 'essay'],
        ])->getBody();

        self::assertStringContainsString('<input type="date" id="field-event_date" name="fields[event_date]" value="someday" aria-invalid="true" aria-describedby="field-event_date-error">', $body);
        self::assertStringContainsString('<input type="text" inputmode="url" dir="ltr" id="field-video" name="fields[video]" value="not a video" aria-invalid="true" aria-describedby="field-video-error">', $body);
        self::assertStringContainsString('<input type="number" step="any" id="field-rating" name="fields[rating]" value="99" min="0" max="10" aria-invalid="true" aria-describedby="field-rating-error">', $body);
        self::assertStringContainsString('<select id="field-kind" name="fields[kind]" aria-invalid="true" aria-describedby="field-kind-error">', $body);
        foreach (['event_date', 'video', 'rating', 'kind'] as $name) {
            self::assertMatchesRegularExpression('~<span class="admin-field-error" id="field-' . $name . '-error">[^<]+</span>~', $body, $name);
        }
        self::assertStringContainsString('<textarea id="field-summary" name="fields[summary]" rows="4" lang="en" dir="ltr">', $body, 'a valid field is not marked');
    }

    public function testThePositionOfAPageExplainsItself(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/pages/new')->getBody();

        self::assertMatchesRegularExpression('~<input type="number" id="position" name="position" step="1" value="">\s*<span class="admin-help">A lower number comes first among the items inside the same parent; the order screen sets it by dragging\.</span>~', $body);
    }

    /**
     * Below 480 px the two secondary actions of the bar show only their icons (admin.css); the text stays for screen
     * readers and as the tooltip.
     */
    public function testTheSecondaryActionsCanShrinkToIcons(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();

        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-link admin-button-danger admin-action-compact" href="/admin/content/articles/\d+/delete" title="Delete…"><svg[^>]*>.*?</svg><span class="admin-action-label">Delete…</span></a>~', $body);
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-secondary admin-action-compact" href="/admin/content/articles/\d+/revisions" title="Earlier versions"><svg[^>]*>.*?</svg><span class="admin-action-label">Earlier versions</span></a>~', $body);
        self::assertMatchesRegularExpression('~<button type="submit" class="admin-button admin-button-secondary admin-action-compact" formaction="/admin/content/articles/\d+/preview" formtarget="atar-preview-articles-\d+" formnovalidate title="Preview" data-preview><svg[^>]*>.*?</svg><span class="admin-action-label">Preview</span></button>~', $body);
    }

    public function testTheActionBarOffersDeleteRevisionsViewAndSaveInThisOrder(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/' . $hello->id)->getBody();
        $bar = substr($body, (int) strpos($body, '<div class="admin-action-bar">'));

        self::assertStringContainsString('<span data-autosave-text>Saved Sep 28, 2026, 12:00</span>', $bar);
        $positions = array_map(static fn(string $needle): int|false => strpos($bar, $needle), [
            'href="/admin/content/articles/' . $hello->id . '/delete"',
            'href="/admin/content/articles/' . $hello->id . '/revisions"',
            'formaction="/admin/content/articles/' . $hello->id . '/preview" formtarget="atar-preview-articles-' . $hello->id . '" formnovalidate',
            '<button type="submit" class="admin-button admin-button-primary">',
        ]);
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        self::assertStringNotContainsString('Back to the list', $body);
        self::assertMatchesRegularExpression('~<nav class="admin-breadcrumbs" aria-label="You are here">\s*<ol>\s*<li><a href="/admin">Dashboard</a></li>\s*<li><a href="/admin/content/articles\?locale=en">Articles</a></li>\s*<li><span aria-current="page">Hello</span></li>~', $body);

        $new = (string) $this->browser->get('/admin/content/articles/new')->getBody();
        self::assertStringContainsString('<span data-autosave-text>Not saved yet</span>', $new);
        self::assertStringNotContainsString('/revisions"', $new);
        self::assertStringContainsString('formaction="/admin/content/articles/preview" formtarget="atar-preview-articles-new" formnovalidate', $new);
        // Enter submits the form's first submit button: a hidden Save ahead of everything, never the Preview.
        self::assertMatchesRegularExpression('~<form class="admin-entry-form"[^>]*>\s*<button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true">Save</button>~', $new);
    }

    public function testAnOrdinaryCollectionNamesItsNewPageByTheActionAndTheBreadcrumbsEndWithIt(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/new')->getBody();

        self::assertStringContainsString('<title>Add new · Articles · ', $body);
        self::assertStringContainsString('<h1>Add new <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', $body);
        self::assertMatchesRegularExpression('~<nav class="admin-breadcrumbs" aria-label="You are here">\s*<ol>\s*<li><a href="/admin">Dashboard</a></li>\s*<li><a href="/admin/content/articles\?locale=en">Articles</a></li>\s*<li><span aria-current="page">Add new</span></li>\s*</ol>~', $body);
    }

    public function testASingleCollectionIsNamedByItsLabelWhetherTheEntryIsNewOrSaved(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'admin'));

        $new = (string) $this->browser->get('/admin/content/settings/new')->getBody();

        self::assertStringContainsString('<title>settings · ', $new);
        self::assertStringContainsString('<h1>settings</h1>', $new);
        self::assertStringNotContainsString('Add new', $new);
        self::assertMatchesRegularExpression('~<li><a href="/admin">Dashboard</a></li>\s*<li><span aria-current="page">settings</span></li>\s*</ol>~', $new);

        $saved = $this->entry('settings', 'Site settings', ['slug' => 'settings']);
        $edit = (string) $this->browser->get('/admin/content/settings/' . $saved->id)->getBody();

        self::assertStringContainsString('<title>settings · ', $edit);
        self::assertStringContainsString('<h1>settings</h1>', $edit);
        self::assertStringContainsString('value="Site settings"', $edit, 'the entry title stays in the title input');
        self::assertMatchesRegularExpression('~<li><a href="/admin">Dashboard</a></li>\s*<li><span aria-current="page">settings</span></li>\s*</ol>~', $edit);
    }

    public function testAnAuthorSeesOnlyTheDraftStatusAndNoDate(): void
    {
        $this->actingAs($this->createUser('au@example.com', 'author'));

        $body = (string) $this->browser->get('/admin/content/articles/new')->getBody();

        self::assertStringContainsString('<input type="radio" name="status" value="draft" checked data-status-choice>', $body);
        self::assertStringNotContainsString('value="published"', $body);
        self::assertStringNotContainsString('data-status-date', $body);
    }

    public function testTheDeleteConfirmationIsADangerCard(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'status' => EntryStatus::Draft]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/content/articles/' . $hello->id . '/delete')->getBody();

        self::assertMatchesRegularExpression('~<section class="admin-card admin-card-danger admin-confirm">\s*<svg[^>]*><use href="[^"]+#trash-2"></use></svg>\s*<h1>Delete entry <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>~', $body);
        self::assertMatchesRegularExpression('~<a class="admin-button admin-button-secondary" href="/admin/content/articles/' . $hello->id . '">Cancel</a>\s*<button type="submit" class="admin-button admin-button-danger">~', $body);
    }
}
