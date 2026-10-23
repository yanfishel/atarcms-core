<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Settings;

use AtarCms\Content\EntryStatus;
use AtarCms\Database\Connection;
use AtarCms\Http\SearchVisibilityMiddleware;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Paths;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HideFromSearchTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private const string SNIPPETS = 'max-snippet:-1, max-image-preview:large, max-video-preview:-1';

    public function testAVisibleSiteOnlyAllowsFullSnippets(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        self::assertSame(self::SNIPPETS, $this->browser->get('/articles/hello')->getHeaderLine('X-Robots-Tag'));
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

        self::assertContains($describedBy, $this->browser->get('/articles/hello')->getHeader('Link'));
        self::assertSame('</ru/articles.md>; rel="alternate"; type="text/markdown", ' . $describedBy, $this->browser->get('/ru/articles')->getHeaderLine('Link'));
        self::assertSame($describedBy, $this->browser->get('/search')->getHeaderLine('Link'));
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

    public function testPublicHtmlPagesAllowFullSnippetsAndNothingElseDoes(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        foreach (['/articles/hello', '/ru/articles', '/articles', '/search?q=hello'] as $path) {
            self::assertSame(self::SNIPPETS, $this->browser->get($path)->getHeaderLine('X-Robots-Tag'), $path);
        }
        self::assertSame('noindex', $this->browser->get('/articles/hello.md')->getHeaderLine('X-Robots-Tag'), 'the twin keeps its own');
        self::assertSame('', $this->browser->get('/articles/hello', ['Accept' => 'text/markdown'])->getHeaderLine('X-Robots-Tag'), 'no HTML');
        foreach (['/sitemap.xml', '/sitemap.md', '/robots.txt', '/llms.txt', '/llms-full.txt', '/articles/feed.xml', '/articles/nope', '/boom'] as $path) {
            self::assertSame('', $this->browser->get($path)->getHeaderLine('X-Robots-Tag'), $path);
        }

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame('', $this->browser->get('/admin')->getHeaderLine('X-Robots-Tag'), 'not the admin');
        self::assertSame('', $this->browser->get('/admin/login')->getHeaderLine('X-Robots-Tag'), 'not the admin');
        self::assertSame('noindex', $this->browser->get('/articles/draft?preview=1')->getHeaderLine('X-Robots-Tag'), 'the preview keeps its own');

        $this->hide();
        self::assertSame('noindex, nofollow', $this->browser->get('/articles/hello')->getHeaderLine('X-Robots-Tag'), 'a hidden site');
    }

    public function testTheSnippetDirectivesAreConfigurable(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $container = $this->app->container();
        $config = $container->service(Config::class);

        $container->set(Config::class, $config->with(['content.snippet_directives' => 'max-snippet:50']));
        self::assertSame('max-snippet:50', $this->browser->get('/articles/hello')->getHeaderLine('X-Robots-Tag'));

        $container->set(Config::class, $config->with(['content.snippet_directives' => '']));
        $page = $this->browser->get('/articles/hello');
        self::assertFalse($page->hasHeader('X-Robots-Tag'), 'an empty string turns them off');
        self::assertContains('</llms.txt>; rel="describedby"', $page->getHeader('Link'), 'the Link header stays');
    }

    public function testSnippetDirectivesThatAreNotOneLineFailTheBoot(): void
    {
        foreach (["max-snippet:-1\r\nSet-Cookie: x=1", ['max-snippet:-1']] as $value) {
            try {
                Application::boot(new Paths(static::SITE, $this->tempDir() . '/storage'), ['content.snippet_directives' => $value]);
                self::fail('the boot accepted ' . var_export($value, true));
            } catch (ConfigException $e) {
                self::assertStringContainsString('content.snippet_directives', $e->getMessage());
            }
        }
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
