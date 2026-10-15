<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MenuLink;
use AtarCms\Content\Menu\PublicMenus;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Tests\Support\Cast;
use AtarCms\View\ThemeConfig;
use InvalidArgumentException;

final class PublicMenusTest extends ContentTestCase
{
    public function testBuildsTheVisibleTreeWithCurrentFlags(): void
    {
        $about = $this->insertEntry(['collection' => 'pages', 'slug' => 'about', 'title' => 'About <b>']);
        $team = $this->insertEntry(['collection' => 'pages', 'slug' => 'team', 'title' => 'Team', 'parent_id' => $about]);
        $draft = $this->insertEntry(['collection' => 'pages', 'slug' => 'draft', 'title' => 'Draft', 'status' => 'draft', 'published_at' => null]);
        $soon = $this->insertEntry(['slug' => 'soon', 'title' => 'Soon', 'status' => 'scheduled', 'published_at' => $this->clock->now()->modify('+1 day')]);
        $gone = $this->insertEntry(['slug' => 'gone', 'title' => 'Gone']);
        $php = $this->insertTerm();
        $north = $this->insertTerm(['taxonomy' => 'regions', 'slug' => 'north', 'name' => 'North']);

        $this->insertMenuItem(['label' => 'Home', 'url' => '/', 'position' => 0]);
        $aboutItem = $this->insertMenuItem(self::entryItem($about, ['position' => 1]));
        $this->insertMenuItem(self::entryItem($team, ['label' => 'Our team', 'parent_id' => $aboutItem, 'position' => 0]));
        $draftItem = $this->insertMenuItem(self::entryItem($draft, ['label' => 'Draft', 'parent_id' => $aboutItem, 'position' => 1]));
        $this->insertMenuItem(['label' => 'Below a draft', 'url' => '/below', 'parent_id' => $draftItem]);
        $this->insertMenuItem(['label' => '', 'target_type' => 'term', 'term_id' => $php, 'url' => null, 'position' => 2]);
        $this->insertMenuItem(['label' => 'North', 'target_type' => 'term', 'term_id' => $north, 'url' => null, 'position' => 3]);
        $this->insertMenuItem(self::entryItem($soon, ['label' => 'Soon', 'position' => 4]));
        $this->insertMenuItem(self::entryItem($gone, ['label' => 'Gone', 'position' => 5]));
        $this->insertMenuItem(['label' => 'Docs', 'url' => 'https://example.org/docs', 'new_tab' => true, 'position' => 6]);
        $this->insertMenuItem(['label' => 'Imprint', 'location' => 'footer']);
        $this->insertMenuItem(['label' => 'Главная', 'locale' => 'ru']);
        $this->db->execute('DELETE FROM entries WHERE id = ?', [$gone]);

        self::assertSame([
            ['Home', '/', false, false, false, []],
            ['About <b>', '/pages/about', false, true, false, [['Our team', '/pages/about/team', true, false, false, []]]],
            ['PHP', '/topics/php', false, false, false, []],
            ['Docs', 'https://example.org/docs', false, false, true, []],
        ], self::shape($this->publicMenus()->tree('main', 'en', '/pages/about/team/')));
        self::assertSame([['Imprint', '/', false, false, false, []]], self::shape($this->publicMenus()->tree('footer', 'en')));
        self::assertSame([['Главная', '/', true, false, false, []]], self::shape($this->publicMenus()->tree('main', 'ru', '/')));
        self::assertSame([], $this->publicMenus()->tree('main', 'de'));
        self::assertSame('Home', (string) $this->publicMenus()->tree('main', 'en')[0]);
    }

    public function testLoadsTargetsInBatchesInsteadOfOneQueryPerItem(): void
    {
        $about = $this->insertEntry(['collection' => 'pages', 'slug' => 'about', 'title' => 'About']);
        for ($i = 1; $i <= 5; $i++) {
            $page = $this->insertEntry(['collection' => 'pages', 'slug' => 'child-' . $i, 'title' => 'Child ' . $i, 'parent_id' => $about]);
            $this->insertMenuItem(['label' => '', 'target_type' => 'entry', 'entry_id' => $page, 'url' => null, 'position' => $i]);
        }
        foreach (['php', 'go'] as $i => $slug) {
            $term = $this->insertTerm(['slug' => $slug, 'name' => strtoupper($slug)]);
            $this->insertMenuItem(['label' => '', 'target_type' => 'term', 'term_id' => $term, 'url' => null, 'position' => 10 + $i]);
        }
        $this->insertMenuItem(['label' => 'Docs', 'url' => '/docs', 'position' => 20]);
        $menus = $this->publicMenus();
        $selects = fn(): int => Cast::int($this->db->selectOne("SHOW SESSION STATUS LIKE 'Com_select'")['Value'] ?? 0);

        $before = $selects();
        $links = $menus->tree('main', 'en');
        $queries = $selects() - $before;

        self::assertCount(8, $links);
        self::assertSame(['/pages/about/child-1', '/topics/php', '/docs'], [$links[0]->url, $links[5]->url, $links[7]->url]);
        self::assertSame(4, $queries, 'the items, their entries, the parent pages (one level), their terms');
    }

    public function testNestedPagesCostOneAncestorQueryWhateverTheirDepth(): void
    {
        $parent = null;
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $slug) {
            $parent = $this->insertEntry(['collection' => 'pages', 'slug' => $slug, 'title' => strtoupper($slug), 'parent_id' => $parent]);
            if (in_array($slug, ['a', 'c', 'e'], true)) {
                $this->insertMenuItem(['label' => '', 'target_type' => 'entry', 'entry_id' => $parent, 'url' => null, 'position' => $i]);
            }
        }
        $menus = $this->publicMenus();
        $selects = fn(): int => Cast::int($this->db->selectOne("SHOW SESSION STATUS LIKE 'Com_select'")['Value'] ?? 0);

        $before = $selects();
        $links = $menus->tree('main', 'en');
        $queries = $selects() - $before;

        self::assertSame(['/pages/a', '/pages/a/b/c', '/pages/a/b/c/d/e'], array_map(static fn(MenuLink $link): string => $link->url, $links));
        self::assertSame(3, $queries, 'the items, their entries, all ancestors at once (no terms)');
    }

    /**
     * ContentViewHelpers::menu() passes the request's full path, WITH the locale prefix, as $currentPath: entry
     * and term URLs carry the prefix too (LocalizedUrls), so a "ru" entry item is only marked current when
     * $currentPath keeps the "/ru" prefix the entry's own resolved URL has.
     */
    public function testAnEntryItemInARussianMenuIsCurrentOnItsPrefixedUrl(): void
    {
        $about = $this->insertEntry(['collection' => 'pages', 'locale' => 'ru', 'slug' => 'o-nas', 'title' => 'О нас']);
        $this->insertMenuItem(['label' => '', 'target_type' => 'entry', 'entry_id' => $about, 'url' => null, 'locale' => 'ru']);

        self::assertSame(
            [['О нас', '/ru/pages/o-nas', true, false, false, []]],
            self::shape($this->publicMenus()->tree('main', 'ru', '/ru/pages/o-nas')),
        );
        self::assertSame(
            [['О нас', '/ru/pages/o-nas', false, false, false, []]],
            self::shape($this->publicMenus()->tree('main', 'ru', '/pages/o-nas')),
            'stripping the locale prefix (RequestPath) would wrongly miss the match',
        );
    }

    public function testTheMainMenuIsTheSectionTreeWithThePinnedCollections(): void
    {
        $guides = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'guides', 'name' => 'Guides', 'position' => 1]);
        $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'php', 'name' => 'PHP <b>', 'parent_id' => $guides]);
        $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'about', 'name' => 'About', 'position' => 2]);
        $this->insertTerm(['taxonomy' => 'sections', 'locale' => 'ru', 'slug' => 'razdel', 'name' => 'Раздел']);
        $this->insertMenuItem(['label' => 'Ignored', 'url' => '/ignored']);
        $this->insertMenuItem(['label' => 'Imprint', 'url' => '/imprint', 'location' => 'footer']);
        $menus = $this->publicMenus(['welcome', 'stories']);

        self::assertSame([
            ['Welcome', '/welcome', false, false, false, []],
            ['Stories', '/stories', false, false, false, []],
            ['Guides', '/guides', false, true, false, [['PHP <b>', '/guides/php', false, true, false, []]]],
            ['About', '/about', false, false, false, []],
        ], self::shape($menus->tree('main', 'en', '/guides/php/hello')), 'the pinned items first until an order is saved; the menu items of "main" are not used');
        self::assertSame([['Imprint', '/imprint', false, false, false, []]], self::shape($menus->tree('footer', 'en')));

        $this->settingsService()->saveMainMenuOrder(['welcome' => 0, 'stories' => 3], null, null);

        self::assertSame([
            ['Welcome', '/welcome', true, false, false, []],
            ['Guides', '/guides', false, false, false, [['PHP <b>', '/guides/php', false, false, false, []]]],
            ['About', '/about', false, false, false, []],
            ['Stories', '/stories', false, false, false, []],
        ], self::shape($menus->tree('main', 'en', '/welcome')));
        self::assertSame(['Stories', '/stories', false, true, false, []], self::shape($menus->tree('main', 'en', '/stories/hello'))[3], 'the archive leads to its entries');
        self::assertSame(
            [['Добро пожаловать', '/ru/welcome', false, false, false, []], ['Раздел', '/ru/razdel', false, false, false, []], ['Stories', '/ru/stories', false, false, false, []]],
            self::shape($menus->tree('main', 'ru')),
            'each language has its own sections; the pinned items keep their places',
        );
        self::assertSame(['main_menu.reordered'], $this->auditActions());
    }

    public function testAPinnedSingleIsNeverTheAncestorOfAPageWhileAnArchiveIs(): void
    {
        $menus = $this->publicMenus(['welcome', 'stories']);

        self::assertSame(
            [['Добро пожаловать', '/ru/welcome', false, false, false, []], ['Stories', '/ru/stories', false, true, false, []]],
            self::shape($menus->tree('main', 'ru', '/ru/stories/hello')),
        );
        self::assertSame(
            [['Добро пожаловать', '/ru/welcome', false, false, false, []], ['Stories', '/ru/stories', false, false, false, []]],
            self::shape($menus->tree('main', 'ru', '/ru/welcome/other')),
            'a single has no pages below it (a home link "/ru" would otherwise be the ancestor of every Russian page)',
        );
    }

    public function testTheMainMenuCostsTwoQueries(): void
    {
        $guides = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'guides', 'name' => 'Guides']);
        $php = $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'php', 'name' => 'PHP', 'parent_id' => $guides]);
        $this->insertTerm(['taxonomy' => 'sections', 'slug' => 'basics', 'name' => 'Basics', 'parent_id' => $php]);
        $menus = $this->publicMenus(['welcome']);
        $selects = fn(): int => Cast::int($this->db->selectOne("SHOW SESSION STATUS LIKE 'Com_select'")['Value'] ?? 0);

        $before = $selects();
        $links = $menus->tree('main', 'en');
        $queries = $selects() - $before;

        self::assertSame('/guides/php/basics', $links[1]->children[0]->children[0]->url);
        self::assertSame(2, $queries, 'the terms of the sections and the stored order');
    }

    public function testUndeclaredLocationsAreProgrammingErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown menu location "sidebar"');

        $this->publicMenus()->tree('sidebar', 'en');
    }

    /**
     * @param list<string>|null $mainMenu content.main_menu of the configuration (none: "main" is an ordinary menu)
     */
    private function publicMenus(?array $mainMenu = null): PublicMenus
    {
        return new PublicMenus(
            $this->menuRepository(),
            new ThemeConfig(['main' => 'Main', 'footer' => 'Footer']),
            $this->entries,
            $this->terms,
            $this->entryUrls(),
            $this->termUrls(),
            $this->clock,
            MainMenu::fromConfig(new Config($mainMenu === null ? [] : ['content' => ['main_menu' => $mainMenu]]), $this->collections),
            $this->siteSettings(),
            $this->localizedUrls(),
            new Locales('en', ['en', 'ru']),
            $this->translator(),
        );
    }

    /**
     * @param list<MenuLink> $links
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: bool, 4: bool, 5: list<mixed>}>
     */
    private static function shape(array $links): array
    {
        return array_map(
            static fn(MenuLink $link): array => [$link->label, $link->url, $link->current, $link->ancestorOfCurrent, $link->newTab, self::shape($link->children)],
            $links,
        );
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function entryItem(int $entryId, array $extra = []): array
    {
        return ['label' => '', 'target_type' => 'entry', 'entry_id' => $entryId, 'url' => null, ...$extra];
    }
}
