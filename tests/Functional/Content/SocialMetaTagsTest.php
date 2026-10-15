<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Content;

use AtarCms\Content\EntryStatus;
use AtarCms\Database\Connection;
use AtarCms\Http\CanonicalUrl;
use AtarCms\Http\RequestMemo;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use AtarCms\View\Renderer;
use Nyholm\Psr7\Factory\Psr17Factory;

final class SocialMetaTagsTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testAnEntryPageDescribesItselfAsAnArticle(): void
    {
        $this->entry('articles', 'Hello <world>', ['slug' => 'hello', 'metaDescription' => 'Tips & "tricks"']);

        $body = (string) $this->browser->get('/articles/hello')->getBody();

        self::assertStringContainsString(implode("\n", [
            '<meta property="og:type" content="article">',
            '<meta property="og:title" content="Hello &lt;world&gt;">',
            '<meta property="og:description" content="Tips &amp; &quot;tricks&quot;">',
            '<meta property="og:url" content="https://localhost/articles/hello">',
            '<meta property="og:site_name" content="Fixture">',
            '<meta property="og:locale" content="en">',
            '<meta name="twitter:card" content="summary">',
        ]), $body);
        self::assertSame(1, substr_count($body, 'og:title'));
    }

    public function testTheGeneratedShareImageIsTheOgImageWithItsSize(): void
    {
        $photo = $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(1600, 1000), 'photo.jpg', 'image/jpeg'), null, null);
        $shared = $this->entry('articles', 'Trip', ['slug' => 'trip', 'metaTitle' => 'Рыбалка на Оке', 'fields' => ['cover' => $photo->id]]);
        $untitled = $this->entry('articles', 'Plain', ['slug' => 'plain', 'fields' => ['cover' => $photo->id]]);
        // A title only of characters the font lacks draws no card.
        $this->entry('articles', '🎣', ['slug' => 'emoji', 'fields' => ['cover' => $photo->id]]);
        $large = '?v=' . ($this->app->container()->service(ImageVariants::class)->get('large')?->version() ?? self::fail('no large variant'));

        $body = (string) $this->browser->get('/articles/trip')->getBody();
        self::assertStringContainsString(implode("\n", [
            '<meta property="og:image" content="https://localhost/uploads/share/' . $shared->shareImage . '">',
            '<meta property="og:image:width" content="1200">',
            '<meta property="og:image:height" content="630">',
            '<meta name="twitter:card" content="summary_large_image">',
        ]), $body);

        self::assertNotNull($untitled->shareImage);
        self::assertStringContainsString('<meta property="og:image" content="https://localhost/uploads/share/' . $untitled->shareImage . '">', (string) $this->browser->get('/articles/plain')->getBody(), 'no title for search engines: the card shows the title');

        $plain = (string) $this->browser->get('/articles/emoji')->getBody();
        self::assertStringContainsString('<meta property="og:image" content="https://localhost/media/large/' . $photo->path . $large . '">' . "\n" . '<meta name="twitter:card" content="summary_large_image">', $plain, 'no card: the main photo');
        self::assertStringNotContainsString('og:image:width', $plain);

        $renderer = $this->app->container()->service(Renderer::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/articles/trip')->withAttribute(RequestMemo::class, new RequestMemo());
        $url = null;
        $queries = QueryCounter::measure($this->app->container()->service(Connection::class), static function () use ($renderer, $request, $shared, &$url): void {
            $url = $renderer->callHelper('ogImage', [$shared, 'large'], $request);
        });
        self::assertSame('https://localhost/uploads/share/' . $shared->shareImage, $url);
        self::assertSame(0, $queries, 'the generated image costs no query');
    }

    public function testArchivesAndSinglePagesAreWebsitesInTheirLanguage(): void
    {
        $this->entry('contact', 'Contact', ['fields' => ['phone' => '1']]);

        self::assertStringContainsString(implode("\n", [
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Articles">',
            '<meta property="og:url" content="https://localhost/ru/articles">',
            '<meta property="og:site_name" content="Fixture">',
            '<meta property="og:locale" content="ru">',
            '<meta name="twitter:card" content="summary">',
        ]), (string) $this->browser->get('/ru/articles')->getBody());
        self::assertStringContainsString(implode("\n", [
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Contact">',
            '<meta property="og:url" content="https://localhost/contact">',
        ]), (string) $this->browser->get('/contact')->getBody());
    }

    public function testPagesWithoutACanonicalUrlHaveNoSocialTags(): void
    {
        $this->entry('articles', 'Hello', ['slug' => 'hello']);
        $this->entry('articles', 'Draft', ['slug' => 'draft', 'status' => EntryStatus::Draft]);

        foreach (['/contact', '/search?q=hello', '/articles/missing'] as $path) {
            $body = (string) $this->browser->get($path)->getBody();
            self::assertStringNotContainsString('og:', $body, $path);
            self::assertStringNotContainsString('twitter:', $body, $path);
        }

        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        foreach (['/articles/hello?preview=1', '/articles/draft?preview=1'] as $path) {
            $preview = $this->browser->get($path);
            self::assertSame(200, $preview->getStatusCode(), $path);
            self::assertStringNotContainsString('og:', (string) $preview->getBody(), $path);
            self::assertStringNotContainsString('twitter:', (string) $preview->getBody(), $path);
        }
    }

    public function testThePageTitleFallsBackToTheSiteName(): void
    {
        $renderer = $this->app->container()->service(Renderer::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/x')
            ->withAttribute(RequestLocale::class, new RequestLocale('ru', true))
            ->withAttribute(CanonicalUrl::class, new CanonicalUrl('https://localhost/ru/x?a=1&b=2'));

        self::assertSame(implode("\n", [
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Fixture">',
            '<meta property="og:url" content="https://localhost/ru/x?a=1&amp;b=2">',
            '<meta property="og:site_name" content="Fixture">',
            '<meta property="og:locale" content="ru">',
            '<meta name="twitter:card" content="summary">',
        ]), $renderer->callHelper('socialMeta', [null, ' '], $request));
    }
}
