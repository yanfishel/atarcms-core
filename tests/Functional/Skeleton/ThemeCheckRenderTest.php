<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Demo\DemoSeeder;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\Cast;
use AtarCms\Theme\Check\Finding;
use AtarCms\Theme\Check\RenderSmoke;
use AtarCms\Theme\Check\Report;
use AtarCms\Theme\Check\Severity;

/**
 * The render smoke of theme:check on the skeleton site: every page type in every language through the whole stack.
 */
final class ThemeCheckRenderTest extends SkeletonTestCase
{
    public function testTheDefaultThemeRendersEveryPageOfTheDemoSite(): void
    {
        $this->app->container()->service(DemoSeeder::class)->seed(null);
        $report = new Report('default');

        (new RenderSmoke())->run($this->application(), $report);

        self::assertSame([], array_map(static fn(Finding $f): string => $f->format(), $report->findings()));
        $pages = array_column($report->pages(), 'status', 'url');
        foreach (['/', '/contacts', '/news', '/ru', '/ru/news', '/he', '/he/contacts'] as $path) {
            self::assertSame(200, $pages[$path] ?? null, $path);
        }
        self::assertSame(404, $pages['/he/atar-theme-check-missing-page'] ?? null);
        self::assertNotEmpty(array_filter(array_keys($pages), static fn(string $url): bool => str_starts_with($url, '/he/search?q=') && $url !== '/he/search?q=site'), 'a word of a Hebrew title');
        self::assertGreaterThanOrEqual(30, count($pages), 'entries, sections, tags and search in three languages');
    }

    /**
     * The smoke writes nothing: no log lines, no session, no uploads, share images or image variants. The one exception,
     * documented in the theme guide, is storage/app.key, created on first use by a page with the contact form.
     */
    public function testTheSmokeWritesNothing(): void
    {
        $this->app->container()->service(DemoSeeder::class)->seed(null);
        $storage = $this->tempDir() . '/storage';
        $before = self::tree($storage);

        (new RenderSmoke())->run($this->application(), new Report('default'));

        self::assertSame($before, self::tree($storage));
        self::assertSame('', session_id(), 'no session was started');
    }

    /**
     * @return array<string, int> file (relative, app.key left out) => size
     */
    private static function tree(string $directory): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() !== 'app.key') {
                $files[substr($file->getPathname(), strlen($directory) + 1)] = $file->getSize();
            }
        }
        ksort($files);

        return $files;
    }

    public function testAnEmptySiteSaysWhatItCouldNotRender(): void
    {
        $report = new Report('default');

        (new RenderSmoke())->run($this->application(), $report);

        self::assertSame([], array_map(static fn(Finding $f): string => $f->format(), $report->findings(Severity::Error)));
        $info = array_map(static fn(Finding $f): string => $f->message, $report->findings(Severity::Info));
        self::assertContains('news (en): no public entries, its entry page was not rendered', $info);
        self::assertContains('tags (he): no terms, its term page was not rendered', $info);
        self::assertSame(200, array_column($report->pages(), 'status', 'url')['/contacts'] ?? null, 'a single page renders before its entry exists');
    }

    public function testATemplateThatFailsIsReportedWithItsPage(): void
    {
        $this->app->container()->service(DemoSeeder::class)->seed(null);
        // A copy of the skeleton site with one more theme: the render smoke boots the site with it.
        foreach (glob(self::SITE . '/config/*.php') ?: [] as $file) {
            $this->writeFile('site/config/' . basename($file), (string) file_get_contents($file));
        }
        $this->writeFile('site/themes/broken/layouts/default.php', "<!doctype html>\n<html<?= \$this->langAttrs(\$this->locale()) ?>><body><?= \$content ?></body></html>\n");
        $this->writeFile('site/themes/broken/index.php', "<?php \$this->layout('layouts/default'); ?><h1>List</h1>\n");
        $this->writeFile('site/themes/broken/term.php', "<?php \$this->layout('layouts/default'); ?><h1>Term</h1><img src=\"/x.png\">\n");
        $this->writeFile('site/themes/broken/show.php', "<?php \$this->layout('layouts/default'); ?><h1><?= \$this->e(\$undefined) ?></h1>\n");
        $this->writeFile('site/themes/broken/collections/news/show.php', "<?php throw new RuntimeException('The news template is broken'); ?>\n");
        $app = Application::boot(new Paths($this->tempDir() . '/site', $this->tempDir() . '/storage'), ['app.theme' => 'broken']);
        $report = new Report('broken');

        (new RenderSmoke())->run($app, $report);

        $lines = array_map(static fn(Finding $f): string => $f->format(), $report->findings());
        $news = Cast::string($this->db->scalar("SELECT slug FROM entries WHERE collection = 'news' AND locale = 'en' ORDER BY published_at DESC, id DESC LIMIT 1"));
        self::assertContains('ERROR [render] /news/' . $news . ' 500: Unhandled RuntimeException: The news template is broken (themes/broken/collections/news/show.php:1)', $lines);
        self::assertNotEmpty(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'ERROR [render] /ru/') && str_contains($l, ' 500: Unhandled ErrorException: Undefined variable $undefined')), implode("\n", $lines));
        self::assertNotEmpty(array_filter($lines, static fn(string $l): bool => str_contains($l, 'WARNING [render] /tag/') && str_ends_with($l, 'An <img> without alt (decorative images: alt="")')));
    }
}
