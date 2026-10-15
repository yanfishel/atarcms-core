<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\Term;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;

/**
 * The fixture's "stories" are routed "/{sections}/{slug}": their form picks exactly one section from a tree.
 */
final class EntrySectionFieldTest extends ContentFunctionalTestCase
{
    public function testTheSummaryHelpSitsUnderTheEditor(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/stories/new')->getBody();

        self::assertMatchesRegularExpression(
            '~<div class="admin-field admin-field-rich-text">\s*<label for="field-summary">.*?</label>\s*<textarea id="field-summary"[^>]*></textarea>\s*'
            . '<span class="admin-help admin-field-help">Leave it empty to show the whole story on its section page.</span>~s',
            $form,
        );
        self::assertMatchesRegularExpression('~<div class="admin-field admin-field-rich-text">\s*<label for="field-body">~', $form, 'every rich-text field');
    }

    public function testTheListIsFilteredBySectionsOnly(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP <b>', ['parent' => $guides]);
        $this->term('tags', 'News');
        $this->entry('stories', 'Hello', ['terms' => ['sections' => [$php->id], 'tags' => ['News']]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $list = (string) $this->browser->get('/admin/content/stories?term=' . $php->id)->getBody();

        self::assertMatchesRegularExpression(
            '~<label class="admin-filter"><span class="admin-filter-label">Sections</span>\s*<select name="term">\s*<option value="">All</option>\s*'
            . '<option value="' . $guides->id . '">Guides</option>\s*<option value="' . $php->id . '" selected>— PHP &lt;b&gt;</option>\s*</select>~',
            $list,
        );
        self::assertStringNotContainsString('<optgroup', $list);
        self::assertStringNotContainsString('>News</option>', $list, 'no tags');
    }

    public function testTheSectionIsOneChoiceFromTheTree(): void
    {
        $guides = $this->term('sections', 'Guides <i>');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $about = $this->term('sections', 'About');
        $this->term('sections', 'Разделы', ['locale' => 'ru']);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/stories/new')->getBody();

        self::assertStringContainsString('<label for="terms-sections">Sections</label>', $form);
        self::assertStringContainsString('<select id="terms-sections" name="terms[sections][]" required>', $form);
        self::assertStringContainsString('<option value="">Choose…</option>', $form);
        self::assertStringContainsString('<option value="' . $guides->id . '" disabled>Guides &lt;i&gt;</option>', $form);
        self::assertStringContainsString('<option value="' . $php->id . '">— PHP</option>', $form);
        self::assertStringContainsString('<option value="' . $about->id . '">About</option>', $form);
        self::assertStringNotContainsString('Разделы', $form);
        self::assertStringNotContainsString('type="checkbox" name="terms[sections][]"', $form);

        $response = $this->browser->post('/admin/content/stories', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'terms' => ['sections' => [(string) $php->id]],
        ]);
        $entry = $this->app->container()->service(EntryRepository::class)->findBySlug('stories', 'en', 'hello');

        self::assertSame(303, $response->getStatusCode());
        self::assertNotNull($entry);
        self::assertSame(['PHP'], array_map(static fn(Term $t): string => $t->name, $this->terms()->forEntries([$entry->id])[$entry->id] ?? []));
        self::assertStringContainsString(
            '<option value="' . $php->id . '" selected>— PHP</option>',
            (string) $this->browser->get('/admin/content/stories/' . $entry->id)->getBody(),
        );
    }

    public function testTheChosenSectionStaysSubmittableAfterItGainsSubsections(): void
    {
        $guides = $this->term('sections', 'Guides');
        $about = $this->term('sections', 'About');
        $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->term('sections', 'Team', ['parent' => $about]);
        $story = $this->entry('stories', 'Hello', ['terms' => ['sections' => [$guides->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $form = (string) $this->browser->get('/admin/content/stories/' . $story->id)->getBody();

        self::assertStringContainsString('<option value="' . $guides->id . '" selected>Guides</option>', $form);
        self::assertStringNotContainsString('<option value="' . $guides->id . '" selected disabled>', $form);
        self::assertStringContainsString('<option value="' . $about->id . '" disabled>About</option>', $form);
    }

    public function testChoosingNoSectionIsAFieldError(): void
    {
        $this->term('sections', 'Guides');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->browser->post('/admin/content/stories', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'title' => 'Hello',
            'status' => 'draft',
            'terms' => ['sections' => ['']],
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<select id="terms-sections" name="terms[sections][]" required aria-invalid="true" aria-describedby="terms-sections-error">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="terms-sections-error">This field is required.</span>', $body);
    }

    public function testTheListShowsEachStorysSection(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->entry('stories', 'Hello', ['terms' => ['sections' => [$guides->id]]]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $list = (string) $this->browser->get('/admin/content/stories')->getBody();

        self::assertStringContainsString('<th>Sections</th>', $list);
        self::assertStringContainsString('<td data-label="Sections">Guides</td>', $list);
        self::assertStringNotContainsString('<th>Sections</th>', (string) $this->browser->get('/admin/content/articles')->getBody());
    }
}
