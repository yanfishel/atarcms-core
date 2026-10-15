<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Http\CanonicalUrl;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

final class CanonicalUrlTest extends ContentFunctionalTestCase
{
    public function testEntryPagesPointToTheirOwnAddress(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);

        self::assertSame('https://localhost/articles/hello', $this->canonical('/articles/hello'));
        self::assertSame('https://localhost/articles/hello', $this->canonical('/articles/hello?utm_source=feed&page=2'), 'the query string is not part of it');
        self::assertSame('https://localhost/ru/articles/privet', $this->canonical('/ru/articles/privet'));
        self::assertSame('https://localhost/pages/about/team', $this->canonical('/pages/about/team'));
    }

    public function testASinglePageHasOneOnceItsEntryExists(): void
    {
        self::assertNull($this->canonical('/contact'));

        $contact = $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        self::assertSame('https://localhost/contact', $this->canonical('/contact'));

        $this->translation($contact, 'ru', 'Контакты', 'contact');
        self::assertSame('https://localhost/ru/contact', $this->canonical('/ru/contact'));
    }

    public function testArchivesAndTermPagesAddThePageNumberFromPageTwoOn(): void
    {
        $php = $this->term('topics', 'PHP');
        foreach (['one', 'two', 'three'] as $slug) {
            $this->entry('articles', ucfirst($slug), ['slug' => $slug, 'terms' => ['topics' => [$php->id]]]);
        }

        self::assertSame('https://localhost/articles', $this->canonical('/articles'));
        self::assertSame('https://localhost/articles', $this->canonical('/articles?page=1'));
        self::assertSame('https://localhost/articles?page=2', $this->canonical('/articles?page=2'));
        self::assertSame('https://localhost/ru/articles', $this->canonical('/ru/articles'));
        self::assertSame('https://localhost/topics/php', $this->canonical('/topics/php'));
        self::assertSame('https://localhost/topics/php?page=2', $this->canonical('/topics/php?page=2'));
    }

    public function testPreviewSearchAndErrorPagesHaveNone(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $this->term('topics', 'PHP');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertNull($this->canonical('/articles/hello?preview=1'));
        self::assertNull($this->canonical('/articles/draft?preview=1'));
        self::assertNull($this->canonical('/contact?preview=1'));
        self::assertNull($this->canonical('/search?q=hello'));
        foreach (['/articles/missing', '/articles?page=9', '/topics/php?page=9'] as $path) {
            $response = $this->browser->get($path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('rel="canonical"', (string) $response->getBody(), $path);
        }
    }

    public function testTheHelperPrintsNothingWithoutAUrlAndEscapesIt(): void
    {
        $renderer = $this->app->container()->service(Renderer::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertSame('', $renderer->callHelper('canonical', [], $request));
        self::assertSame(
            '<link rel="canonical" href="https://localhost/?a=1&amp;b=&quot;2&quot;">',
            $renderer->callHelper('canonical', [], $request->withAttribute(CanonicalUrl::class, new CanonicalUrl('https://localhost/?a=1&b="2"'))),
        );
    }

    /**
     * The canonical URL printed by a 200 page, or null when it has none.
     */
    private function canonical(string $path): ?string
    {
        $response = $this->browser->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);
        $found = preg_match_all('~<link rel="canonical" href="([^"]*)">~', (string) $response->getBody(), $matches);
        self::assertLessThanOrEqual(1, $found, $path . ': at most one canonical link');

        return $found === 1 ? $matches[1][0] : null;
    }
}
