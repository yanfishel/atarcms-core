<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Field\Field;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;

/**
 * An archive path with a trailing slash ("/blog/") has its markdown twin without it ("/blog.md"). An archive at "/"
 * ("/index.md") is covered by the route tests only: the fixture site's own module owns "/".
 */
final class SlashArchiveMarkdownTest extends ContentFunctionalTestCase
{
    protected function application(): Application
    {
        /** @var list<Collection> $collections */
        $collections = require static::SITE . '/config/collections.php';
        $blog = Collection::make('blog')->label('Blog')->route('/blog/{slug}')->archive('/blog/')->fields([Field::richText('body')]);

        return Application::boot(new Paths(static::SITE), ['collections' => [...$collections, $blog]]);
    }

    public function testTheTwinDropsTheTrailingSlash(): void
    {
        $this->entry('blog', 'First post', ['slug' => 'first']);

        $twin = $this->browser->get('/blog.md');
        $archive = $this->browser->get('/blog/');

        self::assertSame(200, $twin->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $twin->getHeaderLine('Content-Type'));
        self::assertSame('<https://localhost/blog/>; rel="canonical"', $twin->getHeaderLine('Link'));
        self::assertStringContainsString("\n# Blog\n", (string) $twin->getBody());
        self::assertStringContainsString("\n- [First post](https://localhost/blog/first) — ", (string) $twin->getBody());
        self::assertSame(200, $archive->getStatusCode());
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="/blog.md">', (string) $archive->getBody());
        self::assertContains('</blog.md>; rel="alternate"; type="text/markdown"', $archive->getHeader('Link'));
    }
}
