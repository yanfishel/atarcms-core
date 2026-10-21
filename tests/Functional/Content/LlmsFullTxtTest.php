<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

final class LlmsFullTxtTest extends ContentFunctionalTestCase
{
    public function testHoldsTheMarkdownOfEveryPublicEntryPage(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'fields' => ['body' => '<h2>Intro</h2><p>Hi there</p>']]);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->entry('notes', 'Secret note', ['fields' => ['text' => 'x']]);
        $this->term('topics', 'PHP');

        $response = $this->browser->get('/llms-full.txt');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith("# Fixture\n", $body);
        self::assertStringContainsString(
            "\nSite languages: en (default), ru\n\nThe full text of the site's entry pages, one after another. The map of every page and term: https://localhost/llms.txt\n",
            $body,
        );
        self::assertStringContainsString($this->block('/articles/hello'), $body);
        self::assertStringContainsString($this->block('/ru/articles/privet'), $body);
        self::assertLessThan(strpos($body, "\n# Привет\n"), strpos($body, "\n# Hello\n"), 'the default language comes first');
        self::assertStringContainsString("\n\n---\n\n# Привет\n", $body, 'entries are separated by a thematic break');
        self::assertStringNotContainsString('Draft', $body);
        self::assertStringNotContainsString('Soon', $body);
        self::assertStringNotContainsString('Secret note', $body, 'a collection without a route is not public');
        self::assertStringNotContainsString('# PHP', $body, 'terms have no markdown');
        self::assertStringNotContainsString('More content', $body);
    }

    public function testANestedPageRendersWithItsAncestors(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->translation($about, 'ru', 'О нас', 'o-nas');
        $this->translation($team, 'ru', 'Команда', 'komanda');

        $body = (string) $this->browser->get('/llms-full.txt')->getBody();

        self::assertStringContainsString($this->block('/pages/about/team'), $body);
        self::assertStringContainsString('[ru](https://localhost/ru/pages/o-nas/komanda.md)', $body, 'the translation links keep the full path');
    }

    public function testTheListingStopsAtTheLimitAndNamesLlmsTxt(): void
    {
        $container = $this->app->container();
        $config = $container->service(Config::class);
        $container->set(Config::class, new Config([...$config->all(), 'content' => ['llms_full_limit' => 2]]));
        $this->entry('articles', 'One', ['slug' => 'one']);
        $this->entry('articles', 'Two', ['slug' => 'two']);
        $this->entry('articles', 'Three', ['slug' => 'three']);

        $body = (string) $this->browser->get('/llms-full.txt')->getBody();

        self::assertSame(2, substr_count($body, "\nSource: "), 'the limit caps the entries');
        self::assertStringEndsWith("\n\n---\n\nMore content: https://localhost/llms.txt\n", $body);
    }

    public function testLlmsTxtPointsToTheFullText(): void
    {
        $body = (string) $this->browser->get('/llms.txt')->getBody();

        self::assertStringContainsString("\nThe full text of the entry pages: https://localhost/llms-full.txt\n", $body);
    }

    public function testASiteHiddenFromSearchHasNoLlmsFullTxt(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->app->container()->service(SettingsService::class)
            ->save('en', new SettingsInput(hideFromSearch: true), null, null);

        self::assertSame(404, $this->browser->get('/llms-full.txt')->getStatusCode());
    }

    public function testLlmsFullTxtIsNotServedUnderALocalePrefix(): void
    {
        self::assertSame(404, $this->browser->get('/ru/llms-full.txt')->getStatusCode());
    }

    /**
     * The entry's block as /llms-full.txt holds it: its markdown twin with a "Source:" line under the title.
     */
    private function block(string $path): string
    {
        $twin = (string) $this->browser->get($path . '.md')->getBody();
        [$title, $rest] = explode("\n\n", $twin, 2);

        return $title . "\nSource: https://localhost" . $path . "\n\n" . $rest;
    }
}
