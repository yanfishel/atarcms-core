<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\TermService;
use AtarCms\Database\Connection;
use AtarCms\Kernel\Config;

final class SitemapTest extends ContentFunctionalTestCase
{
    public function testListsEveryPublicEntryWithAlternates(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $this->entry('notes', 'Note', ['fields' => ['text' => 'x']]);

        $response = $this->browser->get('/sitemap.xml');
        $xml = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertNotFalse(simplexml_load_string($xml));
        preg_match_all('~<loc>([^<]+)</loc>~', $xml, $matches);
        $locations = $matches[1];
        sort($locations);
        self::assertSame([
            'https://localhost/articles/hello',
            'https://localhost/contact',
            'https://localhost/pages/about',
            'https://localhost/pages/about/team',
            'https://localhost/ru/articles/privet',
        ], $locations);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="ru" href="https://localhost/ru/articles/privet"/>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://localhost/articles/hello"/>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="https://localhost/articles/hello"/>', $xml);
        self::assertSame(6, substr_count($xml, '<xhtml:link '), 'en, ru and x-default on both translations; none on pages without translations');
        self::assertStringContainsString('<lastmod>2026-09-28T12:00:00+00:00</lastmod>', $xml);
    }

    public function testListsTheTermsOfRoutedTaxonomiesWithAlternates(): void
    {
        $php = $this->term('topics', 'PHP');
        $this->app->container()->service(TermService::class)->createTranslation($php, 'ru', null, null);
        $this->term('regions', 'North');

        $xml = (string) $this->browser->get('/sitemap.xml')->getBody();

        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('<loc>https://localhost/topics/php</loc>', $xml);
        self::assertStringContainsString('<loc>https://localhost/ru/topics/php</loc>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="ru" href="https://localhost/ru/topics/php"/>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://localhost/topics/php"/>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="https://localhost/topics/php"/>', $xml);
        self::assertStringNotContainsString('north', $xml);
    }

    public function testTranslationsInLocalesNoLongerConfiguredAreNeverListed(): void
    {
        // A site that dropped Hebrew from its locales still has Hebrew rows in the database.
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $shalom = $this->translation($hello, 'ru', 'Шалом', 'shalom');
        $php = $this->term('topics', 'PHP');
        $hebrewTerm = $this->app->container()->service(TermService::class)->createTranslation($php, 'ru', null, null);
        $db = $this->app->container()->service(Connection::class);
        $db->execute('UPDATE entries SET locale = ? WHERE id = ?', ['he', $shalom->id]);
        $db->execute('UPDATE terms SET locale = ? WHERE id = ?', ['he', $hebrewTerm->id]);
        $hebrewOnly = $this->entry('articles', 'Only Hebrew', ['slug' => 'only-hebrew']);
        $db->execute('UPDATE entries SET locale = ? WHERE id = ?', ['he', $hebrewOnly->id]);

        $sitemap = (string) $this->browser->get('/sitemap.xml')->getBody();
        $llms = (string) $this->browser->get('/llms.txt')->getBody();
        $full = (string) $this->browser->get('/llms-full.txt')->getBody();
        $markdown = (string) $this->browser->get('/articles/hello.md')->getBody();
        $page = (string) $this->browser->get('/articles/hello')->getBody();

        self::assertStringContainsString('<loc>https://localhost/articles/hello</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://localhost/topics/php</loc>', $sitemap);
        foreach ([$sitemap, $llms, $full, $markdown, $page] as $body) {
            self::assertStringNotContainsString('/he/', $body);
            self::assertStringNotContainsString('hreflang="he"', $body);
        }
        self::assertStringNotContainsString('<xhtml:link', $sitemap, 'no group has a second configured language');
        self::assertStringContainsString("\n- Language: en\n", $markdown, 'no translation links');
    }

    public function testSitemapAndRobotsAreNotServedUnderALocalePrefix(): void
    {
        self::assertSame(404, $this->browser->get('/ru/sitemap.xml')->getStatusCode());
        self::assertSame(404, $this->browser->get('/ru/robots.txt')->getStatusCode());
    }

    public function testDefaultRobots(): void
    {
        $response = $this->browser->get('/robots.txt');

        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            "User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nDisallow: /admin\n\nSitemap: https://localhost/sitemap.xml\n",
            (string) $response->getBody(),
            'contentsignals.org: search and AI answers yes, training no',
        );
    }

    public function testConfiguredRobots(): void
    {
        $container = $this->app->container();
        $config = $container->service(Config::class);
        $container->set(Config::class, new Config([...$config->all(), 'content' => ['robots' => "User-agent: *\nDisallow: /\n"]]));

        self::assertSame("User-agent: *\nDisallow: /\n", (string) $this->browser->get('/robots.txt')->getBody());
    }
}
