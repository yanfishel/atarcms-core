<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Settings;

use AtarCms\Content\EntryStatus;
use AtarCms\Database\Connection;
use AtarCms\Http\SearchVisibilityMiddleware;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\QueryCounter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HideFromSearchTest extends ContentFunctionalTestCase
{
    public function testAVisibleSiteChangesNothing(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        self::assertSame('', $this->browser->get('/articles/hello')->getHeaderLine('X-Robots-Tag'));
        self::assertStringNotContainsString('Disallow: /' . "\n", (string) $this->browser->get('/robots.txt')->getBody());
        self::assertSame(200, $this->browser->get('/sitemap.xml')->getStatusCode());
    }

    public function testAHiddenSiteAsksSearchEnginesToStayAway(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->hide();

        self::assertSame('noindex, nofollow', $this->browser->get('/articles/hello')->getHeaderLine('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $this->browser->get('/ru/articles')->getHeaderLine('X-Robots-Tag'));
        $robots = $this->browser->get('/robots.txt');
        self::assertSame("User-agent: *\nDisallow: /\n", (string) $robots->getBody());
        self::assertSame(404, $this->browser->get('/sitemap.xml')->getStatusCode());
        self::assertSame(404, $this->browser->get('/sitemap-entries-articles-1.xml')->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame('', $this->browser->get('/admin')->getHeaderLine('X-Robots-Tag'), 'not the admin');
        self::assertSame('noindex', $this->browser->get('/articles/draft?preview=1')->getHeaderLine('X-Robots-Tag'), 'the preview keeps its own');
    }

    public function testPublicHtmlPagesPointAgentsToLlmsTxt(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $describedBy = '</llms.txt>; rel="describedby"';

        self::assertSame($describedBy, $this->browser->get('/articles/hello')->getHeaderLine('Link'));
        self::assertSame($describedBy, $this->browser->get('/ru/articles')->getHeaderLine('Link'));
        self::assertSame(
            '<https://localhost/articles/hello>; rel="canonical"',
            $this->browser->get('/articles/hello.md')->getHeaderLine('Link'),
            'not on the markdown twin',
        );
        foreach (['/sitemap.xml', '/robots.txt', '/llms.txt', '/llms-full.txt', '/articles/feed.xml', '/articles/nope'] as $path) {
            self::assertSame('', $this->browser->get($path)->getHeaderLine('Link'), $path);
        }

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame('', $this->browser->get('/admin')->getHeaderLine('Link'), 'not the admin');
        self::assertSame('', $this->browser->get('/admin/login')->getHeaderLine('Link'), 'not the admin');
        self::assertSame('', $this->browser->get('/articles/hello?preview=1')->getHeaderLine('Link'), 'not a page with its own X-Robots-Tag');

        $this->hide();
        self::assertSame('', $this->browser->get('/articles/hello')->getHeaderLine('Link'), 'no /llms.txt while hidden');
    }

    public function testTheDescribedByLinkIsAppendedToAnExistingLinkHeader(): void
    {
        $middleware = new SearchVisibilityMiddleware($this->app->container());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200, ['Content-Type' => 'text/html; charset=utf-8']))->withHeader('Link', '</style.css>; rel="preload"; as="style"');
            }
        };

        $response = $middleware->process((new ServerRequest('GET', '/somewhere'))->withAttribute(RequestLocale::class, new RequestLocale('en', false)), $handler);

        self::assertSame(['</style.css>; rel="preload"; as="style"', '</llms.txt>; rel="describedby"'], $response->getHeader('Link'));
    }

    public function testErrorPagesAreLeftAlone(): void
    {
        $this->hide();

        $missing = $this->browser->get('/articles/nope');
        $broken = $this->browser->get('/boom');

        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(500, $broken->getStatusCode());
        self::assertSame('', $missing->getHeaderLine('X-Robots-Tag'));
        self::assertSame('', $broken->getHeaderLine('X-Robots-Tag'), 'an error page never reads the settings (the database may be the error)');
    }

    public function testTheSwitchCostsAThemedPageNoExtraQuery(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->browser->get('/articles/hello');
        $db = $this->app->container()->service(Connection::class);
        $visible = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/articles/hello'));
        $this->hide();

        $hidden = QueryCounter::measure($db, fn(): mixed => $this->browser->get('/articles/hello'));

        self::assertSame($visible, $hidden);
    }

    private function hide(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);
    }
}
