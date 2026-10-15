<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;

/**
 * The section form's "Main | SEO" tabs, on the full page and in the Sections screen's dialog: the SEO tab holds the
 * title (a template with {title}) and the description for search engines, each with one help line; field errors open
 * their tab.
 */
final class SectionSeoAdminTest extends ContentFunctionalTestCase
{
    /**
     * Two tabs (tabs.js; not remembered, so the dialog always opens on the name); without JavaScript both panels show
     * one after the other under their headings. The page's panels are the cards, the dialog's sit in the dialog; the
     * Sections screen loads tabs.js for the forms its dialog loads.
     */
    public function testTheSectionFormHasMainAndSeoTabs(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $page = (string) $this->browser->get('/admin/sections/new?locale=en')->getBody();
        self::assertMatchesRegularExpression('~<form class="admin-form-card" method="post" action="/admin/sections">\s*<input type="hidden" name="_csrf" value="[^"]+">\s*<input type="hidden" name="version" value="">\s*<input type="hidden" name="locale" value="en">\s*<div class="admin-form-column" data-tabs="">\s*<div class="admin-tab-list" role="tablist" aria-label="Parts of the form">\s*<button type="button" class="admin-tab" role="tab" id="tab-main" aria-controls="panel-main" aria-selected="true" tabindex="0" data-tab="main" data-errors="0">\s*Main\s*</button>\s*<button type="button" class="admin-tab" role="tab" id="tab-seo" aria-controls="panel-seo" aria-selected="false" tabindex="-1" data-tab="seo" data-errors="0">\s*SEO\s*</button>\s*</div>\s*<section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-main" aria-labelledby="tab-main">\s*<h2 class="admin-tab-heading">Main</h2>\s*<div class="admin-field">\s*<label for="parent_id">~', $page);
        self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-card" role="tabpanel" id="panel-seo" aria-labelledby="tab-seo">\s*<h2 class="admin-tab-heading">SEO</h2>\s*<div class="admin-field">\s*<label for="meta_title"><svg[^>]*>.*?</svg>Title for search engines</label>\s*<input id="meta_title" name="meta_title" maxlength="255" value="" lang="en" dir="ltr">\s*<span class="admin-help">Shown as the link in search results\.</span>\s*</div>~', $page, 'one short help line, no {title} explanation');
        self::assertMatchesRegularExpression('~<label for="meta_description"><svg[^>]*>.*?</svg>Description for search engines</label>\s*<textarea id="meta_description" name="meta_description" maxlength="500" rows="3" lang="en" dir="ltr"></textarea>\s*<span class="admin-help">A sentence or two shown under the link in search results\. Its subsections and articles without their own use it too\.</span>\s*</div>\s*</section>\s*</div>\s*<div class="admin-action-bar">~', $page);
        self::assertStringNotContainsString('<div class="admin-card admin-form">', $page, 'the panels are the cards');
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', $page);

        $dialog = (string) $this->browser->get('/admin/sections/new?locale=en', ['HX-Request' => 'true'])->getBody();
        self::assertStringContainsString('<section class="admin-tab-panel admin-tab-panel-open" role="tabpanel" id="panel-main" aria-labelledby="tab-main">', $dialog);
        self::assertStringContainsString('<section class="admin-tab-panel" role="tabpanel" id="panel-seo" aria-labelledby="tab-seo">', $dialog);
        self::assertStringContainsString('<input id="name" name="name" required maxlength="200" value="" autofocus lang="en" dir="ltr">', $dialog);

        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', (string) $this->browser->get('/admin/sections?locale=en')->getBody(), 'the dialog’s forms need it');
    }

    /**
     * The edit form shows the stored texts escaped, in the dialog too.
     */
    public function testTheEditFormShowsTheStoredTexts(): void
    {
        $about = $this->term('sections', 'About', ['metaTitle' => '{title} — Repair & "co"', 'metaDescription' => 'We <repair> things.']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        foreach ([[], ['HX-Request' => 'true']] as $headers) {
            $form = (string) $this->browser->get('/admin/sections/' . $about->id, $headers)->getBody();
            self::assertStringContainsString('<input id="meta_title" name="meta_title" maxlength="255" value="{title} — Repair &amp; &quot;co&quot;" lang="en" dir="ltr">', $form);
            self::assertStringContainsString('rows="3" lang="en" dir="ltr">We &lt;repair&gt; things.</textarea>', $form);
        }
    }

    /**
     * The texts are saved with the section; a refused text opens the SEO tab (its badge counts the errors, the input
     * points at its error line), on the page and in the dialog, unless the Main tab has errors too (the first tab with
     * errors opens).
     */
    public function testTheTextsAreSavedAndTheirErrorsOpenTheirTab(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $form = ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'version' => '', 'name' => 'Guides', 'slug' => '', 'parent_id' => '', 'meta_description' => 'Fine.'];
        $long = str_repeat('t', 256);

        $refused = $this->browser->post('/admin/sections', [...$form, 'meta_title' => $long]);
        $body = (string) $refused->getBody();
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('id="tab-main" aria-controls="panel-main" aria-selected="false" tabindex="-1" data-tab="main" data-errors="0">', $body);
        self::assertMatchesRegularExpression('~id="tab-seo" aria-controls="panel-seo" aria-selected="true" tabindex="0" data-tab="seo" data-errors="1">\s*SEO\s*<span class="admin-tab-badge" aria-hidden="true">1</span><span class="admin-visually-hidden">1 error</span>~', $body);
        self::assertStringContainsString('<section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-seo" aria-labelledby="tab-seo">', $body);
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $body);
        self::assertStringContainsString('<input id="meta_title" name="meta_title" maxlength="255" value="' . $long . '" aria-invalid="true" aria-describedby="meta_title-error" lang="en" dir="ltr">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="meta_title-error">Use at most 255 characters.</span>', $body);
        self::assertStringContainsString('rows="3" lang="en" dir="ltr">Fine.</textarea>', $body, 'the other text is kept');
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));

        $dialog = (string) $this->browser->post('/admin/sections', [...$form, 'meta_title' => $long], ['HX-Request' => 'true'])->getBody();
        self::assertStringContainsString('<section class="admin-tab-panel admin-tab-panel-open" role="tabpanel" id="panel-seo" aria-labelledby="tab-seo">', $dialog);

        $both = (string) $this->browser->post('/admin/sections', [...$form, 'name' => '', 'meta_title' => $long])->getBody();
        self::assertStringContainsString('id="tab-main" aria-controls="panel-main" aria-selected="true" tabindex="0" data-tab="main" data-errors="1">', $both, 'the first tab with errors');
        self::assertStringContainsString('id="tab-seo" aria-controls="panel-seo" aria-selected="false" tabindex="-1" data-tab="seo" data-errors="1">', $both);

        $this->browser->post('/admin/sections', [...$form, 'meta_title' => ' {title}  — Guides ']);
        $guides = $this->terms()->findBySlug('sections', 'en', 'guides') ?? self::fail('Not created');
        self::assertSame(['{title} — Guides', 'Fine.'], [$guides->seo->metaTitle, $guides->seo->metaDescription]);
    }
}
