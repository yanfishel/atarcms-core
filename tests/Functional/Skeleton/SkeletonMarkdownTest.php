<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryService;
use AtarCms\Http\Exception\ValidationFailed;

/**
 * The skeleton's home page is a single at "/": its markdown twin answers at /index.md (EntryMarkdown), and no page
 * may take the slug "index", which that twin would hide.
 */
final class SkeletonMarkdownTest extends SkeletonTestCase
{
    public function testTheHomePageTwinIsIndexMd(): void
    {
        $this->create('home', 'en', new EntryInput('', fields: ['lead' => 'A tidy little site']));
        $this->create('home', 'ru', new EntryInput('', fields: ['lead' => 'Аккуратный сайт']));

        $home = $this->browser->get('/index.md');
        $russian = $this->browser->get('/ru/index.md');

        self::assertSame(200, $home->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $home->getHeaderLine('Content-Type'));
        self::assertSame('<https://localhost/>; rel="canonical"', $home->getHeaderLine('Link'));
        self::assertStringContainsString("\n- Language: en (translations: [ru](https://localhost/ru/index.md))\n", (string) $home->getBody());
        self::assertStringNotContainsString('Published', (string) $home->getBody(), 'a plain single has no publication date');
        self::assertSame(200, $russian->getStatusCode());
        self::assertSame('<https://localhost/ru>; rel="canonical"', $russian->getHeaderLine('Link'));
        self::assertSame(404, $this->browser->get('/he/index.md')->getStatusCode(), 'no home page in that language yet');
    }

    public function testNoPageTakesTheSlugIndex(): void
    {
        try {
            $this->create('pages', 'en', new EntryInput('Index', slug: 'index'));
            self::fail('the slug "index" was accepted');
        } catch (ValidationFailed $failed) {
            self::assertSame(['slug' => 'validation.slug.reserved'], $failed->errors);
        }
    }

    private function create(string $collection, string $locale, EntryInput $input): void
    {
        $container = $this->app->container();
        $container->service(EntryService::class)->create($container->service(Collections::class)->get($collection), $locale, $input, null, null);
    }
}
