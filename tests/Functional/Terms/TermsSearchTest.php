<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Terms;

use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The terms list of a flat taxonomy has a search by name (Plan 35, D3): the entries list's self-submitting filter
 * form (list-filters.js, htmx swaps #terms-results), matched like the column collation (case- and accent-insensitive),
 * in the list's language. Hierarchical taxonomies keep their tree without one. A save or delete in the dialog returns
 * to the same filtered list.
 */
final class TermsSearchTest extends ContentFunctionalTestCase
{
    public function testAFlatTaxonomySearchesItsNamesLikeTheCollation(): void
    {
        $this->term('tags', 'Café');
        $this->term('tags', 'CAFETERIA');
        $this->term('tags', 'News');
        $this->term('tags', '100% fresh');
        $this->term('tags', 'Кафе', ['locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/tags?q=cafe')->getBody();
        self::assertStringContainsString('>Café</a>', $body);
        self::assertStringContainsString('>CAFETERIA</a>', $body);
        self::assertStringNotContainsString('>News</a>', $body);
        self::assertStringNotContainsString('Кафе', $body, 'the list\'s language only');
        self::assertStringContainsString('<p class="admin-muted admin-list-count">2 items</p>', $body);
        self::assertStringContainsString('<input type="search" id="terms-q" name="q" maxlength="100" value="cafe" data-list-search>', $body);

        $percent = (string) $this->browser->get('/admin/taxonomies/tags?q=%25')->getBody();
        self::assertStringContainsString('>100% fresh</a>', $percent, 'a wildcard is matched literally');
        self::assertStringNotContainsString('>News</a>', $percent);
    }

    public function testTheFilterFormIsTheListsAndNoMatchIsTheFilteredEmptyState(): void
    {
        $this->term('tags', 'News');
        $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/tags')->getBody();
        self::assertStringContainsString('<form class="admin-filters" method="get" action="/admin/taxonomies/tags" hx-get="/admin/taxonomies/tags" hx-target="#terms-results" hx-select="#terms-results" hx-swap="outerHTML" hx-push-url="true" hx-sync="this:replace" role="search" data-list-filters>', $body);
        self::assertStringContainsString('<label class="admin-filter admin-filter-search" for="terms-q"><span class="admin-filter-label">Search by name</span>', $body);
        self::assertStringContainsString('<div id="terms-results">', $body);
        self::assertStringContainsString('admin-list-apply', $body, 'the no-JS submit button');
        self::assertMatchesRegularExpression('~<script type="module" src="[^"]*/list-filters\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', $body);
        self::assertStringNotContainsString('New tags are added', $body, 'no explanation line (a user decision)');

        $none = (string) $this->browser->get('/admin/taxonomies/tags?q=%3Cb%3Ezzz')->getBody();
        self::assertStringContainsString('Nothing matches these filters.', $none);
        self::assertStringContainsString('value="&lt;b&gt;zzz"', $none);

        $tree = (string) $this->browser->get('/admin/taxonomies/topics?q=zzz')->getBody();
        self::assertStringNotContainsString('data-list-filters', $tree, 'a hierarchical taxonomy keeps its tree without a search');
        self::assertStringContainsString('>Code</a>', $tree, 'and ignores q');
    }

    public function testTheDialogReturnsToTheFilteredList(): void
    {
        $news = $this->term('tags', 'News');
        $old = $this->term('tags', 'Old');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $hx = ['HX-Request' => 'true', 'HX-Current-URL' => 'http://localhost/admin/taxonomies/tags?locale=en&q=ne&translate=5'];

        $saved = $this->browser->post('/admin/taxonomies/tags/' . $news->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $news->updatedAt->format('Y-m-d H:i:s'),
            'locale' => 'en',
            'name' => 'Newer',
            'slug' => 'news',
        ], $hx);
        self::assertSame('/admin/taxonomies/tags?locale=en&q=ne', $saved->getHeaderLine('HX-Redirect'));

        $deleted = $this->browser->post('/admin/taxonomies/tags/' . $old->id . '/delete', ['_csrf' => $this->csrfToken()], $hx);
        self::assertSame('/admin/taxonomies/tags?locale=en&q=ne', $deleted->getHeaderLine('HX-Redirect'));

        $elsewhere = $this->browser->post('/admin/taxonomies/tags/' . $news->id, [
            '_csrf' => $this->csrfToken(),
            'version' => $this->terms()->find($news->id)?->updatedAt->format('Y-m-d H:i:s') ?? '',
            'locale' => 'en',
            'name' => 'Newest',
            'slug' => 'news',
        ], ['HX-Request' => 'true', 'HX-Current-URL' => 'http://localhost/admin/content/articles?q=x']);
        self::assertSame('/admin/taxonomies/tags?locale=en', $elsewhere->getHeaderLine('HX-Redirect'), 'another page is never the target');
    }
}
