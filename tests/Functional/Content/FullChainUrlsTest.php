<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;

/**
 * Addresses repeat the nesting of the fixture's sections: /{section}/{subsection}/{story}. A partial or wrong chain
 * redirects to the canonical address.
 */
final class FullChainUrlsTest extends ContentFunctionalTestCase
{
    public function testEveryKindOfAddressAtTheRoot(): void
    {
        $about = $this->term('sections', 'About us', ['slug' => 'about']);
        $history = $this->term('sections', 'History', ['parent' => $about]);
        $projects = $this->term('sections', 'Projects');
        $this->entry('info', 'Privacy', ['slug' => 'privacy']);
        $this->entry('stories', 'Founding', ['slug' => 'founding', 'terms' => ['sections' => [$history->id]], 'fields' => ['summary' => '<p>How we started</p>']]);
        $this->entry('stories', 'Bridge', ['slug' => 'bridge', 'terms' => ['sections' => [$projects->id]]]);

        $page = $this->browser->get('/privacy');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('<div class="generic" data-collection="info">', (string) $page->getBody());

        $parent = $this->browser->get('/about');
        self::assertSame(302, $parent->getStatusCode());
        self::assertSame('/about/history', $parent->getHeaderLine('Location'), 'a section with subsections leads to its first one');

        $subsection = (string) $this->browser->get('/about/history')->getBody();
        self::assertStringContainsString('<h1 class="term" data-taxonomy="sections">History</h1>', $subsection);
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/about/history">', $subsection);
        self::assertStringContainsString('<nav class="trail"><a href="/about">About us</a> / <a href="/about/history">History</a> / </nav>', $subsection);
        self::assertStringContainsString('<a href="/about/history/founding">Founding</a>', $subsection);

        $story = (string) $this->browser->get('/about/history/founding')->getBody();
        self::assertStringContainsString('<div class="generic" data-collection="stories">', $story);
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/about/history/founding">', $story);

        $childless = (string) $this->browser->get('/projects')->getBody();
        self::assertStringContainsString('<h1 class="term" data-taxonomy="sections">Projects</h1>', $childless);
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/projects/bridge">', (string) $this->browser->get('/projects/bridge')->getBody());

        foreach (['/about/ghost', '/about/history/ghost', '/ghost'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
    }

    public function testPartialAndWrongChainsRedirectToTheCanonicalAddress(): void
    {
        $about = $this->term('sections', 'About us', ['slug' => 'about']);
        $history = $this->term('sections', 'History', ['parent' => $about]);
        $this->term('sections', 'Projects');
        $this->entry('stories', 'Founding', ['slug' => 'founding', 'terms' => ['sections' => [$history->id]]]);
        $this->entry('stories', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft, 'terms' => ['sections' => [$history->id]]]);

        $redirects = [
            '/history' => '/about/history',
            '/projects/history' => '/about/history',
            '/history?page=2' => '/about/history?page=2',
            '/founding' => '/about/history/founding',
            '/about/founding' => '/about/history/founding',
            '/history/founding' => '/about/history/founding',
            '/projects/history/founding' => '/about/history/founding',
            '/ru/about/history' => null,
        ];
        foreach ($redirects as $path => $target) {
            $response = $this->browser->get($path);
            if ($target === null) {
                self::assertSame(404, $response->getStatusCode(), $path . ': the sections are not translated');

                continue;
            }
            self::assertSame(301, $response->getStatusCode(), $path);
            self::assertSame($target, $response->getHeaderLine('Location'), $path);
        }
        self::assertSame(404, $this->browser->get('/about/history/draft')->getStatusCode());

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        $preview = $this->browser->get('/history/draft?preview=1');
        self::assertSame(302, $preview->getStatusCode());
        self::assertSame('/about/history/draft?preview=1', $preview->getHeaderLine('Location'));
        self::assertSame('no-store, private', $preview->getHeaderLine('Cache-Control'));
        self::assertSame(200, $this->browser->get('/about/history/draft?preview=1')->getStatusCode());
    }
}
