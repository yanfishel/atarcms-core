<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\Taxonomy;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ReservedSlugsTest extends TestCase
{
    public function testRootCatchAllsCannotTakeOtherRoutesOrLocales(): void
    {
        $router = new Router();
        $router->get('/', [stdClass::class, 'x'], 'home');
        $router->get('/admin', [stdClass::class, 'x'], 'admin.dashboard');
        $router->get('/admin/users', [stdClass::class, 'x'], 'admin.users');
        $router->get('/news/{slug}', [stdClass::class, 'x'], 'content.news.show');
        $router->get('/sitemap.xml', [stdClass::class, 'x'], 'content.sitemap');
        $router->get('/{slug}', [stdClass::class, 'x'], 'content.pages.show');
        $reserved = new ReservedSlugs($router, new Locales('en', ['en', 'ru']));
        $pages = Collection::make('pages')->route('/{slug}')->hierarchical();
        $news = Collection::make('news')->route('/news/{slug}');

        self::assertTrue($reserved->applies($pages));
        self::assertTrue($reserved->applies($news));
        self::assertFalse($reserved->applies(Collection::make('contact')->route('/contact')->single()));
        foreach (['admin', 'news', 'ru', 'en'] as $slug) {
            self::assertTrue($reserved->isReserved($pages, $slug), $slug);
        }
        foreach (['about', 'sitemap', 'users'] as $slug) {
            self::assertFalse($reserved->isReserved($pages, $slug), $slug);
        }
        self::assertFalse($reserved->isReserved($news, 'admin'));
    }

    public function testAStaticMarkdownTwinReservesItsSlug(): void
    {
        $router = new Router();
        // A single at "/" has its twin at "/index.md": the page "index" would lose its own twin to it.
        ContentModule::registerRoutes($router, new Collections([
            Collection::make('home')->route('/')->single(),
            Collection::make('pages')->route('/{slug}'),
            Collection::make('contact')->route('/contact')->single(),
        ]));
        $reserved = new ReservedSlugs($router, new Locales('en', ['en', 'ru']));
        $pages = Collection::make('pages')->route('/{slug}');

        self::assertTrue($reserved->isReserved($pages, 'index'));
        self::assertTrue($reserved->isReserved($pages, 'contact'));
        self::assertFalse($reserved->isReserved($pages, 'about'));
        self::assertFalse($reserved->isReserved($pages, 'llms'), '/llms.txt is no twin');
    }

    /**
     * FastRoute answers a static route before any variable one, and a variable route with a longer literal prefix
     * first (ContentModule::registerRoutes()), so a literal segment right after the prefix hides the entry or term.
     */
    public function testPrefixedRoutesCannotTakeALiteralSegmentOfAnotherRoute(): void
    {
        $router = new Router();
        $router->get('/pages/topics/{slug}', [stdClass::class, 'x'], 'content.terms.topics.show');
        $router->get('/pages/{slug}', [stdClass::class, 'x'], 'content.pages.show');
        $router->get('/news', [stdClass::class, 'x'], 'content.news.index');
        $router->get('/news/feed', [stdClass::class, 'x'], 'site.news.feed');
        $router->get('/news/feed.xml', [stdClass::class, 'x'], 'content.news.feed');
        $router->get('/news/{slug}', [stdClass::class, 'x'], 'content.news.show');
        $router->get('/tags/archive', [stdClass::class, 'x'], 'content.archive.index');
        $router->get('/tags/{slug}', [stdClass::class, 'x'], 'content.terms.tags.show');
        $reserved = new ReservedSlugs($router, new Locales('en', ['en', 'ru']));
        $pages = Collection::make('pages')->route('/pages/{slug}')->hierarchical();
        $news = Collection::make('news')->route('/news/{slug}');
        $tags = Taxonomy::make('tags')->route('/tags/{slug}');

        self::assertTrue($reserved->isReserved($pages, 'topics'), 'the taxonomy route hides every subpage of "topics"');
        self::assertTrue($reserved->isReserved($news, 'feed'));
        self::assertTrue($reserved->isReserved($tags, 'archive'));
        foreach ([[$pages, 'about'], [$news, 'news'], [$news, 'ru'], [$tags, 'php']] as [$owner, $slug]) {
            self::assertFalse($reserved->isReserved($owner, $slug), $slug);
        }
    }
}
