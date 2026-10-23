<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

/**
 * /sitemap.md: /llms.txt's map of the public content as a plain markdown sitemap (Vercel's Agent Readability check).
 */
final class SitemapMdTest extends ContentFunctionalTestCase
{
    public function testListsThePublicContentAsAMarkdownSitemap(): void
    {
        $hello = $this->entry('articles', 'Hello [1]', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('notes', 'Secret note', ['fields' => ['text' => 'x']]);
        $this->term('topics', 'PHP');

        $response = $this->browser->get('/sitemap.md');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'stateless');
        self::assertStringStartsWith("# Fixture — sitemap\n\nSite languages: en (default), ru\n\n## Articles (en)\n\n- [Hello \\[1\\]](https://localhost/articles/hello)\n", $body);
        self::assertStringContainsString("\n## Articles (ru)\n\n- [Привет](https://localhost/ru/articles/privet)\n", $body);
        self::assertStringContainsString('- [Team](https://localhost/pages/about/team)', $body);
        self::assertStringContainsString('- [PHP](https://localhost/topics/php)', $body);
        self::assertStringNotContainsString('Draft', $body);
        self::assertStringNotContainsString('Secret note', $body);
        self::assertStringNotContainsString('llms', $body, 'none of /llms.txt\'s own lines');
        self::assertStringNotContainsString('.md', $body);
    }

    public function testTheListingStopsAtTheLlmsLimitAndNamesTheXmlSitemap(): void
    {
        $container = $this->app->container();
        $config = $container->service(Config::class);
        $container->set(Config::class, new Config([...$config->all(), 'content' => ['llms_limit' => 2]]));
        $this->entry('articles', 'One', ['slug' => 'one']);
        $this->entry('articles', 'Two', ['slug' => 'two']);
        $this->entry('articles', 'Three', ['slug' => 'three']);

        $body = (string) $this->browser->get('/sitemap.md')->getBody();

        self::assertSame(2, substr_count($body, "\n- ["));
        self::assertStringEndsWith("\n\nMore content: https://localhost/sitemap.xml\n", $body);
    }

    public function testLlmsTxtLinksIt(): void
    {
        self::assertStringContainsString("\nA markdown sitemap: https://localhost/sitemap.md\n", (string) $this->browser->get('/llms.txt')->getBody());
    }

    public function testAHiddenSiteAndLocalePrefixesHaveNone(): void
    {
        self::assertSame(404, $this->browser->get('/ru/sitemap.md')->getStatusCode());

        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);

        self::assertSame(404, $this->browser->get('/sitemap.md')->getStatusCode());
    }

    public function testNoRootPageTakesTheSlugSitemap(): void
    {
        $container = $this->app->container();
        try {
            $container->service(EntryService::class)->create($container->service(Collections::class)->get('info'), 'en', new EntryInput('Sitemap', slug: 'sitemap'), null, null);
            self::fail('the slug "sitemap" was accepted: its twin would be /sitemap.md');
        } catch (ValidationFailed $failed) {
            self::assertSame(['slug' => 'validation.slug.reserved'], $failed->errors);
        }
    }
}
