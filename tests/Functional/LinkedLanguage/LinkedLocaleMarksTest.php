<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\LinkedLanguage;

use AtarCms\Admin\LanguageMode;
use AtarCms\Kernel\Container;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * The linked mode leaves out the language marks beside titles and field labels (user decision of Plan 37): the screen
 * and its interface speak one language already. Marks that tell languages apart on one screen stay: the media's
 * alternative texts and the top bar's switch (the entry lists' Languages column of plain flags stays, the term lists' is gone: LinkedScreensTest). The separate mode keeps every mark.
 */
final class LinkedLocaleMarksTest extends LinkedLanguageTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    /**
     * @return array<string, string> page => its body
     */
    private function pages(): array
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $code = $this->term('topics', 'Code');
        $section = $this->term('sections', 'News');
        $home = $this->menuItem('main', 'Home', ['url' => '/']);
        $this->actingAs($this->createUser('ann@example.com'));
        $pages = [];
        foreach ([
            '/admin/content/articles',
            '/admin/content/articles/new',
            '/admin/content/articles/' . $hello->id,
            '/admin/content/articles/' . $hello->id . '/revisions',
            '/admin/content/articles/' . $hello->id . '/delete',
            '/admin/content/pages/order',
            '/admin/taxonomies/topics',
            '/admin/taxonomies/topics/' . $code->id,
            '/admin/taxonomies/topics/' . $code->id . '/delete',
            '/admin/sections',
            '/admin/sections/' . $section->id,
            '/admin/menus/main',
            '/admin/menus/main/' . $home->id,
            '/admin/menus/main/' . $home->id . '/delete',
            '/admin/settings',
        ] as $page) {
            $response = $this->browser->get($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            $pages[$page] = (string) $response->getBody();
        }
        foreach (['/admin/taxonomies/topics/' . $code->id . ' (dialog)', '/admin/menus/main/' . $home->id . ' (dialog)'] as $page) {
            $pages[$page] = (string) $this->browser->get(explode(' ', $page)[0], ['HX-Request' => 'true'])->getBody();
        }

        return $pages;
    }

    public function testTitlesAndLabelsCarryNoLanguageMark(): void
    {
        foreach ($this->pages() as $page => $body) {
            $main = self::withoutTopBar($body);
            self::assertStringNotContainsString('admin-locale-badge', $main, $page);
            self::assertStringNotContainsString('admin-locale-flag', $main, $page);
            self::assertStringNotContainsString('Content language: English', $main, $page);
        }
    }

    public function testTheSeparateModeKeepsTheMarks(): void
    {
        $this->mode = LanguageMode::Separate;
        $this->bootApp();

        foreach ($this->pages() as $page => $body) {
            if (str_ends_with($page, '/admin/content/articles/new') || str_contains($page, '/revisions') || $page === '/admin/settings') {
                continue;
            }
            self::assertMatchesRegularExpression('~<h[12][^>]*>[^<]*(?:<[^>]+>[^<]*</[^>]+>)? <span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>~u', self::withoutTopBar($body), $page);
        }
        self::assertStringContainsString('<span class="admin-locale-badge" title="Content language: English" aria-hidden="true">EN</span>', self::withoutTopBar((string) $this->browser->get('/admin/content/articles/new')->getBody()));
        self::assertStringContainsString('aria-hidden="true">EN</span>', self::withoutTopBar((string) $this->browser->get('/admin/settings')->getBody()), 'the per-language settings fields');
    }

    public function testTheMediaAlternativeTextsKeepTheirLanguagesApart(): void
    {
        $media = $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(), 'photo.jpg', 'image/jpeg'), null, null);
        $this->actingAs($this->createUser('ann@example.com'));

        $page = self::withoutTopBar((string) $this->browser->get('/admin/media/' . $media->id)->getBody());

        self::assertStringContainsString('<span class="admin-locale-badge" title="English" aria-hidden="true">EN</span>', $page);
        self::assertStringContainsString('<span class="admin-locale-badge" title="Русский" aria-hidden="true">RU</span>', $page);
    }

    public function testTheTopBarKeepsItsMarks(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $list = (string) $this->browser->get('/admin/content/articles')->getBody();

        self::assertStringContainsString('<span class="admin-locale-badge" title="Language: English" aria-hidden="true">EN</span></summary>', $list, 'the switch\'s trigger without a theme flag');
    }

    /**
     * The page without the top bar's language menu (which keeps its marks).
     */
    private static function withoutTopBar(string $body): string
    {
        return (string) preg_replace('~<details class="admin-menu admin-content-locale".*?</details>~s', '', $body);
    }
}
