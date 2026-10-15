<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Terms;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Taxonomies;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The terms list of a taxonomy (Plan 35, D3) is a list like the users': the name opens the term's form in the screen's
 * dialog, each row ends with Edit and Delete icon links (the dialog too), "Used in" is a count pill and the languages
 * are the theme's round flags. A taxonomy whose terms come only from tags fields has no "Add new": its tags are added
 * in the entries' tags input.
 */
final class TermsListTest extends ContentFunctionalTestCase
{
    public function testRowsOpenTheirFormAndTheirDeleteConfirmationInTheDialog(): void
    {
        $code = $this->term('topics', 'Code <b>');
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$code->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/topics')->getBody();

        self::assertStringContainsString('<a class="admin-row-link" href="/admin/taxonomies/topics/' . $code->id . '" data-dialog-open>Code &lt;b&gt;</a>', $body);
        self::assertStringContainsString('<th><span class="admin-visually-hidden">Actions</span></th>', $body);
        self::assertStringContainsString('<td class="admin-table-actions"><span class="admin-row-actions">', $body);
        self::assertStringContainsString('<a class="admin-row-action" href="/admin/taxonomies/topics/' . $code->id . '" title="Edit “Code &lt;b&gt;”" aria-label="Edit “Code &lt;b&gt;”" data-dialog-open>', $body);
        self::assertStringContainsString('<a class="admin-row-action admin-row-action-danger" href="/admin/taxonomies/topics/' . $code->id . '/delete" title="Delete “Code &lt;b&gt;”" aria-label="Delete “Code &lt;b&gt;”" data-dialog-open>', $body);
        self::assertStringContainsString('<td data-label="Used in"><span class="admin-uses" title="Used in 1 entry">1</span></td>', $body);
        self::assertStringContainsString('<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">', $body);
        self::assertMatchesRegularExpression('~<script type="module" src="[^"]*/dialog\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', $body);
        self::assertStringContainsString('href="/admin/taxonomies/topics/new?locale=en" data-dialog-open', $body, 'a taxonomy picked in the entry form keeps its Add, in the dialog too');
    }

    public function testAnUnusedTermHasNoPillAndTheDialogFragmentsLoad(): void
    {
        $tag = $this->term('tags', 'News');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringContainsString('<td data-label="Used in"></td>', (string) $this->browser->get('/admin/taxonomies/tags')->getBody());
        $edit = $this->browser->get('/admin/taxonomies/tags/' . $tag->id, ['HX-Request' => 'true']);
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('<div class="admin-dialog-header">', (string) $edit->getBody());
        $delete = $this->browser->get('/admin/taxonomies/tags/' . $tag->id . '/delete', ['HX-Request' => 'true']);
        self::assertSame(200, $delete->getStatusCode());
        self::assertStringContainsString('<div class="admin-dialog-footer">', (string) $delete->getBody());
    }

    public function testATaxonomyFilledOnlyByTagsFieldsHasNoAddButton(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $tags = (string) $this->browser->get('/admin/taxonomies/tags')->getBody();
        self::assertStringNotContainsString('/admin/taxonomies/tags/new', $tags, 'neither the header nor the empty state offers one');
        self::assertSame(200, $this->browser->get('/admin/taxonomies/tags/new')->getStatusCode(), 'the form stays reachable by its address');

        $regions = (string) $this->browser->get('/admin/taxonomies/regions')->getBody();
        self::assertStringContainsString('href="/admin/taxonomies/regions/new?locale=en"', $regions, 'a flat taxonomy picked as a checkbox list keeps it');
    }

    public function testCollectionsKnowWhichTaxonomiesOnlyTagsFieldsFill(): void
    {
        $collections = $this->app->container()->service(Collections::class);
        self::assertTrue($collections->onlyTagged('tags'));
        self::assertFalse($collections->onlyTagged('topics'));
        self::assertFalse($collections->onlyTagged('regions'));
        self::assertFalse($collections->onlyTagged('ghost'), 'used by no collection');

        $both = new Collections([
            Collection::make('a')->taxonomies(['tags']),
            Collection::make('b')->fields([Field::tags('tags')]),
        ], $this->app->container()->service(Taxonomies::class));
        self::assertFalse($both->onlyTagged('tags'), 'a checkbox list elsewhere still needs the Add');
    }

    /**
     * Like the entries list (partials/flags.php); a one-language site shows none (SingleLanguageAdminTest).
     */
    public function testTheLanguagesAreTheThemesFlags(): void
    {
        $this->term('regions', 'North');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/regions')->getBody();

        self::assertStringContainsString('<th>Languages</th>', $body);
        self::assertMatchesRegularExpression('~<td data-label="Languages"><span class="admin-flags">(<img class="admin-flag" src="[^"]+" alt="English" title="English" width="20" height="20">|<abbr class="admin-flag-code" title="English">EN</abbr>)~', $body);
    }

    /**
     * Tags are kept separately per language (a user decision): their list has no Languages column, whatever
     * translation groups old data holds.
     */
    public function testATaxonomyFilledOnlyByTagsFieldsHasNoLanguagesColumn(): void
    {
        $this->term('tags', 'News');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->get('/admin/taxonomies/tags')->getBody();

        self::assertStringContainsString('>News</a>', $body);
        self::assertStringNotContainsString('Languages', $body);
        self::assertStringNotContainsString('admin-flags', $body);
    }
}
