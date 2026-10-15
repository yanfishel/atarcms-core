<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Term;
use AtarCms\Database\Connection;
use AtarCms\Tests\Support\QueryCounter;
use Closure;

/**
 * Every producer of entry links builds the "/{section}/{subsection}/{slug}" URLs of the fixture's "stories" with their
 * terms and the sections' ancestors loaded in one go: the number of queries does not grow with the number of stories
 * or sections.
 */
final class SectionedLinksTest extends ContentFunctionalTestCase
{
    public function testTheSitemapListsStoriesAndOnlyTheLeavesOfRedirectingSections(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->term('sections', 'About');
        $this->story('Hello', $php);

        $xml = (string) $this->browser->get('/sitemap.xml')->getBody();

        self::assertStringContainsString('<loc>https://localhost/guides/php/hello</loc>', $xml);
        self::assertStringContainsString('<loc>https://localhost/guides/php</loc>', $xml);
        self::assertStringContainsString('<loc>https://localhost/about</loc>', $xml);
        self::assertStringNotContainsString('<loc>https://localhost/guides</loc>', $xml, 'a parent section only redirects');
        self::assertQueriesDoNotGrow(fn() => $this->browser->get('/sitemap.xml'), fn() => $this->story('More', $php));
        self::assertQueriesDoNotGrow(fn() => $this->browser->get('/sitemap.xml'), fn() => $this->term('sections', 'Go', ['parent' => $guides]));
        self::assertStringContainsString('<loc>https://localhost/guides/go</loc>', (string) $this->browser->get('/sitemap.xml')->getBody());
    }

    public function testSearchResultsLinkToTheStorysSection(): void
    {
        $php = $this->term('sections', 'PHP', ['parent' => $this->term('sections', 'Guides')]);
        $this->story('Running club', $php);

        $body = (string) $this->browser->get('/search?q=running')->getBody();

        self::assertStringContainsString('<a href="/guides/php/running-club">Running club</a>', $body);
        self::assertQueriesDoNotGrow(fn() => $this->browser->get('/search?q=running'), fn() => $this->story('Running shoes', $php));
    }

    public function testTheFeedLinksToTheStorysSection(): void
    {
        $php = $this->term('sections', 'PHP', ['parent' => $this->term('sections', 'Guides')]);
        $this->story('Hello', $php);

        $xml = (string) $this->browser->get('/stories/feed.xml')->getBody();

        self::assertStringContainsString('<link>https://localhost/guides/php/hello</link>', $xml);
        self::assertQueriesDoNotGrow(fn() => $this->browser->get('/stories/feed.xml'), fn() => $this->story('More', $php));
    }

    private function story(string $title, Term $section): void
    {
        $this->entry('stories', $title, [
            'publishedAt' => $this->clock->now()->modify('-1 hour'),
            'terms' => ['sections' => [$section->id]],
            'fields' => ['body' => '<p>' . $title . '</p>'],
        ]);
    }

    /**
     * $request costs as many queries after $addStory as before.
     */
    private function assertQueriesDoNotGrow(Closure $request, Closure $addStory): void
    {
        $db = $this->app->container()->service(Connection::class);
        $request();
        $before = QueryCounter::measure($db, $request);
        $addStory();
        $request();
        self::assertSame($before, QueryCounter::measure($db, $request));
    }
}
