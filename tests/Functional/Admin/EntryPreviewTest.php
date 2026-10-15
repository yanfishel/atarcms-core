<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Roles;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryStatus;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use Psr\Http\Message\ResponseInterface;

/**
 * The entry form's Preview posts the unsaved form to the theme: the page as it would look, nothing saved.
 */
final class EntryPreviewTest extends ContentFunctionalTestCase
{
    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(Roles::class, new Roles([
            ...Roles::DEFAULTS,
            'viewer' => ['label' => 'Viewer', 'permissions' => ['entries.articles.view']],
        ]));
    }

    public function testANewEntryIsRenderedByTheThemeWithoutBeingSaved(): void
    {
        $php = $this->term('topics', 'PHP');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $response = $this->preview('/admin/content/articles/preview', [
            'locale' => 'en',
            'title' => 'Unsaved <title>',
            'status' => 'draft',
            'fields' => ['body' => '<p>Body<script>x()</script></p>', 'tags' => 'Fresh'],
            'terms' => ['topics' => [(string) $php->id]],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('<h1>Unsaved &lt;title&gt;</h1>', $html);
        self::assertStringContainsString('<div class="body"><p>Body</p></div>', $html);
        self::assertStringContainsString('<ul class="topics"><li><a href="/topics/php">PHP</a></li></ul>', $html);
        self::assertStringContainsString('<ul class="tags"><li><a href="/tags/fresh">Fresh</a></li></ul>', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('hreflang', $html);
        self::assertStringNotContainsString('og:', $html);
        self::assertStringNotContainsString('edit-link', $html);
        self::assertSame(0, Cast::int($this->db->selectOne('SELECT COUNT(*) AS n FROM entries')['n'] ?? -1));
        self::assertSame(['term.created'], $this->auditActions(), 'nothing is saved or audited by the preview');
        self::assertNull($this->terms()->findByName('tags', 'en', 'Fresh'));
    }

    public function testAnExistingEntryShowsTheFormValuesAndKeepsTheStoredOnes(): void
    {
        $editor = $this->createUser('ed@example.com', 'editor');
        $entry = $this->entry('articles', 'Stored', ['fields' => ['body' => '<p>Old</p>']]);
        $this->actingAs($editor);

        $html = (string) $this->preview('/admin/content/articles/' . $entry->id . '/preview', [
            'version' => 'anything: a preview checks no version',
            'locale' => 'en',
            'title' => 'Changed',
            'status' => 'published',
            'fields' => ['body' => '<p>New</p>'],
        ])->getBody();

        self::assertStringContainsString('<h1>Changed</h1>', $html);
        self::assertStringContainsString('<p>New</p>', $html);
        self::assertStringNotContainsString('edit-link', $html, 'a preview has no edit link');
        $stored = $this->app->container()->service(EntryRepository::class)->find($entry->id);
        self::assertSame('Stored', $stored?->title);
        self::assertEquals($entry->updatedAt, $stored->updatedAt);
    }

    public function testAStoryShowsItsChosenSectionAndATranslationItsLanguage(): void
    {
        $guides = $this->term('sections', 'Guides');
        $sub = $this->term('sections', 'Sub', ['parent' => $guides]);
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $html = (string) $this->preview('/admin/content/stories/preview', [
            'locale' => 'en',
            'title' => 'Story',
            'status' => 'draft',
            'terms' => ['sections' => [(string) $sub->id]],
        ])->getBody();
        self::assertStringContainsString('<nav class="trail"><a href="/guides">Guides</a> / <a href="/guides/sub">Sub</a> / </nav>', $html);

        $source = $this->entry('articles', 'Source');
        $ru = $this->translation($source, 'ru', 'Перевод', 'perevod', EntryStatus::Draft);
        $html = (string) $this->preview('/admin/content/articles/' . $ru->id . '/preview', [
            'locale' => 'ru',
            'title' => 'Новый заголовок',
            'status' => 'draft',
        ])->getBody();
        self::assertStringContainsString('<html lang="ru">', $html);
        self::assertStringContainsString('<h1>Новый заголовок</h1>', $html);
    }

    public function testASinglePageAndBestEffortValues(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $html = (string) $this->preview('/admin/content/contact/preview', [
            'locale' => 'en',
            'title' => 'Contact us',
            'status' => 'draft',
        ])->getBody();
        self::assertStringContainsString('<div class="generic" data-collection="contact">', $html);
        self::assertStringContainsString('<h1>Contact us</h1>', $html, 'the required phone is missing: previewed anyway');

        $response = $this->preview('/admin/content/articles/preview', ['locale' => 'en', 'title' => '', 'status' => 'draft', 'fields' => ['rating' => '99']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Articles</h1>', (string) $response->getBody());
    }

    public function testPreviewNeedsTheRightsOfSavingAndAToken(): void
    {
        $author = $this->createUser('au@example.com', 'author');
        $published = $this->entry('articles', 'Published', ['author' => $author]);
        $draft = $this->entry('articles', 'Draft', ['author' => $author, 'status' => EntryStatus::Draft]);
        $this->actingAs($author);

        self::assertSame(200, $this->preview('/admin/content/articles/' . $draft->id . '/preview', ['title' => 'Mine'])->getStatusCode());
        self::assertSame(403, $this->preview('/admin/content/articles/' . $published->id . '/preview', ['title' => 'Not mine to publish'])->getStatusCode());
        self::assertSame(404, $this->preview('/admin/content/pages/' . $draft->id . '/preview', ['title' => 'Other collection'])->getStatusCode());
        self::assertSame(404, $this->preview('/admin/content/notes/preview', ['title' => 'No route'])->getStatusCode());
        self::assertSame(419, $this->browser->post('/admin/content/articles/preview', ['title' => 'No token'])->getStatusCode());

        $this->actingAs($this->createUser('viewer@example.com', 'viewer'));
        self::assertSame(403, $this->preview('/admin/content/articles/preview', ['title' => 'New'])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function preview(string $uri, array $data): ResponseInterface
    {
        return $this->browser->post($uri, ['_csrf' => $this->csrfToken(), ...$data]);
    }
}
