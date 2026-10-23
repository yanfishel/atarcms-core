<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Support\Cast;
use Dom\HTMLDocument;

/**
 * The JSON-LD of the default theme's pages on the demo content, in every language: the layout prints it through
 * socialMeta(), with no change of its own.
 */
final class SkeletonStructuredDataTest extends SkeletonTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function testTheHomePageIsTheWebSiteOfTheOrganizationInEveryLanguage(): void
    {
        $settings = $this->app->container()->service(SiteSettings::class);
        foreach (['/' => ['en', 'https://localhost/'], '/ru' => ['ru', 'https://localhost/ru'], '/he' => ['he', 'https://localhost/he']] as $path => [$locale, $url]) {
            $graph = $this->graph((string) $this->browser->get($path)->getBody());
            $site = $settings->of($locale);
            self::assertSame([
                '@type' => 'WebSite',
                '@id' => $url . '#website',
                'name' => $settings->siteName($locale),
                'url' => $url,
                'inLanguage' => $locale,
                'publisher' => ['@id' => 'https://localhost/#organization'],
            ], $graph[0] ?? null, $path);
            $organization = $graph[1] ?? [];
            self::assertSame('Organization', $organization['@type'] ?? null, $path . ': the default type');
            self::assertSame('https://localhost/#organization', $organization['@id'] ?? null);
            self::assertSame($settings->siteName($locale), $organization['name'] ?? null);
            self::assertSame('https://localhost/', $organization['url'] ?? null);
            self::assertSame($site->phone, $organization['telephone'] ?? null);
            self::assertSame($site->email, $organization['email'] ?? null);
            self::assertSame(implode(', ', array_map('trim', explode("\n", trim((string) $site->address)))), $organization['address'] ?? null, $path . ': the address of its language, on one line');
            self::assertArrayNotHasKey('openingHours', $organization, 'free text, not the openingHours format');
            self::assertCount(2, $graph);
        }
    }

    public function testTheOrganizationTypeComesFromTheConfig(): void
    {
        $container = $this->app->container();
        $container->set(Config::class, $container->service(Config::class)->with(['content.organization_type' => 'Dentist']));

        self::assertSame('Dentist', $this->node((string) $this->browser->get('/')->getBody(), 'Dentist')['@type'] ?? null);
    }

    public function testANewsItemIsAnArticleInItsLanguage(): void
    {
        $slug = Cast::string($this->db->scalar("SELECT slug FROM entries WHERE collection = 'news' AND locale = 'he' ORDER BY published_at DESC LIMIT 1"));
        $name = $this->app->container()->service(SiteSettings::class)->siteName('he');

        $article = $this->node((string) $this->browser->get('/he/news/' . $slug)->getBody(), 'Article');

        self::assertSame('he', $article['inLanguage'] ?? null);
        self::assertSame('https://localhost/he/news/' . $slug, $article['url'] ?? null);
        self::assertSame(['@type' => 'Organization', '@id' => 'https://localhost/#organization', 'name' => $name, 'url' => 'https://localhost/'], $article['publisher'] ?? null, 'the home page\'s organization');
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', Cast::string($article['datePublished'] ?? ''));

        $trail = $this->node((string) $this->browser->get('/he/news/' . $slug)->getBody(), 'BreadcrumbList')['itemListElement'] ?? [];
        self::assertIsArray($trail);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => $this->app->container()->service(Translator::class)->label('he', 'site.collections.news'), 'item' => 'https://localhost/he/news'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $article['headline'] ?? null, 'item' => 'https://localhost/he/news/' . $slug],
        ], $trail, 'the archive, in Hebrew, then the item');
    }

    public function testAnArticleInASubsectionSitsBelowItsSections(): void
    {
        $row = $this->db->selectOne(
            "SELECT e.slug, e.title FROM entries e JOIN entry_terms et ON et.entry_id = e.id JOIN terms t ON t.id = et.term_id
             WHERE e.collection = 'articles' AND e.locale = 'ru' AND t.slug = 'consulting' ORDER BY e.id LIMIT 1",
        );
        self::assertNotNull($row);
        $parent = Cast::string($this->db->scalar("SELECT p.name FROM terms t JOIN terms p ON p.id = t.parent_id WHERE t.slug = 'consulting' AND t.locale = 'ru'"));
        $name = Cast::string($this->db->scalar("SELECT name FROM terms WHERE slug = 'consulting' AND locale = 'ru'"));
        $url = 'https://localhost/ru/services/consulting/' . Cast::string($row['slug']);

        self::assertSame(['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $parent, 'item' => 'https://localhost/ru/services'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => 'https://localhost/ru/services/consulting'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $row['title'], 'item' => $url],
        ]], $this->node((string) $this->browser->get('/ru/services/consulting/' . Cast::string($row['slug']))->getBody(), 'BreadcrumbList'));
    }

    public function testTheVisibleBreadcrumbsFollowTheBreadcrumbList(): void
    {
        $row = $this->db->selectOne(
            "SELECT e.slug FROM entries e JOIN entry_terms et ON et.entry_id = e.id JOIN terms t ON t.id = et.term_id
             WHERE e.collection = 'articles' AND e.locale = 'ru' AND t.slug = 'consulting' ORDER BY e.id LIMIT 1",
        );
        self::assertNotNull($row);
        $newsSlug = Cast::string($this->db->scalar("SELECT slug FROM entries WHERE collection = 'news' AND locale = 'en' ORDER BY published_at DESC LIMIT 1"));
        $home = $this->app->container()->service(Translator::class)->label('en', 'site.home');

        foreach (['/ru/services/consulting/' . Cast::string($row['slug']), '/news/' . $newsSlug, '/ru/services/consulting'] as $path) {
            $body = (string) $this->browser->get($path)->getBody();
            $list = $this->node($body, 'BreadcrumbList')['itemListElement'] ?? [];
            self::assertIsArray($list);
            self::assertContainsOnlyArray($list);
            $crumbs = $this->crumbs($body);
            $expected = [];
            foreach ($list as $index => $item) {
                $expected[] = [$item['name'], $index === array_key_last($list) ? null : $item['item']];
            }
            self::assertSame($expected, array_slice($crumbs, 1), $path . ': the same names and addresses, the page unlinked');
            self::assertCount(count($list) + 1, $crumbs, $path . ': after the home page');
        }
        self::assertSame([[$home, '/'], ['About us', null]], $this->crumbs((string) $this->browser->get('/about')->getBody()), 'a page without an ancestor keeps the template\'s items');
    }

    public function testPlainPagesAreWebPagesAndArchivesCollectionPages(): void
    {
        foreach (['/about' => 'WebPage', '/contacts' => 'WebPage', '/privacy' => 'WebPage', '/he/accessibility' => 'WebPage', '/news' => 'CollectionPage'] as $path => $type) {
            $response = $this->browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $graph = $this->graph((string) $response->getBody());
            self::assertCount(1, $graph ?? [], $path);
            self::assertSame($type, $graph[0]['@type'] ?? null, $path);
            self::assertSame('https://localhost' . $path, $graph[0]['url'] ?? null, $path);
            $site = $graph[0]['isPartOf'] ?? null;
            self::assertIsArray($site, $path);
            $home = str_starts_with($path, '/he/') ? 'https://localhost/he' : 'https://localhost/';
            self::assertSame($home . '#website', $site['@id'] ?? null, $path . ': part of the home page\'s WebSite of its language');
            self::assertSame($home, $site['url'] ?? null, $path);
        }
        self::assertSame('he', $this->node((string) $this->browser->get('/he/accessibility')->getBody(), 'WebPage')['inLanguage'] ?? null);
        self::assertNull($this->graph((string) $this->browser->get('/search?q=lorem')->getBody()), 'no canonical URL');
    }

    /**
     * @return list<array<mixed>>|null
     */
    private function graph(string $body): ?array
    {
        $blocks = HTMLDocument::createFromString($body, LIBXML_NOERROR)->querySelectorAll('script[type="application/ld+json"]');
        self::assertLessThanOrEqual(1, $blocks->length, 'one block per page');
        if ($blocks->length === 0) {
            return null;
        }
        $data = json_decode((string) $blocks->item(0)?->textContent, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('https://schema.org', $data['@context'] ?? null);
        self::assertIsList($data['@graph'] ?? null);
        self::assertContainsOnlyArray($data['@graph']);

        return $data['@graph'];
    }

    /**
     * The visible breadcrumbs: [label, href] pairs, href null for the current page.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function crumbs(string $body): array
    {
        $crumbs = [];
        foreach (HTMLDocument::createFromString($body, LIBXML_NOERROR)->querySelectorAll('nav.breadcrumbs li') as $item) {
            $link = $item->querySelector('a');
            $crumbs[] = [trim((string) $item->textContent), $link?->getAttribute('href')];
        }

        return $crumbs;
    }

    /**
     * @return array<mixed>
     */
    private function node(string $body, string $type): array
    {
        foreach ($this->graph($body) ?? [] as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }

        self::fail('No ' . $type . ' in the page\'s structured data');
    }
}
