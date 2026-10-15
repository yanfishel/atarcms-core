<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Search;

use AtarCms\Content\EntryStatus;
use AtarCms\Http\Session\SessionStorage;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\Paths;
use AtarCms\Search\Http\SearchController;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\MemorySessionStorage;
use AtarCms\Tests\Support\TempDirectory;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * /search on the fixture site; its theme's search.php prints the variables in plain elements, and the fixture's
 * config/content.php sets two results per page.
 */
final class SearchPageTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    public function testListsPublishedMatchesWithTheirLinksAndDescriptions(): void
    {
        $this->entry('articles', 'Running club', ['fields' => ['summary' => 'Weekly runs in the park']]);
        $guides = $this->entry('pages', 'Guides');
        $this->entry('pages', 'Running guide', ['parent' => $guides, 'metaDescription' => 'How to start running']);
        $this->entry('articles', 'Running draft', ['status' => EntryStatus::Draft]);
        $this->entry('events', 'Running event');

        $response = $this->browser->get('/search?q=runs');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<meta name="robots" content="noindex">', $body);
        self::assertStringContainsString('<input name="q" value="runs">', $body);
        self::assertStringContainsString('<p class="total">2</p>', $body);
        self::assertStringContainsString('<a href="/articles/running-club">Running club</a> <span class="description">Weekly runs in the park</span>', $body);
        self::assertStringContainsString('<a href="/pages/guides/running-guide">Running guide</a> <span class="description">How to start running</span>', $body);
        self::assertStringNotContainsString('Running draft', $body);
        self::assertStringNotContainsString('Running event', $body);
        self::assertStringNotContainsString('class="too-short"', $body);
    }

    public function testTheQueryIsEscaped(): void
    {
        $response = $this->browser->get('/search?q=' . rawurlencode('<script>alert("x")</script>'));
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;"', $body);
        self::assertStringNotContainsString('<script>alert', $body);
    }

    public function testNoWordsToSearchForShowsTheFormOnly(): void
    {
        $this->entry('articles', 'A to Z');

        foreach (['/search', '/search?q=a+to+z', '/search?q[]=running'] as $uri) {
            $body = (string) $this->browser->get($uri)->getBody();

            self::assertStringContainsString('<p class="too-short">', $body, $uri);
            self::assertStringContainsString('<p class="total">0</p>', $body, $uri);
        }
    }

    public function testResultsArePaged(): void
    {
        foreach (['First', 'Second', 'Third'] as $n => $title) {
            $this->entry('articles', $title . ' report', ['publishedAt' => $this->clock->now()->modify(sprintf('-%d hours', $n + 1))]);
        }

        $second = (string) $this->browser->get('/search?q=report&page=2')->getBody();

        self::assertStringContainsString('Third report', $second);
        self::assertStringNotContainsString('First report', $second);
        self::assertStringContainsString('<p class="pages">2/2</p>', $second);
        self::assertSame(404, $this->browser->get('/search?q=report&page=3')->getStatusCode());
        self::assertSame(404, $this->browser->get('/search?q=report&page=0')->getStatusCode());
    }

    public function testEachLocaleSearchesItsOwnEntries(): void
    {
        $english = $this->entry('articles', 'Moscow news');
        $this->translation($english, 'ru', 'Новости Москвы', 'novosti');

        $en = (string) $this->browser->get('/search?q=moscow')->getBody();
        $ru = (string) $this->browser->get('/ru/search?q=' . rawurlencode('москве'))->getBody();

        self::assertStringContainsString('Moscow news', $en);
        self::assertStringNotContainsString('Новости Москвы', $en);
        self::assertStringContainsString('<a href="/ru/articles/novosti">Новости Москвы</a>', $ru);
        self::assertStringNotContainsString('Moscow news', $ru);

        $redirect = $this->browser->get('/en/search?q=moscow');
        self::assertSame(301, $redirect->getStatusCode());
        self::assertSame('/search?q=moscow', $redirect->getHeaderLine('Location'));
    }

    public function testAThemeWithoutSearchTemplateHasNoSearchPage(): void
    {
        $site = $this->copySite();
        self::assertSame(200, $this->browserFor($site)->get('/search?q=running')->getStatusCode());

        unlink($site . '/themes/test/search.php');

        self::assertSame(404, $this->browserFor($site)->get('/search?q=running')->getStatusCode());
    }

    public function testResultsPerPageAreValidated(): void
    {
        foreach ([0, 101] as $perPage) {
            $site = $this->copySite('site' . $perPage);
            file_put_contents($site . '/config/content.php', "<?php\n\nreturn ['search_per_page' => " . $perPage . "];\n");

            self::assertSame(500, $this->browserFor($site)->get('/search?q=running')->getStatusCode());
            try {
                Application::boot(new Paths($site, storage: $this->tempDir() . '/storage'))->container()->service(SearchController::class);
                self::fail('Expected a ConfigException for ' . $perPage);
            } catch (ConfigException $e) {
                self::assertStringContainsString('content.search_per_page must be between 1 and 100', $e->getMessage());
            }
        }
    }

    /**
     * A copy of the fixture site's config and themes in a temporary directory.
     */
    private function copySite(string $name = 'site'): string
    {
        $site = $this->tempDir() . '/' . $name;
        foreach (['config', 'themes'] as $directory) {
            mkdir($site . '/' . $directory, 0777, true);
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::SITE . '/' . $directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            /** @var SplFileInfo $item */
            foreach ($items as $item) {
                $target = $site . '/' . $directory . '/' . $items->getSubPathname();
                $item->isDir() ? mkdir($target, 0777, true) : copy($item->getPathname(), $target);
            }
        }

        return $site;
    }

    private function browserFor(string $site): Browser
    {
        $app = Application::boot(new Paths($site, storage: $this->tempDir() . '/storage'));
        $app->container()->set(SessionStorage::class, new MemorySessionStorage());
        $app->container()->set(Clock::class, $this->clock);

        return new Browser($app);
    }
}
