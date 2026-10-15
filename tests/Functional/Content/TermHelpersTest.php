<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\TermService;

final class TermHelpersTest extends ContentFunctionalTestCase
{
    public function testEntryPagesLinkTheirTermsEscaped(): void
    {
        $php = $this->term('topics', 'PHP <b>');
        $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id], 'tags' => ['News']]]);

        $body = (string) $this->browser->get('/articles/hello')->getBody();

        self::assertStringContainsString('<ul class="topics"><li><a href="/topics/php-b">PHP &lt;b&gt;</a></li></ul>', $body);
        self::assertStringContainsString('<ul class="tags"><li><a href="/tags/news">News</a></li></ul>', $body);
    }

    public function testListsLoadTheTermsOfAllTheirEntries(): void
    {
        $this->entry('articles', 'One', ['slug' => 'one', 'publishedAt' => $this->clock->now()->modify('-1 hour'), 'terms' => ['tags' => ['Alpha']]]);
        $this->entry('articles', 'Two', ['slug' => 'two', 'publishedAt' => $this->clock->now()->modify('-2 hours'), 'terms' => ['tags' => ['Beta', 'alpha']]]);

        $body = (string) $this->browser->get('/articles')->getBody();

        self::assertStringContainsString(
            '<p class="entry-tags"><span data-entry="one">Alpha</span><span data-entry="two">Beta</span><span data-entry="two">Alpha</span></p>',
            $body,
        );
    }

    public function testTermLinksFollowTheTermsLocale(): void
    {
        $php = $this->term('topics', 'PHP');
        $hello = $this->entry('articles', 'Hello', ['slug' => 'hello', 'terms' => ['topics' => [$php->id]]]);
        $this->app->container()->service(TermService::class)->createTranslation($php, 'ru', null, null);
        $this->translation($hello, 'ru', 'Привет', 'privet');

        self::assertStringContainsString(
            '<ul class="topics"><li><a href="/ru/topics/php">PHP</a></li></ul>',
            (string) $this->browser->get('/ru/articles/privet')->getBody(),
        );
    }
}
