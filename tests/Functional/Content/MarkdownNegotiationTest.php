<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use Psr\Http\Message\ResponseInterface;

/**
 * An entry page asked for with an Accept header that prefers text/markdown to text/html answers with its markdown
 * twin; both variants say they vary by Accept.
 */
final class MarkdownNegotiationTest extends ContentFunctionalTestCase
{
    private const string BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

    public function testAnEntryPageAnswersAnAgentThatPrefersMarkdownWithItsTwin(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        $response = $this->markdown('/articles/hello');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) $this->browser->get('/articles/hello.md')->getBody(), (string) $response->getBody());
        self::assertFalse($response->hasHeader('X-Robots-Tag'), 'the canonical page\'s own address stays indexable');
        self::assertSame('<https://localhost/articles/hello>; rel="canonical"', $response->getHeaderLine('Link'));
        self::assertSame('noindex', $this->browser->get('/articles/hello.md')->getHeaderLine('X-Robots-Tag'), 'the twin keeps noindex');
        self::assertSame('Accept', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'no session for an agent');
    }

    public function testBrowsersAndEverythingElseGetHtml(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        foreach ([self::BROWSER, '*/*', '', 'text/markdown, text/html', 'text/html, text/markdown;q=0.9', 'text/markdown;q=0'] as $accept) {
            $response = $this->browser->get('/articles/hello', $accept === '' ? [] : ['Accept' => $accept]);

            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'), $accept);
            self::assertSame('Accept', $response->getHeaderLine('Vary'), $accept);
        }
    }

    public function testEveryEntryRouteNegotiates(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $this->entry('info', 'Imprint', ['slug' => 'imprint']);
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');

        // Each starts with its frontmatter, as its twin does.
        self::assertStringContainsString("\nurl: \"https://localhost/pages/about/team\"\n", (string) $this->markdown('/pages/about/team')->getBody());
        self::assertStringContainsString("\n---\n\n# Team\n", (string) $this->markdown('/pages/about/team')->getBody());
        self::assertStringContainsString("\n---\n\n# Contact\n", (string) $this->markdown('/contact')->getBody());
        self::assertStringContainsString("\n---\n\n# Imprint\n", (string) $this->markdown('/imprint')->getBody());
        self::assertStringContainsString("\n---\n\n# Привет\n", (string) $this->markdown('/ru/articles/privet')->getBody());
    }

    public function testRedirectsStayOnThePageAddress(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);

        $response = $this->markdown('/pages/team');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/pages/about/team', $response->getHeaderLine('Location'), 'the canonical page, which negotiates again');
    }

    public function testTermPagesAndArchivesNegotiateToo(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['tags' => ['php']]]);
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'PHP', ['parent' => $guides]);

        foreach (['/tags/php' => '/tags/php.md', '/articles' => '/articles.md', '/articles?page=1' => '/articles.md', '/guides/php' => '/guides/php.md', '/ru/articles' => '/ru/articles.md'] as $path => $twin) {
            $response = $this->markdown($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'), $path);
            self::assertSame((string) $this->browser->get($twin)->getBody(), (string) $response->getBody(), $path);
            self::assertFalse($response->hasHeader('X-Robots-Tag'), $path);
            self::assertSame('Accept', $response->getHeaderLine('Vary'), $path);
            self::assertFalse($response->hasHeader('Set-Cookie'), $path);

            $html = $this->browser->get($path, ['Accept' => self::BROWSER]);
            self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'), $path);
            self::assertSame('Accept', $html->getHeaderLine('Vary'), $path);
        }
        $redirect = $this->markdown('/guides');
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/guides/php', $redirect->getHeaderLine('Location'), 'redirects stay on page addresses');
    }

    public function testPagesWithoutATwinAndMissingEntriesStayHtml(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        foreach (['/contact', '/search?q=hello'] as $path) {
            $response = $this->markdown($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'), $path);
        }
        self::assertSame(404, $this->markdown('/articles/missing')->getStatusCode());
        self::assertSame(404, $this->markdown('/tags/missing')->getStatusCode());
    }

    public function testPreviewsAndAHiddenSiteStayHtml(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $preview = $this->markdown('/articles/draft?preview=1');
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $preview->getHeaderLine('Content-Type'));

        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);
        $hidden = $this->markdown('/articles/hello');
        self::assertSame(200, $hidden->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $hidden->getHeaderLine('Content-Type'));
        self::assertSame('', $hidden->getHeaderLine('Vary'), 'a hidden site never negotiates');
        $this->term('topics', 'PHP');
        foreach (['/articles', '/topics/php'] as $path) {
            $listing = $this->markdown($path);
            self::assertSame('text/html; charset=utf-8', $listing->getHeaderLine('Content-Type'), $path);
            self::assertSame('', $listing->getHeaderLine('Vary'), $path);
        }
    }

    private function markdown(string $path): ResponseInterface
    {
        return $this->browser->get($path, ['Accept' => 'text/markdown, text/html;q=0.9, */*;q=0.1']);
    }
}
