<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Admin\AdminMenu;
use AtarCms\Cli\Command\CollectionsSyncCommand;
use AtarCms\Cli\Command\DemoSeedCommand;
use AtarCms\Cli\Command\ShareImagesRebuildCommand;
use AtarCms\Cli\Command\ThemeCheckCommand;
use AtarCms\Cli\Command\ThemeNewCommand;
use AtarCms\Content\Http\ContentController;
use AtarCms\Content\Http\FeedController;
use AtarCms\Content\Http\LlmsTxtController;
use AtarCms\Content\Http\RobotsController;
use AtarCms\Content\Http\RootController;
use AtarCms\Content\Http\SitemapController;
use AtarCms\Content\Http\TermController;
use AtarCms\Content\Markdown\EntryMarkdown;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Demo\DemoStructure;
use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\Module;
use AtarCms\Kernel\Paths;
use AtarCms\View\ThemeConfig;
use LogicException;

/**
 * Collections, entries and public content routes. Application::fromSite() registers it after every other
 * module so that collection routes (which may include a "/{slug}" catch-all) never shadow module routes.
 */
final class ContentModule extends Module
{
    private ?Container $container = null;

    public function services(Container $container): void
    {
        // routes() only receives the router; the container is kept to read the collections there.
        $this->container = $container;
        $container->factory(Collections::class, static fn(Container $c): Collections => Collections::fromConfig($c->service(Config::class)));
        $container->factory(Taxonomies::class, static fn(Container $c): Taxonomies => $c->service(Collections::class)->taxonomies);
        $container->factory(MainMenu::class, static fn(Container $c): MainMenu => MainMenu::fromConfig($c->service(Config::class), $c->service(Collections::class)));
        $container->factory(DemoStructure::class, static fn(Container $c): DemoStructure => DemoStructure::fromConfig($c->service(Config::class)));
        $container->factory(RichTextTheme::class, static fn(Container $c): RichTextTheme => new RichTextTheme(
            $c->service(ThemeConfig::class)->editor,
            $c->service(Paths::class)->theme($c->service(Config::class)->string('app.theme')),
        ));
        $container->factory(Sitemap::class, static fn(Container $c): Sitemap => Sitemap::fromConfig(
            $c->service(Config::class),
            $c->service(Collections::class),
            $c->service(PublicContent::class),
            $c->service(TermRepository::class),
            $c->service(EntryRepository::class),
            $c->service(Locales::class),
        ));
    }

    public function routes(Router $router): void
    {
        $container = $this->container ?? throw new LogicException('ContentModule::services() must run before routes()');
        self::registerRoutes($router, $container->service(Collections::class));
        // A mistake in content.main_menu stops the boot, like one in the collections; so does a preset page another
        // route would hide (every module's routes are registered by now: this module is the last).
        $container->service(MainMenu::class);
        self::checkPresets($router, $container->service(Collections::class), $container->service(Locales::class));
    }

    /**
     * Refuses a preset slug (Collection::presets()) whose address another route answers or that is a locale code
     * under the root prefix: the page could never be reached.
     *
     * @throws ConfigException
     */
    public static function checkPresets(Router $router, Collections $collections, Locales $locales): void
    {
        $reserved = new ReservedSlugs($router, $locales);
        foreach ($collections->all() as $collection) {
            foreach (array_keys($collection->presets) as $slug) {
                if ($reserved->byRoutes($collection, $slug)) {
                    throw new ConfigException(sprintf(
                        'The preset "%s" of collection "%s" is an address another route answers (or a language code); choose another slug',
                        $slug,
                        $collection->name,
                    ));
                }
            }
        }
    }

    public function commands(): array
    {
        return [CollectionsSyncCommand::class, ShareImagesRebuildCommand::class, DemoSeedCommand::class, ThemeCheckCommand::class, ThemeNewCommand::class];
    }

    public function adminMenu(AdminMenu $menu): void
    {
        $container = $this->container ?? throw new LogicException('ContentModule::services() must run before adminMenu()');
        $collections = $container->service(Collections::class);
        foreach (array_values($collections->all()) as $index => $collection) {
            // A plain single is always published, so only a role that may publish it can edit it
            // (EntryAccess::canEditPublished()); for anyone else its item would open a page they cannot change.
            $permission = $collection->permission($collection->plain ? 'publish' : 'view');
            $menu->add($collection->label, 'admin.entries', $permission, AdminMenu::CONTENT + $index, ['collection' => $collection->name], $collection->adminIcon());
        }
        // Structure: the sections (the root tree, with the main menu) first, the other menus right after them, then the
        // other taxonomies in config order. Without sections the menus follow the taxonomies.
        $hasRootTree = false;
        $offset = 2;
        foreach ($collections->taxonomies->all() as $taxonomy) {
            $icon = $taxonomy->icon ?? ($taxonomy->hierarchical ? 'folder-tree' : 'tag');
            if ($taxonomy->isRootTree()) {
                $hasRootTree = true;
                $menu->add($taxonomy->label, 'admin.sections', Taxonomy::PERMISSION, AdminMenu::TAXONOMIES, [], $icon);
            } else {
                $menu->add($taxonomy->label, 'admin.terms', Taxonomy::PERMISSION, AdminMenu::TAXONOMIES + $offset++, ['taxonomy' => $taxonomy->name], $icon);
            }
        }
        if ($container->service(MainMenu::class)->menuLocations($container->service(ThemeConfig::class)) !== []) {
            $menu->add('admin.menu.menus', 'admin.menus', MenuService::PERMISSION, $hasRootTree ? AdminMenu::TAXONOMIES + 1 : AdminMenu::MENUS, [], 'list-tree');
        }
    }

    /**
     * Registers the public routes of every collection and every routed taxonomy. FastRoute tries variable routes
     * in registration order, so routes with a longer literal prefix ("/news/{slug}", "/category/{slug}") come
     * before catch-alls ("/{slug}"). Each route carries the name of its Collection or Taxonomy as a request attribute
     * (Collection::class, Taxonomy::class): route attributes are plain data. The taxonomy routed at "/{slug}" (the
     * sections), a collection routed there (the pages) and the collections routed "/{sections}/{slug}" (the articles)
     * share one root route, registered last: it carries the taxonomy's name and the collection's, matches every chain
     * of segments when there are sections or articles ("/about/team/hello"), and RootController resolves it. It is
     * named after the collection (the taxonomy's route name is a Router::alias()); the articles' own routes are never
     * matched and only build their URLs. Every entry route has a stateless markdown twin (markdownTwin(),
     * EntryUrls::markdownPattern()): the singles' are static and come with the archives, the root route's follows it.
     */
    public static function registerRoutes(Router $router, Collections $collections): void
    {
        $rootTaxonomy = $collections->taxonomies->root();
        $rootCollection = null;
        $sectioned = [];
        foreach ($rootTaxonomy === null ? [] : $collections->all() as $collection) {
            if ($collection->route === Taxonomy::ROOT_ROUTE) {
                $rootCollection ??= $collection;
            }
            if ($collection->routeTaxonomy === $rootTaxonomy?->name) {
                $sectioned[] = $collection;
            }
        }

        $router->get('/sitemap.xml', [SitemapController::class, 'index'], 'content.sitemap')->middleware(RequireDefaultLocale::class)->stateless();
        $router->get('/robots.txt', [RobotsController::class, 'index'], 'content.robots')->middleware(RequireDefaultLocale::class)->stateless();
        $router->get('/llms.txt', [LlmsTxtController::class, 'index'], 'content.llms')->middleware(RequireDefaultLocale::class)->stateless();
        $router->get(
            '/sitemap-{kind:entries|terms}-{source:[a-z][a-z0-9_]{0,31}}-{number:[1-9][0-9]{0,5}}.xml',
            [SitemapController::class, 'page'],
            'content.sitemap.page',
        )->middleware(RequireDefaultLocale::class)->stateless();

        /** @var list<array{0: int, 1: Collection|Taxonomy}> $routed */
        $routed = [];
        foreach ($collections->all() as $collection) {
            if ($collection->route !== null) {
                $routed[] = [self::literalPrefixLength($collection->route), $collection];
            }
        }
        foreach ($collections->taxonomies->all() as $taxonomy) {
            if ($taxonomy->route !== null) {
                $routed[] = [self::literalPrefixLength($taxonomy->route), $taxonomy];
            }
        }
        usort($routed, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        // Archives and feeds are static paths: FastRoute refuses a static route after a variable route that matches it
        // ("/articles" after "/{slug}"), so they all come first.
        foreach ($routed as [, $item]) {
            if ($item instanceof Taxonomy) {
                continue;
            }
            if ($item->archive !== null) {
                $router->get($item->archive, [ContentController::class, 'index'], EntryUrls::indexRoute($item))
                    ->attribute(Collection::class, $item->name);
            }
            $feedPath = $item->feed ? $item->feedPath() : null;
            if ($feedPath !== null) {
                $router->get($feedPath, [FeedController::class, 'index'], EntryUrls::feedRoute($item))
                    ->attribute(Collection::class, $item->name)
                    ->stateless();
            }
            if ($item->single) {
                // A single's twin is a static path too ("/contact.md", "/index.md").
                self::markdownTwin($router, $item, [ContentController::class, 'single'])->attribute(Collection::class, $item->name);
            }
        }
        foreach ($routed as [, $item]) {
            if ($rootTaxonomy !== null && ($item === $rootTaxonomy || $item === $rootCollection || in_array($item, $sectioned, true))) {
                continue;
            }
            if ($item instanceof Taxonomy) {
                $router->get((string) $item->routePattern(), [TermController::class, 'show'], TermUrls::showRoute($item))
                    ->attribute(Taxonomy::class, $item->name);

                continue;
            }
            $router->get(
                (string) $item->routePattern(),
                [ContentController::class, $item->single ? 'single' : 'show'],
                EntryUrls::showRoute($item),
            )->attribute(Collection::class, $item->name);
            if (!$item->single) {
                self::markdownTwin($router, $item, [ContentController::class, 'show'])->attribute(Collection::class, $item->name);
            }
        }
        if ($rootTaxonomy === null) {
            return;
        }
        $nested = $rootTaxonomy->hierarchical || $sectioned !== [];
        $name = $rootCollection === null ? TermUrls::showRoute($rootTaxonomy) : EntryUrls::showRoute($rootCollection);
        $root = $router->get('/{slug:' . ($nested ? Slug::PATH_PATTERN : Slug::PATTERN) . '}', [RootController::class, 'show'], $name)
            ->attribute(Taxonomy::class, $rootTaxonomy->name);
        if ($rootCollection !== null) {
            $root->attribute(Collection::class, $rootCollection->name);
            $router->alias(TermUrls::showRoute($rootTaxonomy), $name);
        }
        if ($rootCollection !== null || $sectioned !== []) {
            // The root route's twin serves the pages and the articles (never the sections: term pages have none).
            $twin = $router->get(
                '/{slug:' . ($nested ? Slug::PATH_PATTERN : Slug::PATTERN) . '}.md',
                [RootController::class, 'show'],
                $rootCollection === null ? null : EntryUrls::markdownRoute($rootCollection),
            )->attribute(Taxonomy::class, $rootTaxonomy->name)->attribute(EntryMarkdown::class, true)->stateless();
            if ($rootCollection !== null) {
                $twin->attribute(Collection::class, $rootCollection->name);
            }
        }
        foreach ($sectioned as $collection) {
            $router->get((string) $collection->routePattern(), [RootController::class, 'show'], EntryUrls::showRoute($collection));
            self::markdownTwin($router, $collection, [RootController::class, 'show']);
        }
    }

    /**
     * The markdown twin of $collection's entry route (EntryUrls::markdownPattern()): the same handler and middleware,
     * stateless, marked with the EntryMarkdown::class attribute.
     *
     * @param array{0: class-string, 1: string} $handler
     */
    private static function markdownTwin(Router $router, Collection $collection, array $handler): Route
    {
        return $router->get((string) EntryUrls::markdownPattern($collection), $handler, EntryUrls::markdownRoute($collection))
            ->attribute(EntryMarkdown::class, true)
            ->stateless();
    }

    private static function literalPrefixLength(string $route): int
    {
        $brace = strpos($route, '{');

        return $brace === false ? strlen($route) : $brace;
    }
}
