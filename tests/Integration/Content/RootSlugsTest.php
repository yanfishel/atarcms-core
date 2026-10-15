<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\ContentModule;
use AtarCms\Content\EntryInput;
use AtarCms\Content\RootSlugs;
use AtarCms\Content\TermInput;
use AtarCms\Content\TreeNode;
use AtarCms\Content\TreeOrder;
use AtarCms\Http\Routing\Router;

/**
 * The fixture's "info" collection and "sections" taxonomy share "/{slug}": a page and a top-level section of one
 * language never take the same slug. A subsection lives under its parent ("/guides/about") and competes with nothing.
 */
final class RootSlugsTest extends ContentTestCase
{
    public function testPagesAndSectionsOfOneLanguageNeverShareASlug(): void
    {
        $info = $this->collections->get('info');
        $sections = $this->collections->taxonomies->get('sections');
        $this->service()->create($info, 'en', new EntryInput('About', slug: 'about'), null, null);
        $this->termService()->create($sections, 'en', new TermInput('Guides', 'guides'), null, null);

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $this->termService()->create($sections, 'en', new TermInput('About', 'about'), null, null),
        );
        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $this->service()->create($info, 'en', new EntryInput('Guides', slug: 'guides'), null, null),
        );
        self::assertSame('about-2', $this->termService()->create($sections, 'en', new TermInput('About'), null, null)->slug);
        self::assertSame('guides-2', $this->service()->create($info, 'en', new EntryInput('Guides'), null, null)->slug);
        self::assertSame('about', $this->termService()->create($sections, 'ru', new TermInput('About', 'about'), null, null)->slug, 'another language is free');
    }

    public function testRenamesAreCheckedOwnSlugsStayAndSubsectionsAreFree(): void
    {
        $info = $this->collections->get('info');
        $sections = $this->collections->taxonomies->get('sections');
        $about = $this->service()->create($info, 'en', new EntryInput('About', slug: 'about'), null, null);
        $guides = $this->termService()->create($sections, 'en', new TermInput('Guides', 'guides'), null, null);

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $this->termService()->update($guides, new TermInput('Guides', 'about'), null, null),
        );
        $subsection = $this->termService()->create($sections, 'en', new TermInput('About', 'about', $guides->id), null, null);
        self::assertSame('about', $subsection->slug, 'it lives at /guides/about');
        self::assertSame('team', $this->termService()->create($sections, 'en', new TermInput('Team', parentId: $guides->id), null, null)->slug);
        self::assertSame('team', $this->service()->create($info, 'en', new EntryInput('Team'), null, null)->slug, 'a page may take a subsection\'s slug');
        self::assertSame('about', $this->service()->update($about, new EntryInput('About us', slug: 'about'), null, null)->slug);
        self::assertSame('guides', $this->termService()->update($guides, new TermInput('All guides', 'guides'), null, null)->slug);
        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $this->termService()->update($subsection, new TermInput('About', 'about'), null, null),
            [],
        );
    }

    public function testASubsectionCannotMoveToTheTopLevelWhereAPageTakesItsSlug(): void
    {
        $sections = $this->collections->taxonomies->get('sections');
        $this->service()->create($this->collections->get('info'), 'en', new EntryInput('About', slug: 'about'), null, null);
        $guides = $this->termService()->create($sections, 'en', new TermInput('Guides'), null, null);
        $about = $this->termService()->create($sections, 'en', new TermInput('About', 'about', $guides->id), null, null);
        $version = TreeOrder::version($this->terms->forTaxonomy('sections', 'en'));

        self::assertValidation(
            ['tree' => 'validation.tree.reserved'],
            fn() => $this->termService()->reorder($sections, 'en', [new TreeNode($guides->id), new TreeNode($about->id)], $version, null, null),
            ['tree' => ['title' => 'About']],
        );
        self::assertSame($guides->id, $this->terms->find($about->id)?->parentId);
    }

    public function testABlankSlugCannotMoveASubsectionToTheTopLevelOntoAPageSlug(): void
    {
        $sections = $this->collections->taxonomies->get('sections');
        $this->service()->create($this->collections->get('info'), 'en', new EntryInput('Team', slug: 'team'), null, null);
        $guides = $this->termService()->create($sections, 'en', new TermInput('Guides'), null, null);
        $team = $this->termService()->create($sections, 'en', new TermInput('Team', 'team', $guides->id), null, null);

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $this->termService()->update($team, new TermInput('Team', ''), null, null),
        );
        self::assertSame($guides->id, $this->terms->find($team->id)?->parentId);
        self::assertSame('team', $this->termService()->update($team, new TermInput('Team', '', $guides->id), null, null)->slug);
    }

    public function testRouteSegmentsAndLanguagesAreReservedForTopLevelSectionsOnly(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, $this->collections);
        $service = $this->termService($router);
        $sections = $this->collections->taxonomies->get('sections');
        $guides = $service->create($sections, 'en', new TermInput('Guides'), null, null);

        self::assertValidation(['slug' => 'validation.slug.reserved'], static fn() => $service->create($sections, 'en', new TermInput('Contact', 'contact'), null, null));
        self::assertValidation(['slug' => 'validation.slug.reserved'], static fn() => $service->create($sections, 'en', new TermInput('Russian', 'ru'), null, null));
        self::assertSame('contact-2', $service->create($sections, 'en', new TermInput('Contact'), null, null)->slug);
        self::assertSame('contact', $service->create($sections, 'en', new TermInput('Contact', '', $guides->id), null, null)->slug, 'it lives at /guides/contact');
        self::assertSame('ru', $service->create($sections, 'en', new TermInput('Russian', 'ru', $guides->id), null, null)->slug);
    }

    public function testATranslationOfAPageGetsAFreeSlugWhereASectionTakesIt(): void
    {
        $info = $this->collections->get('info');
        $sections = $this->collections->taxonomies->get('sections');
        $page = $this->service()->create($info, 'en', new EntryInput('About', slug: 'about'), null, null);
        $this->termService()->create($sections, 'ru', new TermInput('About', 'about'), null, null);

        $translation = $this->service()->createTranslation($page, 'ru', null, null);

        self::assertSame('ru', $translation->locale);
        self::assertSame('about-2', $translation->slug);
    }

    public function testOnlyTheOtherOwnersOfTheRootCount(): void
    {
        $roots = new RootSlugs($this->collections, $this->entries, $this->terms);
        $this->service()->create($this->collections->get('info'), 'en', new EntryInput('About', slug: 'about'), null, null);
        $this->termService()->create($this->collections->taxonomies->get('topics'), 'en', new TermInput('Topics', 'php'), null, null);
        $guides = $this->termService()->create($this->collections->taxonomies->get('sections'), 'en', new TermInput('Guides'), null, null);
        $this->termService()->create($this->collections->taxonomies->get('sections'), 'en', new TermInput('Team', 'team', $guides->id), null, null);

        self::assertTrue($roots->taken($this->collections->taxonomies->get('sections'), 'en', 'about'));
        self::assertFalse($roots->taken($this->collections->get('info'), 'en', 'about'), 'its own entries are checked by slugExists()');
        self::assertFalse($roots->taken($this->collections->taxonomies->get('sections'), 'ru', 'about'));
        self::assertFalse($roots->taken($this->collections->get('info'), 'en', 'php'), 'topics live under /topics/');
        self::assertTrue($roots->taken($this->collections->get('info'), 'en', 'guides'));
        self::assertFalse($roots->taken($this->collections->get('info'), 'en', 'team'), 'a subsection lives under its parent');
    }
}
