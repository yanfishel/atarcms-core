<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Routing\Router;
use AtarCms\Tests\Support\Cast;
use PDOException;
use stdClass;

final class TermServiceTest extends ContentTestCase
{
    public function testCreatesAndUpdatesTermsWithAudit(): void
    {
        $service = $this->termService();
        $ann = $this->user();

        $term = $service->create($this->topics(), 'en', new TermInput("  PHP \t& Co  "), $ann, '10.0.0.1');
        self::assertSame(['PHP & Co', 'php-co', null, 0], [$term->name, $term->slug, $term->parentId, $term->position]);
        self::assertSame(32, strlen($term->translationGroup));

        $this->clock->advance('+1 hour');
        $renamed = $service->update($term, new TermInput('PHP'), $ann, null);
        self::assertSame(['PHP', 'php-co'], [$renamed->name, $renamed->slug]);
        self::assertSame('2026-09-28 13:00:00', $renamed->updatedAt->format('Y-m-d H:i:s'));
        self::assertSame('php', $service->update($renamed, new TermInput('PHP', 'php', position: 5), $ann, null)->slug);
        self::assertSame(['term.created', 'term.updated', 'term.updated'], $this->auditActions());
    }

    public function testValidation(): void
    {
        $service = $this->termService();
        $service->create($this->topics(), 'en', new TermInput('PHP'), null, null);

        self::assertValidation(['name' => 'validation.field.required'], fn() => $service->create($this->topics(), 'en', new TermInput(" \n "), null, null));
        self::assertValidation(
            ['name' => 'validation.field.too_long'],
            fn() => $service->create($this->topics(), 'en', new TermInput(str_repeat('x', 201)), null, null),
            ['name' => ['max' => 200]],
        );
        self::assertValidation(['slug' => 'validation.slug.invalid'], fn() => $service->create($this->topics(), 'en', new TermInput('Go', 'Go Lang'), null, null));
        self::assertValidation(['slug' => 'validation.term.slug_taken'], fn() => $service->create($this->topics(), 'en', new TermInput('Other', 'php'), null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->create($this->topics(), 'de', new TermInput('Go'), null, null));
        self::assertValidation(['position' => 'validation.field.invalid'], fn() => $service->create($this->topics(), 'en', new TermInput('Go', position: 2147483648), null, null));
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));
    }

    public function testCommasAreRejectedInFlatTaxonomyNamesButAllowedInHierarchicalOnes(): void
    {
        $service = $this->termService();
        $tags = $this->collections->taxonomies->get('tags');

        self::assertValidation(['name' => 'validation.term.comma'], fn() => $service->create($tags, 'en', new TermInput('PHP, web'), null, null));
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM terms')));

        $tag = $service->create($tags, 'en', new TermInput('PHP'), null, null);
        self::assertValidation(['name' => 'validation.term.comma'], fn() => $service->update($tag, new TermInput('PHP, web'), null, null));
        self::assertSame('PHP', $this->terms->find($tag->id)?->name);

        $topic = $service->create($this->topics(), 'en', new TermInput('PHP, web'), null, null);
        self::assertSame('PHP, web', $topic->name);
        self::assertSame('PHP, web, more', $service->update($topic, new TermInput('PHP, web, more'), null, null)->name);
    }

    public function testGeneratedSlugsAreFreePerTaxonomyAndLocale(): void
    {
        $service = $this->termService();

        self::assertSame('php', $service->create($this->topics(), 'en', new TermInput('PHP'), null, null)->slug);
        self::assertSame('php-2', $service->create($this->topics(), 'en', new TermInput('Php'), null, null)->slug);
        self::assertSame('php', $service->create($this->topics(), 'ru', new TermInput('PHP'), null, null)->slug);
        self::assertSame('php', $service->create($this->collections->taxonomies->get('tags'), 'en', new TermInput('PHP'), null, null)->slug);
        self::assertSame('term', $service->create($this->topics(), 'en', new TermInput('!!!'), null, null)->slug);
    }

    public function testTermsCannotTakeTheSlugOfAnotherRouteUnderTheTaxonomyPrefix(): void
    {
        $router = new Router();
        $router->get('/tags/archive', [stdClass::class, 'x'], 'content.archive.index');
        $service = $this->termService($router);
        $tags = $this->collections->taxonomies->get('tags');

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $service->create($tags, 'en', new TermInput('Archive', 'archive'), null, null),
        );
        self::assertSame('archive-2', $service->create($tags, 'en', new TermInput('Archive'), null, null)->slug);
        self::assertSame('archive-3', $this->terms->find($service->tagIds($tags, 'en', ['archive!'], null, null)[0])?->slug);
    }

    public function testParents(): void
    {
        $service = $this->termService();
        $code = $service->create($this->topics(), 'en', new TermInput('Code'), null, null);
        $php = $service->create($this->topics(), 'en', new TermInput('PHP', parentId: $code->id), null, null);
        $russian = $service->create($this->topics(), 'ru', new TermInput('Код'), null, null);
        $tag = $service->create($this->collections->taxonomies->get('tags'), 'en', new TermInput('News'), null, null);

        self::assertSame($code->id, $php->parentId);
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->create($this->topics(), 'en', new TermInput('Go', parentId: $russian->id), null, null));
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->create($this->topics(), 'en', new TermInput('Go', parentId: $tag->id), null, null));
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->create($this->collections->taxonomies->get('tags'), 'en', new TermInput('Sub', parentId: $tag->id), null, null));
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->update($code, new TermInput('Code', parentId: $php->id), null, null));
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->update($code, new TermInput('Code', parentId: $code->id), null, null));
        self::assertValidation(['parent_id' => 'validation.term.parent'], fn() => $service->create($this->topics(), 'en', new TermInput('Go', parentId: 99999), null, null));
    }

    public function testDeleteRemovesTheTermFromEntriesAndRefusesParents(): void
    {
        $service = $this->termService();
        $code = $service->create($this->topics(), 'en', new TermInput('Code'), null, null);
        $php = $service->create($this->topics(), 'en', new TermInput('PHP', parentId: $code->id), null, null);
        $entry = $this->insertEntry();
        $this->terms->assign($entry, 'topics', [$php->id, $code->id]);

        self::assertValidation(['term' => 'validation.term.has_children'], fn() => $service->delete($code, null, null));

        $service->delete($php, $this->user(), null);
        self::assertNull($this->terms->find($php->id));
        self::assertSame([$code->id], $this->terms->idsForEntry($entry));
        self::assertNotNull($this->entries->find($entry));
        self::assertSame(['term.created', 'term.created', 'term.deleted'], $this->auditActions());
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'term.deleted'")), true);
        self::assertSame(['taxonomy' => 'topics', 'locale' => 'en', 'name' => 'PHP', 'slug' => 'php', 'entries' => 1], $meta);
    }

    public function testWithoutAPositionATermGoesLastAmongItsSiblingsAndKeepsItWhileItStays(): void
    {
        $service = $this->termService();
        $code = $service->create($this->topics(), 'en', new TermInput('Code'), null, null);
        $go = $service->create($this->topics(), 'en', new TermInput('Go'), null, null);
        $php = $service->create($this->topics(), 'en', new TermInput('PHP', parentId: $code->id), null, null);
        $ruby = $service->create($this->topics(), 'en', new TermInput('Ruby', parentId: $code->id), null, null);
        $russian = $service->create($this->topics(), 'ru', new TermInput('Код'), null, null);

        self::assertSame([0, 1, 0, 1, 0], [$code->position, $go->position, $php->position, $ruby->position, $russian->position]);
        self::assertSame(0, $service->create($this->collections->taxonomies->get('tags'), 'en', new TermInput('News'), null, null)->position, 'another taxonomy does not count');

        $renamed = $service->update($go, new TermInput('Golang'), null, null);
        self::assertSame(1, $renamed->position, 'the parent is unchanged: the position is kept');

        $moved = $service->update($renamed, new TermInput('Golang', parentId: $code->id), null, null);
        self::assertSame([$code->id, 2], [$moved->parentId, $moved->position], 'a new parent: last among the new siblings');

        self::assertSame(5, $service->update($moved, new TermInput('Golang', position: 5), null, null)->position, 'an explicit position is still honoured');
    }

    public function testCreateTranslation(): void
    {
        $service = $this->termService();
        $code = $service->create($this->topics(), 'en', new TermInput('Code'), null, null);
        $codeRu = $service->createTranslation($code, 'ru', null, null);
        $php = $service->create($this->topics(), 'en', new TermInput('PHP', parentId: $code->id, position: 4), null, null);
        $service->create($this->topics(), 'ru', new TermInput('PHP'), null, null);

        $phpRu = $service->createTranslation($php, 'ru', $this->user(), null);

        self::assertSame(['ru', $php->translationGroup, 'php-2', 'PHP', $codeRu->id, 4], [$phpRu->locale, $phpRu->translationGroup, $phpRu->slug, $phpRu->name, $phpRu->parentId, $phpRu->position]);
        self::assertSame($code->translationGroup, $codeRu->translationGroup);
        self::assertValidation(['locale' => 'validation.locale.exists'], fn() => $service->createTranslation($php, 'ru', null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->createTranslation($php, 'en', null, null));
        self::assertValidation(['locale' => 'validation.locale.unsupported'], fn() => $service->createTranslation($php, 'de', null, null));
        self::assertContains('term.translated', $this->auditActions());
    }

    /**
     * Sections (the root tree) keep a title and a description for search engines per language, cleaned like the
     * entries' (whitespace collapsed, empty is null, 255 and 500 characters at most); every read path returns them.
     * Other taxonomies have none: what a crafted post sends is dropped.
     */
    public function testSectionsKeepTheirTextsForSearchEnginesAndOtherTaxonomiesHaveNone(): void
    {
        $service = $this->termService();
        $sections = $this->collections->taxonomies->get('sections');

        $guides = $service->create($sections, 'en', new TermInput('Guides', metaTitle: " {title}\t— Guides \n", metaDescription: '  Short   guides. '), null, null);
        self::assertSame(['{title} — Guides', 'Short guides.'], [$guides->seo->metaTitle, $guides->seo->metaDescription]);
        $entry = $this->insertEntry(['collection' => 'stories', 'slug' => 'hello']);
        $this->terms->assign($entry, 'sections', [$guides->id]);
        self::assertSame('{title} — Guides', $this->terms->forEntries([$entry])[$entry][0]->seo->metaTitle ?? null, 'the route term of an article');
        $php = $service->create($sections, 'en', new TermInput('PHP', parentId: $guides->id), null, null);
        self::assertSame('Short guides.', $this->terms->ancestors([$php->id])[$guides->id]->seo->metaDescription ?? null);
        self::assertSame([null, null], [$php->seo->metaTitle, $php->seo->metaDescription]);

        $cleared = $service->update($guides, new TermInput('Guides', metaTitle: '  ', metaDescription: ''), null, null);
        self::assertSame([null, null], [$cleared->seo->metaTitle, $cleared->seo->metaDescription]);

        self::assertValidation(
            ['meta_title' => 'validation.field.too_long', 'meta_description' => 'validation.field.too_long'],
            fn() => $service->update($cleared, new TermInput('Guides', metaTitle: str_repeat('t', 256), metaDescription: str_repeat('d', 501)), null, null),
            ['meta_title' => ['max' => 255], 'meta_description' => ['max' => 500]],
        );
        $service->update($cleared, new TermInput('Guides', metaTitle: str_repeat('t', 255), metaDescription: str_repeat('d', 500)), null, null);

        $topic = $service->create($this->topics(), 'en', new TermInput('Code', metaTitle: 'Code', metaDescription: str_repeat('d', 501)), null, null);
        self::assertSame([null, null], [$topic->seo->metaTitle, $topic->seo->metaDescription], 'only sections have them');
    }

    /**
     * The texts belong to one language: a translation starts without them, and moving sections in the tree keeps them.
     */
    public function testTranslationsStartWithoutTheTextsAndReordersKeepThem(): void
    {
        $service = $this->termService();
        $sections = $this->collections->taxonomies->get('sections');
        $guides = $service->create($sections, 'en', new TermInput('Guides', metaTitle: '{title} — Guides', metaDescription: 'All guides.'), null, null);
        $tools = $service->create($sections, 'en', new TermInput('Tools'), null, null);

        $russian = $service->createTranslation($guides, 'ru', null, null);
        self::assertSame([null, null], [$russian->seo->metaTitle, $russian->seo->metaDescription]);

        $service->reorder(
            $sections,
            'en',
            TreeOrder::parse(sprintf('[{"id":%d,"children":[{"id":%d}]}]', $tools->id, $guides->id)),
            TreeOrder::version($this->terms->forTaxonomy('sections', 'en')),
            null,
            null,
        );
        $moved = $this->terms->find($guides->id);
        self::assertSame([$tools->id, '{title} — Guides', 'All guides.'], [$moved?->parentId, $moved?->seo->metaTitle, $moved?->seo->metaDescription]);
    }

    public function testTagIdsReuseExistingNamesAndCreateMissingOnes(): void
    {
        $service = $this->termService();
        $tags = $this->collections->taxonomies->get('tags');
        $news = $service->create($tags, 'en', new TermInput('News'), null, null);

        $ids = $service->tagIds($tags, 'en', ['news', 'Brand new', 'Brand New'], $this->user(), null);

        $created = $this->terms->findBySlug('tags', 'en', 'brand-new');
        self::assertNotNull($created);
        self::assertSame([$news->id, $created->id], $ids);
        self::assertSame('Brand new', $created->name);
        self::assertSame([$news->id], $service->tagIds($tags, 'en', ['NEWS'], null, null));

        $ruIds = $service->tagIds($tags, 'ru', ['News'], null, null);
        self::assertCount(1, $ruIds);
        $ruTerm = $this->terms->find($ruIds[0]);
        self::assertSame(['tags', 'ru'], [$ruTerm?->taxonomy, $ruTerm?->locale]);
        self::assertSame(['term.created', 'term.created', 'term.created'], $this->auditActions());
        $meta = json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE subject_id = ?", [(string) $created->id])), true);
        self::assertIsArray($meta);
        self::assertSame('tags', $meta['via'] ?? null);
    }

    public function testDuplicateKeyViolationsBecomeValidationErrors(): void
    {
        $this->insertTerm(['translation_group' => str_repeat('a', 32)]);

        self::assertSame(['slug' => 'validation.term.slug_taken'], TermService::duplicateError($this->violation(['translation_group' => str_repeat('b', 32)]))?->errors);
        self::assertSame(['locale' => 'validation.locale.exists'], TermService::duplicateError($this->violation(['slug' => 'other', 'translation_group' => str_repeat('a', 32)]))?->errors);
        self::assertNull(TermService::duplicateError($this->violation(['slug' => 'orphan', 'parent_id' => 99999])));
    }

    private function topics(): Taxonomy
    {
        return $this->collections->taxonomies->get('topics');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function violation(array $overrides): PDOException
    {
        try {
            $this->insertTerm($overrides);
        } catch (PDOException $e) {
            return $e;
        }
        self::fail('Expected a database error');
    }
}
