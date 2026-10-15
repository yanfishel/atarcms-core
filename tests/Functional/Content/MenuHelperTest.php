<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Database\Connection;
use AtarCms\Http\RequestMemo;
use AtarCms\I18n\RequestLocale;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

final class MenuHelperTest extends ContentFunctionalTestCase
{
    public function testThemesRenderTheVisibleMenusOfTheCurrentLanguage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $secret = $this->entry('articles', 'Secret', ['slug' => 'secret', 'status' => EntryStatus::Draft]);
        $this->menuItem('main', 'Home <b>', ['url' => '/']);
        $articles = $this->menuItem('main', '', ['entry' => $hello]);
        $this->menuItem('main', 'Menus', ['url' => '/menus', 'parent' => $articles, 'position' => 0]);
        $this->menuItem('main', 'Secret', ['entry' => $secret]);
        $this->menuItem('main', 'Docs', ['url' => 'https://example.org/docs', 'newTab' => true]);
        $this->menuItem('footer', 'Contact', ['url' => 'mailto:team@example.com']);
        $this->menuItem('main', 'Главная', ['url' => '/ru', 'locale' => 'ru']);

        $body = (string) $this->browser->get('/menus?from=test')->getBody();

        self::assertStringContainsString(
            '<nav class="menu-main"><ul><li class="link"><a href="/">Home &lt;b&gt;</a></li>'
            . '<li class="ancestor"><a href="/articles/hello">Hello</a><ul><li class="current"><a href="/menus">Menus</a></li></ul></li>'
            . '<li class="link"><a href="https://example.org/docs" target="_blank" rel="noopener">Docs</a></li></ul></nav>',
            $body,
        );
        self::assertStringContainsString('<nav class="menu-footer"><ul><li class="link"><a href="mailto:team@example.com">Contact</a></li></ul></nav>', $body);
        self::assertStringNotContainsString('Secret', $body);
        self::assertStringNotContainsString('Главная', $body);

        $russian = (string) $this->browser->get('/ru/menus')->getBody();
        self::assertStringContainsString('<nav class="menu-main"><ul><li class="link"><a href="/ru">Главная</a></li></ul></nav>', $russian);
        self::assertStringContainsString('<nav class="menu-footer"></nav>', $russian);
    }

    public function testTheSameMenuTwiceOnOnePageRunsItsQueriesOnce(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->menuItem('main', '', ['entry' => $about]);
        $this->menuItem('main', 'Docs', ['url' => '/docs']);
        $renderer = $this->app->container()->service(Renderer::class);
        $db = $this->app->container()->service(Connection::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/menus')
            ->withAttribute(RequestLocale::class, new RequestLocale('en', false))
            ->withAttribute(RequestMemo::class, new RequestMemo());

        $first = QueryCounter::measure($db, static fn(): mixed => $renderer->callHelper('menu', ['main'], $request));
        $second = QueryCounter::measure($db, static fn(): mixed => $renderer->callHelper('menu', ['main'], $request));
        $footer = QueryCounter::measure($db, static fn(): mixed => $renderer->callHelper('menu', ['footer'], $request));

        self::assertGreaterThan(0, $first);
        self::assertSame(0, $second);
        self::assertGreaterThan(0, $footer, 'another location is computed separately');
        self::assertEquals(
            $renderer->callHelper('menu', ['main'], $request),
            $renderer->callHelper('menu', ['main'], $request->withoutAttribute(RequestMemo::class)),
        );
    }

    public function testApplicationAttachesTheRequestMemoToEveryRequest(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->menuItem('main', '', ['entry' => $about]);
        $this->menuItem('main', 'Docs', ['url' => '/docs']);
        $db = $this->app->container()->service(Connection::class);
        // Warm up anything that is not per page (sessions, lazily built services).
        $this->browser->get('/menus');
        $this->browser->get('/menus?repeat=1');

        $once = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/menus'));
        $twice = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/menus?repeat=1'));

        self::assertGreaterThan(0, $once);
        self::assertSame($once, $twice, 'the second menu() call of the page must be served from the request memo');
        self::assertStringContainsString('menu-main-again', (string) $this->browser->get('/menus?repeat=1')->getBody());
    }

    public function testRequestsDoNotShareMenus(): void
    {
        $item = $this->menuItem('main', 'Before', ['url' => '/docs']);
        self::assertStringContainsString('>Before</a>', (string) $this->browser->get('/menus')->getBody());

        $this->app->container()->service(Connection::class)->execute('UPDATE menu_items SET label = ? WHERE id = ?', ['After', $item->id]);

        self::assertStringContainsString('>After</a>', (string) $this->browser->get('/menus')->getBody());
    }
}
