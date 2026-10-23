<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

final class LlmsTxtTest extends ContentFunctionalTestCase
{
    public function testListsThePublicContentAsMarkdown(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('notes', 'Secret note', ['fields' => ['text' => 'x']]);
        $this->term('topics', 'PHP');
        $this->term('regions', 'North');

        $response = $this->browser->get('/llms.txt');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("# Fixture\n", $body);
        self::assertStringContainsString("\nSite languages: en (default), ru\n\nAppend .md to the URL of an entry, term or archive page for a markdown version.\n\nThe full text of the entry pages: https://localhost/llms-full.txt\n\nA markdown sitemap: https://localhost/sitemap.md\n\n## ", $body);
        self::assertStringContainsString('## Articles (en)', $body);
        self::assertStringContainsString('- [Hello](https://localhost/articles/hello)', $body);
        self::assertStringContainsString('## Articles (ru)', $body);
        self::assertStringContainsString('- [Привет](https://localhost/ru/articles/privet)', $body);
        self::assertStringContainsString('- [Team](https://localhost/pages/about/team)', $body, 'a nested page keeps its full path');
        self::assertStringContainsString('- [PHP](https://localhost/topics/php)', $body, 'terms of a routed taxonomy');
        self::assertStringNotContainsString('draft', $body);
        self::assertStringNotContainsString('soon', $body);
        self::assertStringNotContainsString('Secret note', $body, 'a collection without a route is not public');
        self::assertStringNotContainsString('North', $body, 'a taxonomy without a route is not public');
    }

    public function testTheDefaultLanguageComesFirstAndEmptySectionsAreLeftOut(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);

        $body = (string) $this->browser->get('/llms.txt')->getBody();

        self::assertStringContainsString('## Articles (en)', $body);
        self::assertStringNotContainsString('## Articles (ru)', $body, 'no entries in ru, no section');
        self::assertStringNotContainsString('## Topics', $body, 'no terms, no section');
    }

    public function testTheSiteDescriptionBecomesTheBlockquote(): void
    {
        $this->app->container()->service(SettingsService::class)
            ->save('en', new SettingsInput(metaDescription: 'A small fixture site.'), null, null);

        $body = (string) $this->browser->get('/llms.txt')->getBody();

        self::assertStringContainsString("\n> A small fixture site.\n", $body);
    }

    public function testTheListingStopsAtTheLimitAndNamesTheSitemap(): void
    {
        $container = $this->app->container();
        $config = $container->service(Config::class);
        $container->set(Config::class, new Config([...$config->all(), 'content' => ['llms_limit' => 2]]));
        $this->entry('articles', 'One', ['slug' => 'one']);
        $this->entry('articles', 'Two', ['slug' => 'two']);
        $this->entry('articles', 'Three', ['slug' => 'three']);

        $body = (string) $this->browser->get('/llms.txt')->getBody();

        self::assertSame(2, substr_count($body, "\n- ["), 'the limit caps the links');
        self::assertStringContainsString('More content: https://localhost/sitemap.xml', $body);
    }

    public function testASiteHiddenFromSearchHasNoLlmsTxt(): void
    {
        $this->app->container()->service(SettingsService::class)
            ->save('en', new SettingsInput(hideFromSearch: true), null, null);

        self::assertSame(404, $this->browser->get('/llms.txt')->getStatusCode());
    }

    public function testLlmsTxtIsNotServedUnderALocalePrefix(): void
    {
        self::assertSame(404, $this->browser->get('/ru/llms.txt')->getStatusCode());
    }
}
