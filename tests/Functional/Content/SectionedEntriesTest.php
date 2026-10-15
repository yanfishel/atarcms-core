<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\TermService;
use AtarCms\Database\Connection;

/**
 * The fixture's "stories" are routed "/{sections}/{slug}" under the root-routed "sections" taxonomy.
 */
final class SectionedEntriesTest extends ContentFunctionalTestCase
{
    public function testAStoryLivesUnderItsSection(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$guides->id]]]);

        $response = $this->browser->get('/guides/hello');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<div class="generic" data-collection="stories">', $body);
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/guides/hello">', $body);
        self::assertSame(404, $this->browser->get('/guides/ghost')->getStatusCode());
    }

    public function testAnotherSectionSegmentRedirectsToTheCanonicalUrl(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->term('sections', 'Notes');
        $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$guides->id]]]);
        $this->entry('stories', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft, 'terms' => ['sections' => [$guides->id]]]);

        foreach (['/notes/hello', '/ghost/hello'] as $path) {
            $redirect = $this->browser->get($path);
            self::assertSame(301, $redirect->getStatusCode(), $path);
            self::assertSame('/guides/hello', $redirect->getHeaderLine('Location'), $path);
        }

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $preview = $this->browser->get('/notes/draft?preview=1');
        self::assertSame(302, $preview->getStatusCode());
        self::assertSame('/guides/draft?preview=1', $preview->getHeaderLine('Location'));
        self::assertSame('no-store, private', $preview->getHeaderLine('Cache-Control'));
    }

    public function testTranslationsLinkToEachOther(): void
    {
        $guides = $this->term('sections', 'Guides');
        $this->app->container()->service(TermService::class)->createTranslation($guides, 'ru', null, null);
        $hello = $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$guides->id]]]);
        $this->translation($hello, 'ru', 'Привет', 'privet');

        $russian = $this->browser->get('/ru/guides/privet');
        $body = (string) $russian->getBody();

        self::assertSame(200, $russian->getStatusCode());
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://localhost/guides/hello">', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru/guides/privet">', $body);
    }

    public function testAStoryWithoutASectionHasNoPage(): void
    {
        $guides = $this->term('sections', 'Guides');
        $story = $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$guides->id]]]);
        $this->app->container()->service(Connection::class)->execute('DELETE FROM entry_terms WHERE entry_id = ?', [$story->id]);

        self::assertSame(404, $this->browser->get('/guides/hello')->getStatusCode());
    }
}
