<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\Router;
use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;

/**
 * Every public entry page has a markdown twin at its address + ".md" (EntryMarkdown), resolved like the page itself.
 */
final class EntryMarkdownTest extends ContentFunctionalTestCase
{
    public function testAnArticleHasAMarkdownTwin(): void
    {
        $php = $this->term('topics', 'PHP');
        $other = $this->entry('articles', 'Other [story]', ['slug' => 'other']);
        $this->entry('articles', 'Hello', [
            'slug' => 'hello',
            'metaDescription' => 'A greeting.',
            'terms' => ['topics' => [$php->id], 'tags' => ['php', 'cms']],
            'fields' => [
                'summary' => "Short text\nwith a second line",
                'body' => '<h2>Part one</h2><p>Hello <strong>world</strong>, see <a href="/articles/other">this</a>.</p>',
                'rating' => '7',
                'event_date' => '2026-10-20',
                'kind' => 'report',
                'featured' => '1',
                'video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'related' => [(string) $other->id],
            ],
        ]);

        $response = $this->browser->get('/articles/hello.md');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('<https://localhost/articles/hello>; rel="canonical"', $response->getHeaderLine('Link'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'stateless');
        self::assertStringStartsWith("# Hello\n\n> A greeting.\n\n- Published: Sep 28, 2026\n- Language: en\n", $body);
        self::assertStringContainsString("\n- Topics: [PHP](https://localhost/topics/php)\n", $body);
        self::assertStringContainsString("\n- rating: 7\n", $body);
        self::assertStringContainsString("\n- kind: Report\n", $body);
        self::assertStringContainsString("\n- featured: yes\n", $body);
        self::assertStringContainsString("\n- video: [YouTube video](https://www.youtube.com/watch?v=dQw4w9WgXcQ)\n", $body);
        self::assertStringContainsString("\n- related: [Other \\[story\\]](https://localhost/articles/other)\n", $body);
        self::assertStringContainsString("\n- tags: php, cms\n", $body);
        self::assertStringContainsString("\n- event_date: Oct 20, 2026\n", $body, 'a date field is wall-clock');
        self::assertStringContainsString("\n## summary\n\nShort text\\\nwith a second line\n", $body);
        self::assertStringContainsString(
            "\n## body\n\n### Part one\n\nHello **world**, see [this](https://localhost/articles/other).\n",
            $body,
            'rich text headings sit under the field heading',
        );
        self::assertStringEndsWith(".\n", $body);
    }

    public function testTranslationsLinkTheirMarkdownTwins(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet');

        $english = (string) $this->browser->get('/articles/hello.md')->getBody();
        $russian = $this->browser->get('/ru/articles/privet.md');

        self::assertStringContainsString("\n- Language: en (translations: [ru](https://localhost/ru/articles/privet.md))\n", $english);
        self::assertSame(200, $russian->getStatusCode());
        self::assertSame('<https://localhost/ru/articles/privet>; rel="canonical"', $russian->getHeaderLine('Link'));
        self::assertStringStartsWith("# Привет\n", (string) $russian->getBody());
        self::assertStringContainsString("\n- Language: ru (translations: [en](https://localhost/articles/hello.md))\n", (string) $russian->getBody());
        self::assertSame(404, $this->browser->get('/ru/articles/hello.md')->getStatusCode(), 'a slug of another language');
    }

    public function testAnUnpublishedTranslationIsNotLinked(): void
    {
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->translation($hello, 'ru', 'Привет', 'privet', EntryStatus::Draft);

        self::assertStringContainsString("\n- Language: en\n", (string) $this->browser->get('/articles/hello.md')->getBody());
        self::assertSame(404, $this->browser->get('/ru/articles/privet.md')->getStatusCode());
    }

    public function testANestedPageAnswersAtItsFullPath(): void
    {
        $about = $this->entry('pages', 'About', ['slug' => 'about']);
        $this->entry('pages', 'Team', ['slug' => 'team', 'parent' => $about, 'fields' => ['body' => '<ul><li>Ann</li><li>Bob</li></ul>']]);

        $page = $this->browser->get('/pages/about/team.md');
        $short = $this->browser->get('/pages/team.md');

        self::assertSame(200, $page->getStatusCode());
        self::assertStringStartsWith("# Team\n", (string) $page->getBody());
        self::assertStringContainsString("\n## body\n\n- Ann\n- Bob\n", (string) $page->getBody());
        self::assertSame(301, $short->getStatusCode());
        self::assertSame('/pages/about/team.md', $short->getHeaderLine('Location'), 'a missing parent leads to the canonical twin');
    }

    public function testAFullChainEntryAndItsSection(): void
    {
        $about = $this->term('sections', 'About us', ['slug' => 'about']);
        $history = $this->term('sections', 'History', ['parent' => $about]);
        $this->term('sections', 'Projects');
        $this->entry('stories', 'Founding', ['slug' => 'founding', 'terms' => ['sections' => [$history->id]], 'fields' => ['summary' => '<p>How we started</p>']]);

        $story = $this->browser->get('/about/history/founding.md');
        $wrong = $this->browser->get('/projects/founding.md');

        self::assertSame(200, $story->getStatusCode());
        self::assertSame('<https://localhost/about/history/founding>; rel="canonical"', $story->getHeaderLine('Link'));
        self::assertStringContainsString("\n- Sections: [History](https://localhost/about/history)\n", (string) $story->getBody());
        self::assertStringContainsString("\n## summary\n\nHow we started\n", (string) $story->getBody());
        self::assertSame(301, $wrong->getStatusCode());
        self::assertSame('/about/history/founding.md', $wrong->getHeaderLine('Location'));
        self::assertSame(404, $this->browser->get('/about/history.md')->getStatusCode(), 'a section page has no twin');
        self::assertSame(404, $this->browser->get('/about/history/ghost.md')->getStatusCode());
    }

    public function testARootPage(): void
    {
        $this->entry('info', 'Imprint', ['slug' => 'imprint', 'fields' => ['body' => '<p>Who we are</p>']]);

        $page = $this->browser->get('/imprint.md');

        self::assertSame(200, $page->getStatusCode());
        self::assertSame('<https://localhost/imprint>; rel="canonical"', $page->getHeaderLine('Link'));
        self::assertStringContainsString("\n## body\n\nWho we are\n", (string) $page->getBody());
        self::assertSame(404, $this->browser->get('/ghost.md')->getStatusCode());
    }

    public function testSingles(): void
    {
        $this->entry('contact', 'Contact us', ['fields' => ['phone' => '+1 555 0100']]);
        $this->entry('welcome', 'ignored', ['fields' => ['intro' => '<p>Hi there</p>']]);

        $contact = $this->browser->get('/contact.md');
        $welcome = (string) $this->browser->get('/welcome.md')->getBody();

        self::assertSame(200, $contact->getStatusCode());
        self::assertSame('<https://localhost/contact>; rel="canonical"', $contact->getHeaderLine('Link'));
        self::assertStringStartsWith("# Contact us\n\n- Published: Sep 28, 2026\n", (string) $contact->getBody());
        self::assertStringContainsString("\n- phone: +1 555 0100\n", (string) $contact->getBody());
        self::assertStringStartsWith("# Welcome\n\n- Language: en\n", $welcome, 'a plain single has no publication date');
        self::assertStringContainsString("\n## intro\n\nHi there\n", $welcome);
        self::assertSame(404, $this->browser->get('/ru/contact.md')->getStatusCode(), 'no entry in that language');
    }

    public function testDraftsScheduledEntriesAndWrongSlugsAreNotFound(): void
    {
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $this->clock->now()->modify('+1 day')]);
        $this->actingAs($this->createUser('admin@example.com'));

        foreach (['/articles/draft.md', '/articles/draft.md?preview=1', '/articles/soon.md', '/articles/nope.md', '/articles/.md'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
    }

    public function testOnlyRoutedCollectionsHaveTwins(): void
    {
        $names = array_map(static fn(Route $route): ?string => $route->name, $this->app->container()->service(Router::class)->routes());

        self::assertContains('content.articles.md', $names);
        self::assertContains('content.stories.md', $names);
        self::assertContains('content.info.md', $names);
        self::assertNotContains('content.notes.md', $names);
        self::assertNotContains('content.settings.md', $names);
    }

    public function testACollectionWithoutTranslationsHasNoTwinUnderAnotherLanguage(): void
    {
        $this->entry('events', 'Fair', ['slug' => 'fair', 'fields' => ['venue' => 'Hall']]);

        self::assertSame(200, $this->browser->get('/events/fair.md')->getStatusCode());
        self::assertSame(404, $this->browser->get('/ru/events/fair.md')->getStatusCode());
    }

    public function testASiteHiddenFromSearchHasNoTwins(): void
    {
        $history = $this->term('sections', 'History');
        $this->entry('stories', 'Founding', ['slug' => 'founding', 'terms' => ['sections' => [$history->id]]]);
        $this->entry('info', 'Imprint', ['slug' => 'imprint']);
        $this->rename($this->entry('articles', 'Hello', ['slug' => 'hello']), 'world');
        $this->entry('contact', 'Contact us', ['fields' => ['phone' => '1']]);
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);

        foreach (['/articles/world.md', '/articles/hello.md', '/contact.md', '/imprint.md', '/history/founding.md'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertSame(200, $this->browser->get('/articles/world')->getStatusCode(), 'the page itself stays');
    }

    public function testAPreviousSlugLeadsToTheCurrentTwin(): void
    {
        $this->rename($this->entry('articles', 'Hello', ['slug' => 'hello']), 'world');
        $this->rename($this->entry('info', 'About', ['slug' => 'about']), 'about-us');
        $history = $this->term('sections', 'History');
        $this->rename($this->entry('stories', 'Founding', ['slug' => 'founding', 'terms' => ['sections' => [$history->id]]]), 'start');

        $redirects = [
            '/articles/hello.md?utm=x' => '/articles/world.md?utm=x',
            '/about.md' => '/about-us.md',
            '/history/founding.md' => '/history/start.md',
            '/founding.md' => '/history/start.md',
        ];
        foreach ($redirects as $from => $to) {
            $response = $this->browser->get($from);
            self::assertSame(301, $response->getStatusCode(), $from);
            self::assertSame($to, $response->getHeaderLine('Location'), $from);
        }
        self::assertSame('/articles/world', $this->browser->get('/articles/hello')->getHeaderLine('Location'), 'the page keeps its own redirect');
        self::assertSame(404, $this->browser->get('/history.md')->getStatusCode(), 'never a section, nor its history');
    }

    public function testMediaFieldsLinkTheirOriginals(): void
    {
        $cover = $this->insertMedia('sea.jpg', ['en' => 'Sea [view]']);
        $first = $this->insertMedia('a.jpg');
        $second = $this->insertMedia('b.jpg', ['en' => 'Bee']);
        $pdf = $this->insertMedia('report.pdf', [], 'application/pdf');
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'fields' => ['cover' => $cover->id, 'images' => [$first->id, $second->id], 'attachment' => $pdf->id]]);
        $this->entry('pages', 'Home', ['slug' => 'home', 'fields' => ['hero' => [
            ['image' => $first->id, 'title' => 'Sea', 'subtitle' => 'Blue', 'url' => '/contact', 'button' => 'Write'],
            ['image' => $second->id, 'url' => 'https://example.org/x'],
        ]]]);

        $article = (string) $this->browser->get('/articles/hello.md')->getBody();
        $page = (string) $this->browser->get('/pages/home.md')->getBody();

        self::assertStringContainsString("\n- cover: ![Sea \\[view\\]](https://localhost/uploads/" . $cover->path . ")\n", $article);
        self::assertStringContainsString("\n- attachment: [report.pdf](https://localhost/uploads/" . $pdf->path . ")\n", $article);
        self::assertStringContainsString("\n## images\n\n- ![](https://localhost/uploads/" . $first->path . ")\n- ![Bee](https://localhost/uploads/" . $second->path . ")", $article);
        self::assertStringContainsString(
            "\n## Slides\n\n- ![](https://localhost/uploads/" . $first->path . ") Sea — Blue [Write](https://localhost/contact)\n"
            . '- ![Bee](https://localhost/uploads/' . $second->path . ') [https://example.org/x](https://example.org/x)',
            $page,
        );
    }

    public function testScalarValuesAndOmittedRelations(): void
    {
        $draft = $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'fields' => ['related' => [(string) $draft->id]]]);
        $note = $this->entry('notes', 'Secret', ['fields' => ['text' => 'x']]);
        $this->entry('events', 'Fair', ['slug' => 'fair', 'fields' => ['starts_at' => '2026-10-20T18:30', 'speakers' => [(string) $note->id]]]);

        $article = (string) $this->browser->get('/articles/hello.md')->getBody();
        $event = (string) $this->browser->get('/events/fair.md')->getBody();

        self::assertStringContainsString("\n- featured: no\n", $article, 'an unchecked checkbox is a value');
        self::assertStringNotContainsString('related', $article, 'a draft is never linked');
        self::assertStringContainsString("\n- starts_at: Oct 20, 2026, 18:30\n", $event, 'a datetime field is wall-clock');
        self::assertStringNotContainsString('speakers', $event, 'an entry without a page is never named');
        self::assertStringNotContainsString('Secret', $event);
    }

    public function testTitlesAndDescriptionsStayText(): void
    {
        $this->entry('articles', 'Learn C #', ['slug' => 'csharp', 'metaDescription' => '1. Not a list']);

        self::assertStringStartsWith("# Learn C \\#\n\n> 1\\. Not a list\n", (string) $this->browser->get('/articles/csharp.md')->getBody());
    }

    private function rename(Entry $entry, string $slug): Entry
    {
        return $this->app->container()->service(EntryService::class)->update($entry, new EntryInput(...[...(array) EntryInput::fromEntry($entry), 'slug' => $slug]), null, null);
    }

    /**
     * @param array<string, string> $alt
     */
    private function insertMedia(string $name, array $alt = [], string $mime = 'image/jpeg'): Media
    {
        $now = $this->clock->now();
        $media = $this->app->container()->service(MediaRepository::class);
        $id = $media->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . '.' . pathinfo($name, PATHINFO_EXTENSION),
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => str_starts_with($mime, 'image/') ? 1600 : null,
            'height' => str_starts_with($mime, 'image/') ? 1000 : null,
            'alt' => $alt,
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $media->find($id) ?? self::fail('no media');
    }
}
