<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\AdminMenu;
use AtarCms\Admin\AdminMenuGroup;
use AtarCms\Admin\AdminMenuItem;
use AtarCms\Admin\AdminModule;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Container;
use AtarCms\View\ThemeConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AdminMenuTest extends TestCase
{
    public function testSortsItemsByOrderThenLabel(): void
    {
        $menu = new AdminMenu();
        $menu->add('Users', 'admin.users', 'users.manage', 80);
        $menu->add('Dashboard', 'admin.dashboard', null, 0);
        $menu->add('Audit log', 'admin.audit', 'audit.view', 80);

        self::assertSame(
            ['Dashboard', 'Audit log', 'Users'],
            array_map(static fn(AdminMenuItem $item): string => $item->label, $menu->items()),
        );
        self::assertSame('users.manage', $menu->items()[2]->permission);
    }

    public function testItemsCarryParamsAndKnowWhenTheyAreCurrent(): void
    {
        $router = new Router();
        $router->get('/admin/users', [stdClass::class, 'x'], 'admin.users');
        $router->get('/admin/users/{id}', [stdClass::class, 'x'], 'admin.users.edit');
        $router->get('/admin/content/{collection}', [stdClass::class, 'x'], 'admin.entries');
        $router->get('/admin/content/{collection}/{id}', [stdClass::class, 'x'], 'admin.entries.edit');
        $router->get('/admin/usersx', [stdClass::class, 'x'], 'admin.usersx');

        $menu = new AdminMenu();
        $menu->add('admin.menu.users', 'admin.users', 'users.manage', 80);
        $menu->add('News', 'admin.entries', 'entries.news.view', 10, ['collection' => 'news']);
        [$news, $users] = $menu->items();

        self::assertSame(['collection' => 'news'], $news->params);
        self::assertSame([], $users->params);
        self::assertTrue($news->isCurrent($router->match('GET', '/admin/content/news')));
        self::assertTrue($news->isCurrent($router->match('GET', '/admin/content/news/5')));
        self::assertFalse($news->isCurrent($router->match('GET', '/admin/content/pages')));
        self::assertTrue($users->isCurrent($router->match('GET', '/admin/users/7')));
        self::assertFalse($users->isCurrent($router->match('GET', '/admin/usersx')));
        self::assertFalse($users->isCurrent(null));
    }

    public function testOrderBandsKeepLongListsBeforeModuleItemsUsersAndAudit(): void
    {
        $container = new Container();
        $content = new ContentModule();
        $content->services($container);
        $taxonomies = [];
        for ($i = 0; $i < 25; $i++) {
            // Labels sort the other way round: the config order must win within a band.
            $taxonomies[] = Taxonomy::make(sprintf('t%02d', $i))->label(sprintf('Taxonomy %02d', 24 - $i));
        }
        $container->set(Collections::class, new Collections([Collection::make('pages')->label('Zzz pages')], new Taxonomies($taxonomies)));

        $menu = new AdminMenu();
        new AdminModule()->adminMenu($menu);
        $content->adminMenu($menu);
        $menu->add('Newsletter', 'admin.newsletter', 'newsletter.manage');

        $labels = array_map(static fn(AdminMenuItem $item): string => $item->label, $menu->items());
        self::assertSame(['admin.menu.dashboard', 'Zzz pages', 'Taxonomy 24', 'Taxonomy 23'], array_slice($labels, 0, 4));
        self::assertSame('Taxonomy 00', $labels[26]);
        self::assertSame(['admin.menu.media', 'Newsletter', 'admin.menu.settings', 'admin.menu.users', 'admin.menu.audit'], array_slice($labels, -5));
        self::assertCount(32, $labels);
    }

    public function testItemsCarryAnIconThatDefaultsToACircle(): void
    {
        $menu = new AdminMenu();
        $menu->add('Newsletter', 'admin.newsletter', 'newsletter.manage');
        $menu->add('Shop', 'admin.shop', null, AdminMenu::MODULES, [], 'tags');

        self::assertSame(['circle', 'tags'], array_map(static fn(AdminMenuItem $item): string => $item->icon, $menu->items()));
    }

    public function testAnIconNameOutsideTheSpriteAlphabetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AdminMenu()->add('Shop', 'admin.shop', null, AdminMenu::MODULES, [], 'Shop"x');
    }

    /**
     * A name of the right alphabet that is not in the sprite (a typo in Collection::icon(), Taxonomy::icon() or a
     * module's item) would render a blank icon: the admin refuses the menu instead, naming the item and the icon.
     */
    public function testAnIconMissingFromTheSpriteIsAConfigError(): void
    {
        $menu = new AdminMenu();
        $menu->add('Events', 'admin.entries', null, AdminMenu::CONTENT, ['collection' => 'events'], 'calendar');
        $menu->assertIcons(['calendar', 'circle']);
        $menu->add('Regions', 'admin.terms', null, AdminMenu::TAXONOMIES, ['taxonomy' => 'regions'], 'map-pn');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('The admin menu item "Regions" uses the icon "map-pn", which is not in resources/admin/assets/icons.svg');

        $menu->assertIcons(['calendar', 'circle']);
    }

    /**
     * Items added while a module fills the menu (AdminModule calls from() before each module's adminMenu()) carry
     * that module, and the icon error names it.
     */
    public function testTheIconErrorNamesTheModule(): void
    {
        $menu = new AdminMenu();
        $menu->from(ContentModule::class);
        $menu->add('Regions', 'admin.terms', null, AdminMenu::TAXONOMIES, ['taxonomy' => 'regions'], 'map-pn');

        self::assertSame(ContentModule::class, $menu->items()[0]->module);
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('The admin menu item "Regions" of ' . ContentModule::class . ' uses the icon "map-pn"');

        $menu->assertIcons(['calendar', 'circle']);
    }

    public function testTheCoreItemsGetTheirIcons(): void
    {
        $container = new Container();
        $content = new ContentModule();
        $content->services($container);
        $container->set(Collections::class, new Collections(
            [
                Collection::make('news')->route('/news/{slug}')->archive('/news'),
                Collection::make('home')->route('/')->single(),
                Collection::make('notes'),
                Collection::make('events')->icon('calendar'),
            ],
            new Taxonomies([Taxonomy::make('tags'), Taxonomy::make('topics')->hierarchical(), Taxonomy::make('regions')->icon('map-pin')]),
        ));
        $container->set(ThemeConfig::class, new ThemeConfig(['main' => 'Main']));

        $menu = new AdminMenu();
        new AdminModule()->adminMenu($menu);
        $content->adminMenu($menu);

        $icons = [];
        foreach ($menu->items() as $item) {
            $icons[$item->label] = $item->icon;
        }
        self::assertSame([
            'admin.menu.dashboard' => 'layout-dashboard',
            'news' => 'newspaper',
            'home' => 'home',
            'notes' => 'file-text',
            'events' => 'calendar',
            'tags' => 'tag',
            'topics' => 'folder-tree',
            'regions' => 'map-pin',
            'admin.menu.menus' => 'list-tree',
            'admin.menu.media' => 'image',
            'admin.menu.settings' => 'settings',
            'admin.menu.users' => 'users',
            'admin.menu.audit' => 'history',
        ], $icons);
        $sprite = (string) file_get_contents(__DIR__ . '/../../../resources/admin/assets/icons.svg');
        foreach ($icons as $icon) {
            self::assertStringContainsString('<symbol id="' . $icon . '"', $sprite, $icon);
        }
    }

    public function testTheSectionsHaveTheirOwnScreenAndTheMenusScreenNeedsAnotherLocation(): void
    {
        $container = new Container();
        $content = new ContentModule();
        $content->services($container);
        $container->set(Collections::class, new Collections(
            [Collection::make('home')->route('/')->single()],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}'), Taxonomy::make('tags')]),
        ));
        $container->set(ThemeConfig::class, new ThemeConfig(['main' => 'Main']));
        $container->set(Config::class, new Config(['content' => ['main_menu' => ['home']]]));

        $menu = new AdminMenu();
        $content->adminMenu($menu);

        $routes = array_map(static fn(AdminMenuItem $item): array => [$item->label, $item->route, $item->params], $menu->items());
        self::assertContains(['sections', 'admin.sections', []], $routes);
        self::assertContains(['tags', 'admin.terms', ['taxonomy' => 'tags']], $routes);
        self::assertNotContains('admin.menus', array_column($routes, 1), 'the main menu is the sections');
    }

    public function testTheSidebarGroupsItemsByTheirOrderBands(): void
    {
        $menu = new AdminMenu();
        $menu->add('Audit', 'admin.audit', null, AdminMenu::AUDIT);
        $menu->add('Dashboard', 'admin.dashboard', null, AdminMenu::DASHBOARD);
        $menu->add('News', 'admin.entries', null, AdminMenu::CONTENT, ['collection' => 'news']);
        $menu->add('Pages', 'admin.entries', null, AdminMenu::CONTENT + 1, ['collection' => 'pages']);
        $menu->add('Tags', 'admin.terms', null, AdminMenu::TAXONOMIES, ['taxonomy' => 'tags']);
        $menu->add('Menus', 'admin.menus', null, AdminMenu::MENUS);
        $menu->add('Media', 'admin.media', null, AdminMenu::MEDIA);
        $menu->add('Users', 'admin.users', null, AdminMenu::USERS);
        $menu->add('Site', 'admin.settings', null, AdminMenu::SETTINGS);

        $groups = AdminMenuGroup::of($menu->items());

        self::assertSame(
            [
                [null, ['Dashboard']],
                ['admin.menu.group.content', ['News', 'Pages']],
                ['admin.menu.group.structure', ['Tags', 'Menus']],
                ['admin.menu.group.files', ['Media']],
                ['admin.menu.group.settings', ['Site', 'Users', 'Audit']],
            ],
            array_map(static fn(AdminMenuGroup $group): array => [$group->heading, array_map(static fn(AdminMenuItem $item): string => $item->label, $group->items)], $groups),
        );

        $menu->add('Newsletter', 'admin.newsletter');
        $withModule = AdminMenuGroup::of($menu->items());
        self::assertSame('admin.menu.group.modules', $withModule[4]->heading);
        self::assertSame(['Newsletter'], array_map(static fn(AdminMenuItem $item): string => $item->label, $withModule[4]->items));
        self::assertSame([], AdminMenuGroup::of([]));
    }
}
