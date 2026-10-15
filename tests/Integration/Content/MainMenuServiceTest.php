<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MainMenuService;
use AtarCms\Content\TreeNode;
use AtarCms\Kernel\Config;

/**
 * The Sections screen's order: the fixture's sections with "welcome" (-1) and "stories" (-2) pinned among them.
 */
final class MainMenuServiceTest extends ContentTestCase
{
    public function testTheTreeHoldsTheSectionsAndThePinnedItems(): void
    {
        $guides = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'guides', 'name' => 'Guides']);
        $php = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'php', 'name' => 'PHP', 'parent_id' => $guides]);
        $service = $this->mainMenuService();

        self::assertEquals(
            [new TreeNode(-1), new TreeNode(-2), new TreeNode($guides, [new TreeNode($php)])],
            $service->tree($this->terms->forTaxonomy('sections', 'en')),
        );
        self::assertEquals([new TreeNode($guides, [new TreeNode($php)])], $this->mainMenuService(null)->tree($this->terms->forTaxonomy('sections', 'en')), 'no main menu, no pinned items');
    }

    public function testSavingMovesTheSectionsAndStoresThePinnedPlaces(): void
    {
        $guides = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'guides', 'name' => 'Guides']);
        $about = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'about', 'name' => 'About', 'position' => 1]);
        $service = $this->mainMenuService();
        $version = $service->version($this->terms->forTaxonomy('sections', 'en'));
        $sections = $this->collections->taxonomies->get('sections');

        $service->reorder($sections, 'en', [new TreeNode(-1), new TreeNode($about, [new TreeNode($guides)]), new TreeNode(-2)], $version, null, null);

        self::assertSame($about, $this->terms->find($guides)?->parentId);
        self::assertSame(['welcome' => 0, 'stories' => 2], $this->siteSettings()->mainMenuOrder());
        self::assertEquals(
            [new TreeNode(-1), new TreeNode($about, [new TreeNode($guides)]), new TreeNode(-2)],
            $service->tree($this->terms->forTaxonomy('sections', 'en')),
        );
        self::assertSame(['term.reordered', 'main_menu.reordered'], $this->auditActions());
        self::assertValidation(
            ['tree' => 'validation.tree.stale'],
            static fn() => $service->reorder($sections, 'en', [new TreeNode(-1), new TreeNode($about, [new TreeNode($guides)]), new TreeNode(-2)], $version, null, null),
        );
    }

    public function testPinnedItemsStayAtTheTopLevelAndHoldNothing(): void
    {
        $guides = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'guides', 'name' => 'Guides']);
        $service = $this->mainMenuService();
        $version = $service->version($this->terms->forTaxonomy('sections', 'en'));
        $sections = $this->collections->taxonomies->get('sections');

        foreach ([
            [new TreeNode(-1, [new TreeNode($guides)]), new TreeNode(-2)],
            [new TreeNode($guides, [new TreeNode(-1)]), new TreeNode(-2)],
        ] as $tree) {
            self::assertValidation(['tree' => 'validation.sections.pinned'], static fn() => $service->reorder($sections, 'en', $tree, $version, null, null));
        }
        foreach ([[new TreeNode($guides), new TreeNode(-1)], [new TreeNode($guides), new TreeNode(-1), new TreeNode(-2), new TreeNode(-3)]] as $tree) {
            self::assertValidation(['tree' => 'validation.tree.invalid'], static fn() => $service->reorder($sections, 'en', $tree, $version, null, null));
        }
        self::assertSame([], $this->auditActions());
    }

    /**
     * @param list<string>|null $pinned content.main_menu
     */
    private function mainMenuService(?array $pinned = ['welcome', 'stories']): MainMenuService
    {
        return new MainMenuService(
            MainMenu::fromConfig(new Config($pinned === null ? [] : ['content' => ['main_menu' => $pinned]]), $this->collections),
            $this->termService(),
            $this->settingsService(),
            $this->siteSettings(),
        );
    }
}
