<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\StructuredData\Breadcrumbs;
use AtarCms\Database\Connection;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use DateTimeImmutable;
use DateTimeZone;
use Dom\HTMLDocument;

/**
 * The schema.org JSON-LD that socialMeta() prints after the Open Graph tags: Article on the entries of article-like
 * collections, WebPage on other entry pages, CollectionPage on archives and term pages, BreadcrumbList on pages below
 * an archive, a section or a parent. The WebSite of the home page is tested
 * on the skeleton (Skeleton\SkeletonStructuredDataTest): the fixture site has no home page collection.
 */
final class StructuredDataTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private const array PUBLISHER = ['@type' => 'Organization', '@id' => 'https://localhost/#organization', 'name' => 'Fixture', 'url' => 'https://localhost/'];

    private const array WEBSITE = ['@type' => 'WebSite', '@id' => 'https://localhost/#website', 'name' => 'Fixture', 'url' => 'https://localhost/'];

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testAnArticleDescribesItself(): void
    {
        $this->entry('articles', 'Hello </script> <world>', [
            'slug' => 'hello',
            'metaTitle' => 'Search title',
            'metaDescription' => 'Tips & "tricks"',
            'publishedAt' => new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')),
        ]);

        $body = (string) $this->browser->get('/articles/hello')->getBody();

        self::assertSame([
            '@type' => 'Article',
            'headline' => 'Hello </script> <world>',
            'description' => 'Tips & "tricks"',
            'datePublished' => '2026-09-01T10:00:00+00:00',
            'dateModified' => '2026-09-28T12:00:00+00:00',
            'inLanguage' => 'en',
            'url' => 'https://localhost/articles/hello',
            'author' => self::PUBLISHER,
            'publisher' => self::PUBLISHER,
        ], $this->node($body, 'Article'));
        self::assertStringNotContainsString('</script> <world>', $body, 'the title is escaped everywhere, the JSON included');
    }

    public function testAScheduledArticleWasNotModifiedBeforeItWasPublished(): void
    {
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'publishedAt' => new DateTimeImmutable('2026-09-30 08:00:00', new DateTimeZone('UTC'))]);
        $this->clock->advance('+3 days');

        $article = $this->node((string) $this->browser->get('/articles/soon')->getBody(), 'Article');

        self::assertSame('2026-09-30T08:00:00+00:00', $article['datePublished'] ?? null);
        self::assertSame('2026-09-30T08:00:00+00:00', $article['dateModified'] ?? null);
    }

    public function testTheImageIsTheSharingImage(): void
    {
        $photo = $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(1600, 1000), 'photo.jpg', 'image/jpeg'), null, null);
        $shared = $this->entry('articles', 'Trip', ['slug' => 'trip', 'fields' => ['cover' => $photo->id]]);
        $this->entry('articles', 'Plain', ['slug' => 'plain']);

        self::assertSame('https://localhost/uploads/share/' . $shared->shareImage, $this->node((string) $this->browser->get('/articles/trip')->getBody(), 'Article')['image'] ?? null);
        self::assertArrayNotHasKey('image', $this->node((string) $this->browser->get('/articles/plain')->getBody(), 'Article'), 'no image at all: no key');
        self::assertArrayNotHasKey('description', $this->node((string) $this->browser->get('/articles/plain')->getBody(), 'Article'), 'no description at all: no key');

        $site = $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . str_repeat('a', 32) . '.jpg',
            'original_name' => 'site.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'width' => 1200,
            'height' => 630,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput('Fishing club', 'We fish.', $site), null, null);

        $plain = $this->node((string) $this->browser->get('/articles/plain')->getBody(), 'Article');
        self::assertStringStartsWith('https://localhost/media/large/2026/09/' . str_repeat('a', 32) . '.jpg?v=', Cast::string($plain['image'] ?? ''), 'the site\'s sharing image');
        self::assertSame('We fish.', $plain['description'] ?? null, 'the site\'s description, as og:description');
    }

    public function testAnArticleIsInItsLanguage(): void
    {
        $this->entry('articles', 'Привет', ['slug' => 'privet', 'locale' => 'ru']);

        $article = $this->node((string) $this->browser->get('/ru/articles/privet')->getBody(), 'Article');

        self::assertSame('ru', $article['inLanguage'] ?? null);
        self::assertSame('https://localhost/ru/articles/privet', $article['url'] ?? null);
    }

    public function testOnlyArticleLikeCollectionsHaveArticles(): void
    {
        $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('info', 'Terms', ['slug' => 'terms']);
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);
        $section = $this->term('sections', 'Services', ['slug' => 'services']);
        $this->entry('stories', 'Plan', ['slug' => 'plan', 'terms' => ['sections' => [$section->id]]]);

        foreach (['/pages/about', '/terms', '/contact'] as $path) {
            self::assertSame(['WebPage'], $this->types((string) $this->browser->get($path)->getBody()), $path);
        }
        self::assertSame('Plan', $this->node((string) $this->browser->get('/services/plan')->getBody(), 'Article')['headline'] ?? null, 'a sectioned story');
        self::assertSame(['Article', 'BreadcrumbList'], $this->types((string) $this->browser->get('/services/plan')->getBody()), 'an article is no WebPage');
    }

    public function testAnyOtherEntryPageIsAWebPageOfTheSite(): void
    {
        $this->entry('pages', 'About', ['slug' => 'about', 'metaTitle' => 'About the club', 'metaDescription' => 'Who we are.']);
        $this->entry('info', 'Terms', ['slug' => 'terms']);
        $this->entry('contact', 'Связь', ['locale' => 'ru', 'fields' => ['phone' => '1']]);

        self::assertSame([
            '@type' => 'WebPage',
            'name' => 'About the club',
            'description' => 'Who we are.',
            'url' => 'https://localhost/pages/about',
            'inLanguage' => 'en',
            'isPartOf' => self::WEBSITE,
        ], $this->node((string) $this->browser->get('/pages/about')->getBody(), 'WebPage'));
        self::assertSame([
            '@type' => 'WebPage',
            'name' => 'Terms',
            'url' => 'https://localhost/terms',
            'inLanguage' => 'en',
            'isPartOf' => self::WEBSITE,
        ], $this->node((string) $this->browser->get('/terms')->getBody(), 'WebPage'), 'no description at all: no key');
        $contact = $this->node((string) $this->browser->get('/ru/contact')->getBody(), 'WebPage');
        self::assertSame('ru', $contact['inLanguage'] ?? null);
        self::assertSame('https://localhost/ru/contact', $contact['url'] ?? null);
    }

    public function testArchivesAndTermPagesAreCollectionPages(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->term('topics', 'PHP');
        $this->term('topics', 'Ява', ['locale' => 'ru', 'slug' => 'java']);
        $section = $this->term('sections', 'Services', ['slug' => 'services']);
        $this->entry('stories', 'Plan', ['slug' => 'plan', 'terms' => ['sections' => [$section->id]]]);

        self::assertSame([
            '@type' => 'CollectionPage',
            'name' => 'Articles',
            'url' => 'https://localhost/articles',
            'inLanguage' => 'en',
            'isPartOf' => self::WEBSITE,
        ], $this->node((string) $this->browser->get('/articles')->getBody(), 'CollectionPage'));
        self::assertSame([
            '@type' => 'CollectionPage',
            'name' => 'Ява',
            'url' => 'https://localhost/ru/topics/java',
            'inLanguage' => 'ru',
            'isPartOf' => ['@type' => 'WebSite', '@id' => 'https://localhost/ru#website', 'name' => 'Fixture', 'url' => 'https://localhost/ru'],
        ], $this->node((string) $this->browser->get('/ru/topics/java')->getBody(), 'CollectionPage'), 'the WebSite of its language');
        self::assertSame('https://localhost/topics/php', $this->node((string) $this->browser->get('/topics/php')->getBody(), 'CollectionPage')['url'] ?? null);
        self::assertSame(['CollectionPage'], $this->types((string) $this->browser->get('/services')->getBody()), 'a section page');
    }

    public function testAnArticleSitsBelowItsArchive(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Привет', ['slug' => 'privet', 'locale' => 'ru']);

        self::assertSame(
            self::breadcrumbs(['Articles', 'https://localhost/articles'], ['Hello', 'https://localhost/articles/hello']),
            $this->node((string) $this->browser->get('/articles/hello')->getBody(), 'BreadcrumbList'),
        );
        self::assertSame(
            self::breadcrumbs(['Articles', 'https://localhost/ru/articles'], ['Привет', 'https://localhost/ru/articles/privet']),
            $this->node((string) $this->browser->get('/ru/articles/privet')->getBody(), 'BreadcrumbList'),
        );
    }

    public function testASectionedStoryAndANestedSectionSitBelowTheirSections(): void
    {
        $services = $this->term('sections', 'Services', ['slug' => 'services']);
        $consulting = $this->term('sections', 'Consulting', ['slug' => 'consulting', 'parent' => $services]);
        $solo = $this->term('sections', 'Solo', ['slug' => 'solo']);
        $this->entry('stories', 'Plan', ['slug' => 'plan', 'terms' => ['sections' => [$consulting->id]]]);
        $this->entry('stories', 'Alone', ['slug' => 'alone', 'terms' => ['sections' => [$solo->id]]]);

        $story = $this->graph((string) $this->browser->get('/services/consulting/plan')->getBody());
        self::assertSame(['Article', 'BreadcrumbList'], array_column($story ?? [], '@type'), 'the sections, not the archive');
        self::assertSame(self::breadcrumbs(
            ['Services', 'https://localhost/services'],
            ['Consulting', 'https://localhost/services/consulting'],
            ['Plan', 'https://localhost/services/consulting/plan'],
        ), $story[1] ?? null);
        self::assertSame(
            self::breadcrumbs(['Solo', 'https://localhost/solo'], ['Alone', 'https://localhost/solo/alone']),
            $this->node((string) $this->browser->get('/solo/alone')->getBody(), 'BreadcrumbList'),
        );

        self::assertSame(self::breadcrumbs(
            ['Services', 'https://localhost/services'],
            ['Consulting', 'https://localhost/services/consulting'],
        ), $this->node((string) $this->browser->get('/services/consulting')->getBody(), 'BreadcrumbList'));
        self::assertSame(['CollectionPage', 'BreadcrumbList'], $this->types((string) $this->browser->get('/services/consulting')->getBody()));
        self::assertSame(['CollectionPage'], $this->types((string) $this->browser->get('/solo')->getBody()), 'a top-level section');
    }

    public function testANestedPageSitsBelowItsPublicParents(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('pages', 'Lead', ['slug' => 'lead', 'parent' => $team]);
        $drafts = $this->entry('pages', 'Drafts', ['slug' => 'drafts', 'parent' => $about, 'status' => EntryStatus::Draft]);
        $this->entry('pages', 'Note', ['slug' => 'note', 'parent' => $drafts]);

        self::assertSame(self::breadcrumbs(
            ['About', 'https://localhost/pages/about'],
            ['Team', 'https://localhost/pages/about/team'],
            ['Lead', 'https://localhost/pages/about/team/lead'],
        ), $this->node((string) $this->browser->get('/pages/about/team/lead')->getBody(), 'BreadcrumbList'));
        self::assertSame(['WebPage', 'BreadcrumbList'], $this->types((string) $this->browser->get('/pages/about/team/lead')->getBody()), 'no Article for a page');
        self::assertSame(self::breadcrumbs(
            ['About', 'https://localhost/pages/about'],
            ['Note', 'https://localhost/pages/about/drafts/note'],
        ), $this->node((string) $this->browser->get('/pages/about/drafts/note')->getBody(), 'BreadcrumbList'), 'a draft parent is left out');
        self::assertSame(['WebPage'], $this->types((string) $this->browser->get('/pages/about')->getBody()), 'a top-level page has no BreadcrumbList');
    }

    public function testThemesReadTheTrailThroughPageTrail(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);

        $body = (string) $this->browser->get('/pages/about/team')->getBody();

        self::assertStringContainsString('<ol class="page-trail"><li><a href="https://localhost/pages/about">About</a></li><li><a href="https://localhost/pages/about/team">Team</a></li></ol>', $body, 'the BreadcrumbList\'s items, root first, the page last');
        self::assertStringContainsString('<ol class="page-trail"></ol>', (string) $this->browser->get('/search?q=team')->getBody(), 'none without a trail');
    }

    public function testANestedTermSitsBelowItsParents(): void
    {
        $science = $this->term('topics', 'Science', ['slug' => 'science']);
        $this->term('topics', 'Physics', ['slug' => 'physics', 'parent' => $science]);
        $this->term('tags', 'Fish', ['slug' => 'fish']);

        self::assertSame(self::breadcrumbs(
            ['Science', 'https://localhost/topics/science'],
            ['Physics', 'https://localhost/topics/physics'],
        ), $this->node((string) $this->browser->get('/topics/physics')->getBody(), 'BreadcrumbList'));
        self::assertSame(['CollectionPage'], $this->types((string) $this->browser->get('/topics/science')->getBody()), 'no BreadcrumbList');
        self::assertSame(['CollectionPage'], $this->types((string) $this->browser->get('/tags/fish')->getBody()), 'no BreadcrumbList');
    }

    public function testTheBreadcrumbsReadNothingTheControllersHaveLoaded(): void
    {
        $container = $this->app->container();
        $services = $this->term('sections', 'Services', ['slug' => 'services']);
        $consulting = $this->term('sections', 'Consulting', ['slug' => 'consulting', 'parent' => $services]);
        $story = $this->entry('stories', 'Plan', ['slug' => 'plan', 'terms' => ['sections' => [$consulting->id]]]);
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $science = $this->term('topics', 'Science', ['slug' => 'science']);
        $physics = $this->term('topics', 'Physics', ['slug' => 'physics', 'parent' => $science]);
        $breadcrumbs = $container->service(Breadcrumbs::class);
        $tree = [$services->id => $services, $consulting->id => $consulting];
        $ancestors = $container->service(EntryRepository::class)->ancestors([$team->id]);

        $queries = QueryCounter::measure($container->service(Connection::class), static function () use ($breadcrumbs, $story, $consulting, $services, $tree, $team, $ancestors): void {
            $breadcrumbs->forEntry($story, 'https://localhost/services/consulting/plan', [$services, $consulting], $tree, []);
            $breadcrumbs->forTerm($consulting, 'https://localhost/services/consulting', $tree);
            $breadcrumbs->forEntry($team, 'https://localhost/pages/about/team', [], [], $ancestors);
        });
        self::assertSame(0, $queries);
        self::assertSame(1, QueryCounter::measure($container->service(Connection::class), static function () use ($breadcrumbs, $physics): void {
            $breadcrumbs->forTerm($physics, 'https://localhost/topics/physics', []);
        }), 'a nested term of a taxonomy not routed as a tree: its ancestors');
    }

    public function testANestedPageAndASectionedStoryReadTheirAncestorsOnce(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $team = $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about]);
        $this->entry('pages', 'Lead', ['slug' => 'lead', 'parent' => $team]);
        $services = $this->term('sections', 'Services', ['slug' => 'services']);
        $consulting = $this->term('sections', 'Consulting', ['slug' => 'consulting', 'parent' => $services]);
        $solo = $this->term('sections', 'Solo', ['slug' => 'solo']);
        $this->entry('stories', 'Plan', ['slug' => 'plan', 'terms' => ['sections' => [$consulting->id]]]);
        $this->entry('stories', 'Alone', ['slug' => 'alone', 'terms' => ['sections' => [$solo->id]]]);
        $db = $this->app->container()->service(Connection::class);
        $queries = function (string $path) use ($db): int {
            $this->browser->get($path);

            return QueryCounter::measure($db, function () use ($path): void {
                self::assertSame(200, $this->browser->get($path)->getStatusCode(), $path);
            });
        };

        // A top-level page or story needs no ancestors; a nested one in one language exactly one query for them,
        // whatever its depth, shared by its address, its language alternates and its breadcrumbs.
        self::assertSame($queries('/pages/about') + 1, $queries('/pages/about/team/lead'), 'a nested page');
        // A story in a subsection: its sections' ancestors once for its address and breadcrumbs, once more for the
        // trails of its language alternates (EntryUrls::paths() takes no known terms).
        self::assertSame($queries('/solo/alone') + 2, $queries('/services/consulting/plan'), 'a story in a subsection');
    }

    public function testPagesWithoutACanonicalUrlHaveNoStructuredData(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        foreach (['/search?q=hello', '/articles/missing'] as $path) {
            self::assertNull($this->graph((string) $this->browser->get($path)->getBody()), $path);
        }
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        foreach (['/articles/hello?preview=1', '/articles/draft?preview=1'] as $path) {
            $preview = $this->browser->get($path);
            self::assertSame(200, $preview->getStatusCode(), $path);
            self::assertNull($this->graph((string) $preview->getBody()), $path);
        }
    }

    /**
     * The BreadcrumbList node of the given [name, url] items.
     *
     * @param array{0: string, 1: string} ...$items
     *
     * @return array<string, mixed>
     */
    private static function breadcrumbs(array ...$items): array
    {
        $list = [];
        foreach (array_values($items) as $index => [$name, $url]) {
            $list[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /**
     * The page's JSON-LD graph, or null without a block; a page has at most one block.
     *
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
        self::assertNotSame([], $data['@graph']);
        self::assertContainsOnlyArray($data['@graph']);

        return $data['@graph'];
    }

    /**
     * The types of the graph's nodes, in order ([] without a block).
     *
     * @return list<mixed>
     */
    private function types(string $body): array
    {
        return array_map(static fn(array $node): mixed => $node['@type'] ?? null, $this->graph($body) ?? []);
    }

    /**
     * The graph's node of $type (failing when there is none).
     *
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
