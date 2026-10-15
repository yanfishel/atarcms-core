<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Content\TermService;

final class TermPagesTest extends ContentFunctionalTestCase
{
    public function testListsOnlyThePublicEntriesThatCarryTheTerm(): void
    {
        $php = $this->term('topics', 'PHP <script>');
        $go = $this->term('topics', 'Go');
        $now = $this->clock->now();
        $this->entry('articles', 'Old', ['slug' => 'old', 'publishedAt' => $now->modify('-2 days'), 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'New', ['slug' => 'new', 'publishedAt' => $now->modify('-1 day'), 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft, 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'Soon', ['slug' => 'soon', 'status' => EntryStatus::Scheduled, 'publishedAt' => $now->modify('+1 day'), 'terms' => ['topics' => [$php->id]]]);
        $this->entry('articles', 'Other', ['slug' => 'other', 'terms' => ['topics' => [$go->id]]]);

        $response = $this->browser->get('/topics/' . $php->slug);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('php-script', $php->slug);
        self::assertStringContainsString('<h1 class="term" data-taxonomy="topics">PHP &lt;script&gt;</h1>', $body);
        self::assertStringNotContainsString('PHP <script>', $body);
        self::assertSame(['New', 'Old'], self::titles($body));
        self::assertStringContainsString('<p class="pages">1/1</p>', $body);
        self::assertStringContainsString('<li class="term-entry"><a href="/articles/new">New</a></li>', $body);
    }

    public function testPaginatesAndRejectsMissingPagesAndTerms(): void
    {
        $php = $this->term('topics', 'PHP');
        foreach (['a', 'b', 'c'] as $i => $slug) {
            $this->entry('articles', strtoupper($slug), ['slug' => $slug, 'publishedAt' => $this->clock->now()->modify('-' . ($i + 1) . ' hours'), 'terms' => ['topics' => [$php->id]]]);
        }
        $empty = $this->term('topics', 'Empty');
        // "regions" has no route; creating the term first proves the 404 below really comes from the
        // taxonomy having no route, not merely from "no such term".
        $this->term('regions', 'North');

        self::assertSame(['A', 'B'], self::titles((string) $this->browser->get('/topics/php')->getBody()));
        self::assertSame(['C'], self::titles((string) $this->browser->get('/topics/php?page=2')->getBody()));
        self::assertSame(200, $this->browser->get('/topics/' . $empty->slug)->getStatusCode(), 'an empty term still has page 1');
        foreach (['/topics/php?page=3', '/topics/php?page=0', '/topics/php?page=x', '/topics/ghost', '/topics/PHP', '/regions/north'] as $path) {
            self::assertSame(404, $this->browser->get($path)->getStatusCode(), $path);
        }
    }

    public function testTermPagesAreLocalizedWithAlternates(): void
    {
        $php = $this->term('topics', 'PHP');
        $this->app->container()->service(TermService::class)->createTranslation($php, 'ru', null, null);
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id]]]);
        $this->translation($hello, 'ru', 'Привет', 'privet');

        $russian = (string) $this->browser->get('/ru/topics/php')->getBody();

        self::assertSame(['Привет'], self::titles($russian));
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://localhost/topics/php">', $russian);
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru/topics/php">', $russian);
        self::assertSame(['Hello'], self::titles((string) $this->browser->get('/topics/php')->getBody()));
        self::assertSame(404, $this->browser->get('/ru/topics/ghost')->getStatusCode());
    }

    public function testATaxonomyTemplateWinsOverTheGenericOne(): void
    {
        $this->term('tags', 'News & more');

        self::assertStringContainsString('<h1 class="tag">News &amp; more</h1>', (string) $this->browser->get('/tags/news-more')->getBody());
    }

    /**
     * @return list<string>
     */
    private static function titles(string $body): array
    {
        preg_match_all('~<li class="term-entry"><a href="[^"]*">([^<]*)</a></li>~', $body, $matches);

        return $matches[1];
    }
}
