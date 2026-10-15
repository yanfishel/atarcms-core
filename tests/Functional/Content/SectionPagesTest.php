<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Database\Connection;
use AtarCms\Tests\Support\QueryCounter;

/**
 * The fixture theme's taxonomies/sections/show.php renders a story with a summary as an announce and one without as
 * the full text, with the section trail (termTrail()) and batched story URLs (entryUrls()).
 */
final class SectionPagesTest extends ContentFunctionalTestCase
{
    public function testASectionShowsAnnouncesAndFullStories(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->entry('stories', 'Announced', [
            'publishedAt' => $this->clock->now()->modify('-1 hour'),
            'terms' => ['sections' => [$php->id]],
            'fields' => ['summary' => '<p>Short intro</p>', 'body' => '<p>Long text</p>'],
        ]);
        $this->entry('stories', 'Inline', [
            'publishedAt' => $this->clock->now()->modify('-2 hours'),
            'terms' => ['sections' => [$php->id]],
            'fields' => ['body' => '<p>All of it</p>'],
        ]);

        $body = (string) $this->browser->get('/guides/php')->getBody();

        self::assertStringContainsString('<nav class="trail"><a href="/guides">Guides</a> / <a href="/guides/php">PHP</a> / </nav>', $body);
        self::assertStringContainsString('<article class="announce"><h2><a href="/guides/php/announced">Announced</a></h2><div class="summary"><p>Short intro</p></div></article>', $body);
        self::assertStringContainsString('<article class="full"><h2>Inline</h2><div class="body"><p>All of it</p></div></article>', $body);
        self::assertStringNotContainsString('Long text', $body);
    }

    public function testMoreStoriesCostNoMoreQueries(): void
    {
        $php = $this->term('sections', 'PHP', ['parent' => $this->term('sections', 'Guides')]);
        $story = fn(string $title) => $this->entry('stories', $title, [
            'terms' => ['sections' => [$php->id]],
            'fields' => ['summary' => '<p>' . $title . '</p>'],
        ]);
        $story('One');
        $db = $this->app->container()->service(Connection::class);
        $this->browser->get('/guides/php');
        $one = QueryCounter::measure($db, fn() => $this->browser->get('/guides/php'));

        $story('Two');
        $this->browser->get('/guides/php');

        self::assertSame($one, QueryCounter::measure($db, fn() => $this->browser->get('/guides/php')));
    }

    public function testADeeperSectionCostsNoMoreQueries(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $basics = $this->term('sections', 'Basics', ['parent' => $php]);
        $this->entry('stories', 'One', ['slug' => 'one', 'terms' => ['sections' => [$php->id]]]);
        $this->entry('stories', 'Two', ['slug' => 'two', 'terms' => ['sections' => [$basics->id]]]);
        $db = $this->app->container()->service(Connection::class);
        $this->browser->get('/guides/php/one');
        $this->browser->get('/guides/php/basics/two');

        $two = QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/one'));
        $three = QueryCounter::measure($db, fn() => $this->browser->get('/guides/php/basics/two'));

        self::assertSame($two, $three);
        self::assertStringContainsString(
            '<nav class="trail"><a href="/guides">Guides</a> / <a href="/guides/php">PHP</a> / <a href="/guides/php/basics">Basics</a> / </nav>',
            (string) $this->browser->get('/guides/php/basics/two')->getBody(),
        );
    }

    public function testAStoryShowsItsSectionTrail(): void
    {
        $guides = $this->term('sections', 'Guides');
        $php = $this->term('sections', 'PHP', ['parent' => $guides]);
        $this->entry('stories', 'Hello', ['slug' => 'hello', 'terms' => ['sections' => [$php->id]]]);

        $body = (string) $this->browser->get('/guides/php/hello')->getBody();

        self::assertStringContainsString('<nav class="trail"><a href="/guides">Guides</a> / <a href="/guides/php">PHP</a> / </nav>', $body);
    }
}
