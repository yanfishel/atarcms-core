<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Tags;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\Term;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The tags field's tag box (Plan 35): the comma-separated input stays the posted value and the whole widget without
 * JavaScript; the server renders the box with a pill per chosen tag, the combobox, its suggestions (the taxonomy's
 * tags of the entry's language) and the templates tags.js clones.
 */
final class TagsWidgetTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Locales::class, new Locales('en', ['en', 'ru', 'he']));
    }

    public function testTheFormOffersTheTagsOfTheTaxonomyAndLanguage(): void
    {
        $this->term('tags', 'Zebra');
        $this->term('tags', 'Apple <b>&"');
        $this->term('tags', 'Новости', ['locale' => 'ru']);
        $this->term('topics', 'Code');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/articles/new')->getBody();

        self::assertStringContainsString('data-tags-names="[&quot;Apple &lt;b&gt;&amp;\&quot;&quot;,&quot;Zebra&quot;]"', $form, 'by name, escaped, this taxonomy and language only');
        self::assertStringNotContainsString('Новости', $form);
        self::assertMatchesRegularExpression('~<script type="module" src="[^"]*/tags\.js\?v=[0-9a-f]{12}" nonce="[^"]+"></script>~', $form);
        self::assertStringNotContainsString('tags.js', (string) $this->browser->get('/admin/content/events/new')->getBody(), 'only forms with a tags field load it');
    }

    public function testTheBoxShowsAPillPerChosenTag(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'fields' => ['tags' => 'News, <b>Bold</b>'],
        ]);
        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'hello');
        self::assertNotNull($entry);

        $form = (string) $this->browser->get('/admin/content/articles/' . $entry->id)->getBody();

        self::assertStringContainsString('<input id="field-tags" name="fields[tags]" value="News, &lt;b&gt;Bold&lt;/b&gt;" autocomplete="off" class="admin-tags-value" data-tags-value lang="en" dir="ltr">', $form);
        self::assertStringContainsString('<span class="admin-help admin-tags-help-plain">Separate tags with commas; new tags are created.</span>', $form);
        self::assertStringContainsString('<ul class="admin-tags-list" aria-label="Chosen tags" data-tags-list>', $form);
        self::assertStringContainsString('<span class="admin-tags-pill-text">News</span><button type="button" class="admin-tags-remove" data-tags-remove aria-label="Remove “News”" title="Remove “News”">', $form);
        self::assertStringContainsString('<span class="admin-tags-pill-text">&lt;b&gt;Bold&lt;/b&gt;</span>', $form);
        self::assertStringNotContainsString('<b>Bold</b>', $form);
        self::assertSame(3, substr_count($form, '<li class="admin-tags-pill">'), 'the two pills and the template\'s empty one');
        self::assertSame(1, substr_count($form, '<template data-tags-pill><li class="admin-tags-pill">'));
        self::assertStringContainsString('<input class="admin-tags-query" id="field-tags-query" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="field-tags-listbox" autocomplete="off" maxlength="200" aria-describedby="field-tags-help field-tags-full" data-tags-query>', $form);
        self::assertStringContainsString('<ul class="admin-tags-listbox" id="field-tags-listbox" role="listbox" aria-label="Suggestions" hidden data-tags-listbox></ul>', $form);
        self::assertStringContainsString('<span class="admin-visually-hidden" role="status" data-tags-status></span>', $form);
        self::assertStringContainsString('<span class="admin-help" id="field-tags-help">Type to find a tag, then press Enter or a comma. A new name becomes a new tag. Up to 5 tags.</span>', $form);
        self::assertStringContainsString('<span class="admin-help admin-tags-full" id="field-tags-full" data-tags-full hidden></span>', $form, 'empty until full: aria-describedby reads a hidden element\'s text too');
        self::assertStringContainsString('data-full="That is the most tags this field takes."', $form);
        self::assertStringContainsString('data-max="5"', $form);
        self::assertStringContainsString('data-remove="Remove “#”"', $form);
        self::assertStringContainsString('data-add="Add “#”"', $form);
        self::assertStringContainsString('<template data-tags-option><li class="admin-tags-option" role="option">', $form);
        self::assertStringContainsString('<template data-tags-create><li class="admin-tags-option admin-tags-create" role="option">', $form);
        self::assertStringNotContainsString('data-tags-required', $form, 'the field is not required');
    }

    public function testAHebrewEntryWritesItsTagsRightToLeft(): void
    {
        $section = $this->term('sections', 'חדשות', ['locale' => 'he', 'slug' => 'hadashot']);
        $shalom = $this->entry('stories', 'שלום', ['locale' => 'he', 'slug' => 'shalom', 'terms' => ['sections' => [$section->id]]]);
        $this->term('tags', 'ספורט', ['locale' => 'he']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/stories/' . $shalom->id)->getBody();

        self::assertMatchesRegularExpression('~<div class="admin-tags" data-tags [^>]*lang="he" dir="rtl">~', $form);
        self::assertStringContainsString('data-tags-names="[&quot;ספורט&quot;]"', $form);
        self::assertStringNotContainsString('data-max=', $form, 'no limit on this field');
        self::assertStringContainsString('<span class="admin-help" id="field-tags-help">Type to find a tag, then press Enter or a comma. A new name becomes a new tag.</span>', $form);
    }

    public function testARefusedSaveMarksTheVisibleControlAndKeepsThePostedTags(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'fields' => ['tags' => 'Kept, ' . str_repeat('x', 201)],
        ]);
        $form = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<div class="admin-field admin-field-invalid">', $form);
        self::assertMatchesRegularExpression('~<input id="field-tags" name="fields\[tags\]" [^>]*aria-invalid="true" aria-describedby="field-tags-error"~', $form);
        self::assertStringContainsString('aria-describedby="field-tags-help field-tags-full field-tags-error" aria-invalid="true" data-tags-query>', $form);
        self::assertStringContainsString('<span class="admin-tags-pill-text">Kept</span>', $form);
        self::assertSame([], $this->terms()->forTaxonomy('tags', 'en'), 'nothing stored');
    }

    public function testSavingStillCreatesAndLinksTheTags(): void
    {
        $this->term('tags', 'PHP');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $this->browser->post('/admin/content/articles', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'fields' => ['tags' => 'php, Fresh'],
        ]);
        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('articles', 'en', 'hello');
        self::assertNotNull($entry);

        self::assertSame(['PHP', 'Fresh'], array_map(static fn(Term $t): string => $t->name, $this->terms()->forEntries([$entry->id])[$entry->id] ?? []));
    }
}
