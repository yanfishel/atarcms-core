<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\PublicContent;
use AtarCms\Content\Sitemap;
use AtarCms\Content\TermRepository;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Container;

/**
 * Past content.sitemap_limit URLs (10 here), /sitemap.xml is a sitemap index of paged sitemaps.
 */
final class SitemapIndexTest extends ContentFunctionalTestCase
{
    private bool $small = true;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        if ($this->small) {
            $container->factory(Sitemap::class, static fn(Container $c): Sitemap => new Sitemap(
                $c->service(Collections::class),
                $c->service(PublicContent::class),
                $c->service(TermRepository::class),
                $c->service(EntryRepository::class),
                $c->service(Locales::class),
                10,
            ));
        }
    }

    public function testUnderTheLimitTheSitemapIsAUrlset(): void
    {
        $this->entry('articles', 'One', ['slug' => 'one']);

        $body = (string) $this->browser->get('/sitemap.xml')->getBody();

        self::assertStringContainsString('<urlset ', $body);
        self::assertStringContainsString('<loc>https://localhost/articles/one</loc>', $body);
        self::assertSame(404, $this->browser->get('/sitemap-entries-articles-1.xml')->getStatusCode(), 'no pages without an index');
    }

    public function testOverTheLimitTheSitemapIsAnIndexOfPages(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->entry('articles', 'Article ' . $i, ['slug' => 'article-' . $i]);
        }

        $index = $this->browser->get('/sitemap.xml');

        self::assertSame(200, $index->getStatusCode());
        self::assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . "  <sitemap>\n    <loc>https://localhost/sitemap-entries-articles-1.xml</loc>\n  </sitemap>\n"
            . "  <sitemap>\n    <loc>https://localhost/sitemap-entries-articles-2.xml</loc>\n  </sitemap>\n"
            . "  <sitemap>\n    <loc>https://localhost/sitemap-entries-articles-3.xml</loc>\n  </sitemap>\n"
            . "</sitemapindex>\n",
            (string) $index->getBody(),
        );
        $urls = 0;
        foreach ([1, 2, 3] as $number) {
            $page = $this->browser->get('/sitemap-entries-articles-' . $number . '.xml');
            self::assertSame(200, $page->getStatusCode());
            self::assertStringContainsString('<urlset ', (string) $page->getBody());
            $urls += substr_count((string) $page->getBody(), '<url>');
        }
        self::assertSame(11, $urls);
    }

    public function testTranslationsStayOnOnePage(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $entry = $this->entry('articles', 'Article ' . $i, ['slug' => 'article-' . $i]);
        }
        $this->translation($entry, 'ru', 'Статья', 'statya');

        $pages = [];
        foreach ([1, 2, 3] as $number) {
            $pages[] = (string) $this->browser->get('/sitemap-entries-articles-' . $number . '.xml')->getBody();
        }
        $withRussian = array_values(array_filter($pages, static fn(string $body): bool => str_contains($body, '/ru/articles/statya')));

        self::assertCount(1, $withRussian);
        self::assertStringContainsString('hreflang="en" href="https://localhost/articles/article-10"', $withRussian[0] ?? '');
    }

    public function testMissingPagesAre404(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->entry('articles', 'Article ' . $i, ['slug' => 'article-' . $i]);
        }

        foreach (['/sitemap-entries-articles-4.xml', '/sitemap-entries-nope-1.xml', '/sitemap-entries-notes-1.xml', '/sitemap-terms-regions-1.xml', '/sitemap-entries-articles-0.xml', '/sitemap-other-articles-1.xml'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertNotSame(200, $this->browser->get('/ru/sitemap-entries-articles-1.xml')->getStatusCode(), 'default locale only');
    }

    public function testTheLimitComesFromTheConfig(): void
    {
        $this->small = false;
        $this->bootApp();

        self::assertSame(Sitemap::MAX_URLS, $this->app->container()->service(Sitemap::class)->limit());
    }
}
