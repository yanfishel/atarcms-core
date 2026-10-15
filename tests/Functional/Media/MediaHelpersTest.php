<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Media;

use AtarCms\Database\Connection;
use AtarCms\Http\RequestMemo;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageFormats;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use AtarCms\View\Renderer;
use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

final class MediaHelpersTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    /** A title only of characters the share font lacks: no generated card, so og:image shows the main photo. */
    private const string NO_CARD = '🎣';

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
        // The markup does not depend on whether this PHP's GD writes AVIF.
        $container->set(ImageFormats::class, new ImageFormats(true));
    }

    public function testRendersImagesGalleriesFilesAndTheSeoImage(): void
    {
        $cover = $this->alt($this->upload(TestImages::jpeg(1200, 800), 'cover.jpg'), ['en' => 'Sea <b>"', 'ru' => 'Море']);
        $second = $this->alt($this->upload(TestImages::png(400, 300), 'second.png', 'image/png'), ['en' => 'Second']);
        $pdf = $this->upload(TestImages::pdf(), 'Price <list>.pdf', 'application/pdf');
        $entry = $this->entry('articles', self::NO_CARD, [
            'slug' => 'trip',
            'fields' => ['cover' => $cover->id, 'images' => [$second->id, $cover->id], 'attachment' => $pdf->id],
        ]);

        $body = (string) $this->browser->get('/articles/trip')->getBody();

        self::assertStringContainsString('<div class="cover"><picture><source type="image/avif" srcset="/media/medium/' . $cover->path . '.avif?v=' . $this->version('medium') . ' 800w', $body);
        self::assertStringContainsString('<source type="image/webp" srcset="/media/medium/' . $cover->path . '.webp?v=' . $this->version('medium') . ' 800w', $body);
        self::assertStringContainsString('width="800" height="533" alt="Sea &lt;b&gt;&quot;" class="cover-image" loading="lazy" decoding="async"></picture></div>', $body);
        $gallery = (int) strpos($body, '<ul class="gallery">');
        self::assertGreaterThan(0, $gallery);
        self::assertLessThan(
            (int) strpos($body, '/media/thumb/' . $cover->path, $gallery),
            (int) strpos($body, '/media/thumb/' . $second->path, $gallery),
            'the gallery keeps the chosen order',
        );
        self::assertStringContainsString('<a class="attachment" href="/uploads/' . $pdf->path . '" download="Price &lt;list&gt;.pdf">Price &lt;list&gt;.pdf</a>', $body);
        self::assertStringContainsString(
            '<meta property="og:image" content="https://localhost/media/large/' . $cover->path . '?v=' . $this->version('large') . '">'
            . "\n" . '<meta name="twitter:card" content="summary_large_image">',
            $body,
        );

        $this->translation($entry, 'ru', 'Поездка', 'poezdka');
        $russian = (string) $this->browser->get('/ru/articles/poezdka')->getBody();
        self::assertStringContainsString('alt="Море" class="cover-image"', $russian);
        self::assertStringContainsString('alt="Second"', $russian, 'falls back to the default locale');
    }

    public function testEntriesWithoutMediaRenderNothing(): void
    {
        $this->entry('articles', 'Plain', ['slug' => 'plain']);

        $body = (string) $this->browser->get('/articles/plain')->getBody();

        self::assertStringNotContainsString('<picture>', $body);
        self::assertStringNotContainsString('og:image', $body);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $body);
        self::assertStringNotContainsString('class="gallery"', $body);
    }

    public function testAnArchivePageLoadsItsMediaInOneQuery(): void
    {
        $first = $this->upload(TestImages::jpeg(), 'first.jpg');
        $second = $this->upload(TestImages::png(), 'second.png', 'image/png');
        $this->entry('articles', 'First', ['slug' => 'first', 'fields' => ['cover' => $first->id]]);
        $one = $this->statements(fn(): ResponseInterface => $this->browser->get('/articles'));

        $this->entry('articles', 'Second', ['slug' => 'second', 'fields' => ['cover' => $second->id, 'images' => [$first->id, $second->id]]]);
        $two = $this->statements(fn(): ResponseInterface => $this->browser->get('/articles'));

        self::assertSame($one, $two, 'the number of SQL statements does not grow with the entries of the page');
        $body = (string) $this->browser->get('/articles')->getBody();
        self::assertStringContainsString('/media/thumb/' . $first->path, $body);
        self::assertStringContainsString('/media/thumb/' . $second->path, $body);
    }

    private function upload(string $bytes, string $name, string $type = 'image/jpeg'): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded($bytes, $name, $type), null, null);
    }

    public function testOgImageLoadsTheMediumOncePerRequest(): void
    {
        $cover = $this->upload(TestImages::jpeg(1200, 800), 'cover.jpg');
        $entry = $this->entry('articles', self::NO_CARD, ['slug' => 'trip', 'fields' => ['cover' => $cover->id]]);
        $renderer = $this->app->container()->service(Renderer::class);
        $db = $this->app->container()->service(Connection::class);
        $request = (new Psr17Factory())->createServerRequest('GET', '/articles/trip')
            ->withAttribute(RequestLocale::class, new RequestLocale('en', false))
            ->withAttribute(RequestMemo::class, new RequestMemo());
        $urls = [];

        $queries = QueryCounter::measure($db, static function () use ($renderer, $request, $entry, &$urls): void {
            $urls[] = $renderer->callHelper('ogImage', [$entry, 'large'], $request);
            $urls[] = $renderer->callHelper('ogImage', [$entry], $request);
        });

        self::assertSame(1, $queries);
        self::assertSame('https://localhost/media/large/' . $cover->path . '?v=' . $this->version('large'), $urls[0]);
        self::assertSame('https://localhost/uploads/' . $cover->path, $urls[1]);
    }

    public function testOgImageWorksWithoutARequest(): void
    {
        $cover = $this->upload(TestImages::jpeg(1200, 800), 'cover.jpg');
        $entry = $this->entry('articles', self::NO_CARD, ['slug' => 'trip', 'fields' => ['cover' => $cover->id]]);
        $renderer = $this->app->container()->service(Renderer::class);

        self::assertSame('https://localhost/uploads/' . $cover->path, $renderer->callHelper('ogImage', [$entry]));
        self::assertSame(
            'https://localhost/media/large/' . $cover->path . '?v=' . $this->version('large'),
            $renderer->callHelper('ogImage', [$entry, 'large']),
        );
    }

    /**
     * @param array<string, string> $alt
     */
    private function alt(Media $media, array $alt): Media
    {
        return $this->app->container()->service(MediaService::class)->updateAlt($media, $alt, MediaService::version($media), null, null);
    }

    private function version(string $variant): string
    {
        return $this->app->container()->service(ImageVariants::class)->get($variant)?->version() ?? self::fail('No variant ' . $variant);
    }

    /**
     * @param Closure(): ResponseInterface $request
     */
    private function statements(Closure $request): int
    {
        $db = $this->app->container()->service(Connection::class);
        $questions = static fn(): int => Cast::int($db->selectOne("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
        $before = $questions();
        self::assertSame(200, $request()->getStatusCode());

        return $questions() - $before;
    }
}
