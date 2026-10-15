<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\Http\RootController;
use AtarCms\Content\Markdown\EntryMarkdown;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequireDefaultLocale;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContentRoutesTest extends TestCase
{
    public function testCollectionRoutesNeverShadowModuleRoutesAndSpecificOnesWin(): void
    {
        $router = new Router();
        // A module route: Application registers module routes before ContentModule's.
        $router->get('/news/{slug}', [stdClass::class, 'news'], 'module.news');
        ContentModule::registerRoutes($router, new Collections([
            Collection::make('pages')->route('/{slug}')->hierarchical(),
            Collection::make('stories')->route('/stories/{slug}'),
            Collection::make('home')->route('/')->single(),
            Collection::make('notes'),
        ]));

        self::assertSame('module.news', $router->match('GET', '/news/hello')->route->name);
        $page = $router->match('GET', '/about/team');
        self::assertSame('content.pages.show', $page->route->name);
        self::assertSame(['slug' => 'about/team'], $page->params);
        self::assertSame('pages', $page->route->attributes()[Collection::class] ?? null);
        self::assertSame('content.stories.show', $router->match('GET', '/stories/hello')->route->name);
        self::assertSame('content.home.show', $router->match('GET', '/')->route->name);
        self::assertSame('/about/team', $router->url('content.pages.show', ['slug' => 'about/team']));

        $this->expectException(NotFound::class);
        $router->match('GET', '/About');
    }

    public function testEveryEntryRouteHasAStatelessMarkdownTwin(): void
    {
        $router = new Router();
        // The catch-all comes first in the config: the singles' static twins must still be registered before its twin.
        ContentModule::registerRoutes($router, new Collections([
            Collection::make('pages')->route('/{slug}')->hierarchical(),
            Collection::make('stories')->route('/stories/{slug}'),
            Collection::make('home')->route('/')->single(),
            Collection::make('contact')->route('/contact')->single(),
            Collection::make('notes'),
        ]));

        $page = $router->match('GET', '/about/team.md');
        self::assertSame('content.pages.md', $page->route->name);
        self::assertSame(['slug' => 'about/team'], $page->params);
        self::assertSame('pages', $page->route->attributes()[Collection::class] ?? null);
        self::assertTrue($page->route->attributes()[EntryMarkdown::class] ?? false);
        self::assertTrue($page->route->isStateless());
        self::assertSame('content.stories.md', $router->match('GET', '/stories/hello.md')->route->name);
        self::assertSame('content.home.md', $router->match('GET', '/index.md')->route->name);
        self::assertSame('content.contact.md', $router->match('GET', '/contact.md')->route->name);
        self::assertSame('/about/team.md', $router->url('content.pages.md', ['slug' => 'about/team']));
        self::assertSame('content.pages.show', $router->match('GET', '/about/team')->route->name, 'the pages themselves stay');

        $this->expectException(NotFound::class);
        $router->match('GET', '/stories/hello.md.md');
    }

    public function testArchiveRoutes(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections([
            Collection::make('pages')->route('/{slug}')->hierarchical(),
            Collection::make('stories')->route('/stories/{slug}')->archive('/stories'),
        ]));

        $archive = $router->match('GET', '/stories');
        self::assertSame('content.stories.index', $archive->route->name);
        self::assertSame('/stories?page=2', $router->url('content.stories.index', ['page' => 2]));
    }

    public function testFeedRoutes(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections([
            Collection::make('stories')->route('/stories/{slug}')->archive('/stories/')->feed(),
        ]));

        self::assertSame('content.stories.feed', $router->match('GET', '/stories/feed.xml')->route->name);
        self::assertSame('content.stories.show', $router->match('GET', '/stories/feed')->route->name);
    }

    public function testBuiltInRoutesLiveAtTheRootOnly(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections([Collection::make('pages')->route('/{slug}')->hierarchical()]));

        foreach (['/sitemap.xml' => 'content.sitemap', '/robots.txt' => 'content.robots'] as $path => $name) {
            $route = $router->match('GET', $path)->route;
            self::assertSame($name, $route->name);
            self::assertContains(RequireDefaultLocale::class, $route->middlewareList());
        }
    }

    public function testTermRoutesComeBeforeCatchAllsAndReserveTheirFirstSegment(): void
    {
        $router = new Router();
        $collections = new Collections(
            [Collection::make('pages')->route('/{slug}')->hierarchical()],
            new Taxonomies([Taxonomy::make('topics')->route('/topics/{slug}'), Taxonomy::make('regions')]),
        );
        ContentModule::registerRoutes($router, $collections);

        $match = $router->match('GET', '/topics/php');
        self::assertSame('taxonomy.topics.show', $match->route->name);
        self::assertSame(['slug' => 'php'], $match->params);
        self::assertSame('topics', $match->route->attributes()[Taxonomy::class] ?? null);
        self::assertSame('content.pages.show', $router->match('GET', '/about/team')->route->name);
        self::assertSame('/topics/php', $router->url('taxonomy.topics.show', ['slug' => 'php']));
        self::assertSame(['taxonomy.topics.show'], array_values(array_filter(
            array_map(static fn($route): ?string => $route->name, $router->routes()),
            static fn(?string $name): bool => $name !== null && str_starts_with($name, 'taxonomy.'),
        )));
        self::assertTrue(new ReservedSlugs($router, new Locales('en', ['en']))->isReserved($collections->get('pages'), 'topics'));
    }

    public function testARootTaxonomySharesTheRootRouteOfACollection(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections(
            [Collection::make('pages')->route('/{slug}'), Collection::make('stories')->route('/stories/{slug}')],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]),
        ));

        $match = $router->match('GET', '/guides');
        self::assertSame('content.pages.show', $match->route->name);
        self::assertSame([RootController::class, 'show'], $match->route->handler);
        self::assertSame('pages', $match->route->attributes()[Collection::class] ?? null);
        self::assertSame('sections', $match->route->attributes()[Taxonomy::class] ?? null);
        self::assertSame('/guides', $router->url('taxonomy.sections.show', ['slug' => 'guides']));
        self::assertSame('/guides?page=2', $router->url('taxonomy.sections.show', ['slug' => 'guides', 'page' => 2]));
        self::assertSame(['slug' => 'guides/php'], $router->match('GET', '/guides/php')->params, 'a section lives at its trail');
        self::assertSame('/guides/php', $router->url('taxonomy.sections.show', ['slug' => 'guides/php']));
        self::assertSame('content.stories.show', $router->match('GET', '/stories/hello')->route->name);
    }

    public function testTheRootRouteAnswersEveryChainOfSectionsAndArticles(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections(
            [
                Collection::make('news')->route('/news/{slug}'),
                Collection::make('pages')->route('/{slug}'),
                Collection::make('articles')->route('/{sections}/{slug}')->taxonomies(['sections']),
            ],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]),
        ));

        $match = $router->match('GET', '/guides/php/hello');
        self::assertSame('content.pages.show', $match->route->name);
        self::assertSame([RootController::class, 'show'], $match->route->handler);
        self::assertSame(['slug' => 'guides/php/hello'], $match->params);
        self::assertSame('/guides/php/hello', $router->url('content.articles.show', ['sections' => 'guides/php', 'slug' => 'hello']));
        self::assertSame('/guides/php', $router->url('taxonomy.sections.show', ['slug' => 'guides/php']));
        self::assertSame('content.news.show', $router->match('GET', '/news/hello')->route->name, 'a literal prefix wins');
        self::assertSame('content.pages.show', $router->match('GET', '/guides')->route->name);
    }

    public function testArchivesComeBeforeEveryCatchAll(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections(
            [
                Collection::make('pages')->route('/{slug}'),
                Collection::make('articles')->route('/{sections}/{slug}')->archive('/articles')->feed()->taxonomies(['sections']),
            ],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]),
        ));

        self::assertSame('content.articles.index', $router->match('GET', '/articles')->route->name);
        self::assertSame('content.articles.feed', $router->match('GET', '/articles/feed.xml')->route->name);
        self::assertSame('content.pages.show', $router->match('GET', '/articles/hello')->route->name, 'the root route resolves an article');
    }

    public function testARootTaxonomyAloneOwnsTheRootRoute(): void
    {
        $router = new Router();
        ContentModule::registerRoutes($router, new Collections(
            [Collection::make('stories')->route('/stories/{slug}')],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]),
        ));

        $match = $router->match('GET', '/guides/php');
        self::assertSame('taxonomy.sections.show', $match->route->name);
        self::assertSame([RootController::class, 'show'], $match->route->handler);
        self::assertSame(['slug' => 'guides/php'], $match->params);
        self::assertSame('sections', $match->route->attributes()[Taxonomy::class] ?? null);
        self::assertArrayNotHasKey(Collection::class, $match->route->attributes());
        self::assertSame('content.stories.show', $router->match('GET', '/stories/hello')->route->name);
    }
}
