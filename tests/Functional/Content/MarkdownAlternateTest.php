<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\Markdown\MarkdownTwin;
use AtarCms\I18n\RequestLocale;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

final class MarkdownAlternateTest extends ContentFunctionalTestCase
{
    public function testEntryPagesPointToTheirTwin(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);

        self::assertSame('/articles/hello.md', $this->twin('/articles/hello'));
        self::assertSame('/ru/articles/privet.md', $this->twin('/ru/articles/privet'));
        self::assertSame('/pages/about/team.md', $this->twin('/pages/about/team'));
        self::assertSame('/contact.md', $this->twin('/contact'));
        self::assertSame(200, $this->browser->get('/pages/about/team.md')->getStatusCode(), 'the link leads to the twin');
    }

    public function testPagesWithoutATwinHaveNone(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->term('topics', 'PHP');

        self::assertNull($this->twin('/contact'), 'a single before its entry exists');
        self::assertNull($this->twin('/articles'));
        self::assertNull($this->twin('/topics/php'));
        self::assertNull($this->twin('/search?q=hello'));
        self::assertStringNotContainsString('text/markdown', (string) $this->browser->get('/articles/missing')->getBody());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertNull($this->twin('/articles/hello?preview=1'));
        self::assertNull($this->twin('/articles/draft?preview=1'));
    }

    public function testASiteHiddenFromSearchAdvertisesNoTwin(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->app->container()->service(SettingsService::class)
            ->save('en', new SettingsInput(hideFromSearch: true), null, null);

        self::assertNull($this->twin('/articles/hello'));
    }

    public function testTheHelperEscapesThePath(): void
    {
        $renderer = $this->app->container()->service(Renderer::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(RequestLocale::class, new RequestLocale('en', false));

        self::assertSame('', $renderer->callHelper('markdownAlternate', [], $request));
        self::assertSame(
            '<link rel="alternate" type="text/markdown" href="/a&amp;b.md">',
            $renderer->callHelper('markdownAlternate', [], $request->withAttribute(MarkdownTwin::class, new MarkdownTwin('/a&b.md'))),
        );
    }

    private function twin(string $path): ?string
    {
        $response = $this->browser->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);
        preg_match('~<link rel="alternate" type="text/markdown" href="([^"]+)">~', (string) $response->getBody(), $match);

        return $match[1] ?? null;
    }
}
