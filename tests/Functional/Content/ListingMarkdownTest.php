<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\Database\Connection;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Tests\Support\QueryCounter;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Term pages and collection archives have markdown twins at their address + ".md" (ListingMarkdown), resolved like
 * the pages themselves; "?page=N" lists the same entries as that HTML page.
 */
final class ListingMarkdownTest extends ContentFunctionalTestCase
{
    public function testATagPageHasAMarkdownTwin(): void
    {
        $this->entry('articles', 'Hello [world]', ['slug' => 'hello', 'terms' => ['tags' => ['php']]]);

        $response = $this->browser->get('/tags/php.md');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('<https://localhost/tags/php>; rel="canonical"', $response->getHeaderLine('Link'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'stateless');
        self::assertMatchesRegularExpression(
            '~\A---\ntitle: "php"\nurl: "https://localhost/tags/php"\nlang: "en"\nlast_updated: "[0-9T:+-]+"\n---\n\n~',
            $body,
        );
        self::assertSame(
            "# php\n\n- Language: en\n\n## Entries\n\n- [Hello \\[world\\]](https://localhost/articles/hello) — Sep 28, 2026\n",
            self::withoutFrontmatter($body),
        );
    }

    public function testATermWithTranslationsAndChildren(): void
    {
        $php = $this->term('topics', 'PHP');
        $this->term('topics', 'Laravel', ['parent' => $php]);
        $this->translate($php, 'ru', 'ПХП', 'php');
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id]]]);

        $body = (string) $this->browser->get('/topics/php.md')->getBody();

        self::assertStringContainsString("\ntitle: \"PHP\"\nurl: \"https://localhost/topics/php\"\n", $body, 'only sections have descriptions');
        self::assertSame(
            "# PHP\n\n- Language: en (translations: [ru](https://localhost/ru/topics/php.md))\n\n"
            . "## Entries\n\n- [Hello](https://localhost/articles/hello) — Sep 28, 2026\n\n"
            . "## Topics\n\n- [Laravel](https://localhost/topics/laravel)\n",
            self::withoutFrontmatter($body),
        );
        $russian = $this->browser->get('/ru/topics/php.md');
        self::assertSame(200, $russian->getStatusCode());
        self::assertStringContainsString("\n# ПХП\n\n- Language: ru (translations: [en](https://localhost/topics/php.md))\n", (string) $russian->getBody());
    }

    public function testPagesOfATwinListTheEntriesOfTheSameHtmlPage(): void
    {
        $php = $this->term('topics', 'PHP');
        foreach (['One' => '2026-09-01', 'Two' => '2026-09-02', 'Three' => '2026-09-03'] as $title => $date) {
            $this->entry('articles', $title, ['slug' => strtolower($title), 'terms' => ['topics' => [$php->id]], 'publishedAt' => self::utc($date . ' 12:00:00')]);
        }

        $first = (string) $this->browser->get('/topics/php.md')->getBody();
        $second = $this->browser->get('/topics/php.md?page=2');

        self::assertStringContainsString("\n- Page 1 of 2: [next](https://localhost/topics/php.md?page=2)\n", $first);
        self::assertStringContainsString("\n- [Three](https://localhost/articles/three) — Sep 3, 2026\n- [Two](https://localhost/articles/two) — Sep 2, 2026\n", $first);
        self::assertStringNotContainsString('[One]', $first);
        self::assertSame('<https://localhost/topics/php?page=2>; rel="canonical"', $second->getHeaderLine('Link'));
        self::assertStringContainsString("\nurl: \"https://localhost/topics/php?page=2\"\n", (string) $second->getBody());
        self::assertStringContainsString("\n- Page 2 of 2: [previous](https://localhost/topics/php.md)\n", (string) $second->getBody());
        self::assertStringContainsString("\n- [One](https://localhost/articles/one) — Sep 1, 2026\n", (string) $second->getBody());
        self::assertSame(404, $this->browser->get('/topics/php.md?page=3')->getStatusCode());
        self::assertSame(404, $this->browser->get('/topics/php.md?page=x')->getStatusCode());
        self::assertSame(404, $this->browser->get('/topics/missing.md')->getStatusCode());
    }

    public function testAnArchiveHasAMarkdownTwin(): void
    {
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(metaDescription: 'A test site.'), null, null);
        foreach (['One' => '2026-09-01', 'Two' => '2026-09-02', 'Three' => '2026-09-03'] as $title => $date) {
            $this->entry('articles', $title, ['slug' => strtolower($title), 'publishedAt' => self::utc($date . ' 12:00:00')]);
        }

        $response = $this->browser->get('/articles.md');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('<https://localhost/articles>; rel="canonical"', $response->getHeaderLine('Link'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'stateless');
        self::assertMatchesRegularExpression(
            '~\A---\ntitle: "Articles"\ndescription: "A test site."\nurl: "https://localhost/articles"\nlang: "en"\nlast_updated: "[0-9T:+-]+"\n---\n\n~',
            $body,
        );
        self::assertSame(
            "# Articles\n\n- Language: en (translations: [ru](https://localhost/ru/articles.md))\n"
            . "- Page 1 of 2: [next](https://localhost/articles.md?page=2)\n\n"
            . "## Entries\n\n- [Three](https://localhost/articles/three) — Sep 3, 2026\n- [Two](https://localhost/articles/two) — Sep 2, 2026\n",
            self::withoutFrontmatter($body),
        );
        self::assertStringContainsString("\n- [One](https://localhost/articles/one) — Sep 1, 2026\n", (string) $this->browser->get('/articles.md?page=2')->getBody());
        self::assertSame(404, $this->browser->get('/articles.md?page=3')->getStatusCode());
    }

    public function testAnEmptyArchiveAndACollectionWithoutTranslations(): void
    {
        $empty = (string) $this->browser->get('/ru/articles.md')->getBody();

        self::assertMatchesRegularExpression('~\A---\ntitle: "Articles"\nurl: "https://localhost/ru/articles"\nlang: "ru"\n---\n\n# Articles\n\n- Language: ru \(translations: \[en\]\(https://localhost/articles.md\)\)\n\z~', $empty);
        self::assertSame(200, $this->browser->get('/events.md')->getStatusCode());
        self::assertStringContainsString("\n- Language: en\n", (string) $this->browser->get('/events.md')->getBody());
        self::assertSame(404, $this->browser->get('/ru/events.md')->getStatusCode());
    }

    public function testSectionsAnswerAtTheRootLikeTheirPages(): void
    {
        $guides = $this->term('sections', 'Guides', ['metaDescription' => 'Learn things.']);
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->term('sections', 'History', ['metaDescription' => 'Our past.']);
        $this->entry('stories', 'Basics', ['slug' => 'basics', 'terms' => ['sections' => [$php->id]]]);
        $this->entry('info', 'Imprint', ['slug' => 'imprint']);

        $section = $this->browser->get('/guides/php.md');
        $body = (string) $section->getBody();

        self::assertSame(200, $section->getStatusCode());
        self::assertSame('<https://localhost/guides/php>; rel="canonical"', $section->getHeaderLine('Link'));
        self::assertStringContainsString("\n# PHP\n\n- Language: en\n\n## Entries\n\n- [Basics](https://localhost/guides/php/basics) — Sep 28, 2026\n", $body);
        self::assertStringNotContainsString('> Learn', $body, 'the quote is the section\'s own description only');
        self::assertStringContainsString("\ndescription: \"Learn things.\"\n", $body, 'a section inherits its parent\'s description');
        $history = (string) $this->browser->get('/history.md')->getBody();
        self::assertStringContainsString("\ndescription: \"Our past.\"\n", $history);
        self::assertStringEndsWith("\n# History\n\n> Our past.\n\n- Language: en\n", $history, 'an empty section with its own description');
        self::assertStringStartsWith("---\ntitle: \"Imprint\"", (string) $this->browser->get('/imprint.md')->getBody(), 'a page still wins');
        self::assertStringStartsWith("---\ntitle: \"Basics\"", (string) $this->browser->get('/guides/php/basics.md')->getBody(), 'an article too');

        $redirects = ['/guides.md' => [302, '/guides/php.md'], '/php.md' => [301, '/guides/php.md'], '/guides/php.md?x=1' => [200, '']];
        foreach ($redirects as $from => [$status, $to]) {
            $response = $this->browser->get($from);
            self::assertSame($status, $response->getStatusCode(), $from);
            self::assertSame($to, $response->getHeaderLine('Location'), $from);
        }
        self::assertSame(404, $this->browser->get('/guides/ghost.md')->getStatusCode());
    }

    public function testAnEntryWithoutAPageIsNamedAsPlainText(): void
    {
        $history = $this->term('sections', 'History');
        $story = $this->entry('stories', '1. Intro', ['slug' => 'intro', 'terms' => ['sections' => [$history->id], 'tags' => ['php']]]);
        // A story without its section has no address (data written around EntryTerms, which requires one).
        $this->app->container()->service(Connection::class)->execute('DELETE FROM entry_terms WHERE entry_id = ? AND term_id = ?', [$story->id, $history->id]);

        self::assertStringContainsString("\n## Entries\n\n- 1\\. Intro — Sep 28, 2026\n", (string) $this->browser->get('/tags/php.md')->getBody(), 'no nested list');
    }

    public function testAPreviousSlugLeadsToTheCurrentTwin(): void
    {
        $this->rename($this->term('tags', 'PHP'), 'php-lang');
        $this->rename($this->term('sections', 'History'), 'past');

        foreach (['/tags/php.md?page=2' => '/tags/php-lang.md?page=2', '/history.md' => '/past.md'] as $from => $to) {
            $response = $this->browser->get($from);
            self::assertSame(301, $response->getStatusCode(), $from);
            self::assertSame($to, $response->getHeaderLine('Location'), $from);
        }
        self::assertSame('/past', $this->browser->get('/history')->getHeaderLine('Location'), 'the page keeps its own redirect');
    }

    public function testASiteHiddenFromSearchHasNoListingTwins(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['tags' => ['php']]]);
        $this->term('sections', 'History');
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(hideFromSearch: true), null, null);

        foreach (['/tags/php.md', '/articles.md', '/history.md'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
        self::assertSame(200, $this->browser->get('/tags/php')->getStatusCode(), 'the page itself stays');
    }

    public function testMoreEntriesCostNoMoreQueries(): void
    {
        $db = $this->app->container()->service(Connection::class);
        $this->entry('articles', 'One', ['slug' => 'one', 'terms' => ['tags' => ['php']]]);
        $this->entry('stories', 'Story', ['slug' => 'story', 'terms' => ['sections' => [$this->term('sections', 'History')->id], 'tags' => ['php']]]);
        $tag = QueryCounter::measure($db, fn() => $this->browser->get('/tags/php.md'));
        $archive = QueryCounter::measure($db, fn() => $this->browser->get('/articles.md'));

        $this->entry('articles', 'Two', ['slug' => 'two', 'terms' => ['tags' => ['php']]]);
        $this->entry('stories', 'Tale', ['slug' => 'tale', 'terms' => ['sections' => [$this->term('sections', 'Future')->id], 'tags' => ['php']]]);

        self::assertSame($tag, QueryCounter::measure($db, fn() => $this->browser->get('/tags/php.md')));
        self::assertSame($archive, QueryCounter::measure($db, fn() => $this->browser->get('/articles.md')));
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /**
     * The markdown after the twin's frontmatter block.
     */
    private static function withoutFrontmatter(string $body): string
    {
        self::assertStringStartsWith("---\ntitle: ", $body);

        return explode("\n---\n\n", $body, 2)[1] ?? self::fail('no frontmatter');
    }

    private function translate(Term $term, string $locale, string $name, string $slug): Term
    {
        $service = $this->app->container()->service(TermService::class);
        $translation = $service->createTranslation($term, $locale, null, null);

        return $service->update($translation, new TermInput($name, $slug, $translation->parentId), null, null);
    }

    private function rename(Term $term, string $slug): Term
    {
        return $this->app->container()->service(TermService::class)->update($term, new TermInput($term->name, $slug, $term->parentId), null, null);
    }
}
