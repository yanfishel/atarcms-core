<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Media;

use AtarCms\Kernel\Container;
use AtarCms\Media\ImageFormats;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use Psr\Http\Message\ResponseInterface;

final class MediaFilesTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
        $container->set(MediaStorage::class, $this->storage);
    }

    public function testServesOriginalsWithSafeHeaders(): void
    {
        $photo = $this->upload(TestImages::jpeg(1200, 800), 'photo.jpg');

        $response = $this->browser->get('/uploads/' . $photo->path);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) file_get_contents($this->storage->original($photo->path)), (string) $response->getBody());
        self::assertSame((string) $photo->size, $response->getHeaderLine('Content-Length'));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('', $response->getHeaderLine('Content-Disposition'));
    }

    public function testServesPdfFilesAsDownloads(): void
    {
        $pdf = $this->upload(TestImages::pdf(), 'report.pdf', 'application/pdf');

        $response = $this->browser->get('/uploads/' . $pdf->path);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(TestImages::pdf(), (string) $response->getBody());
    }

    public function testServesVideosInlineWithByteRanges(): void
    {
        $bytes = TestImages::mp4(100);
        $video = $this->upload($bytes, 'waves.mp4', 'video/mp4');
        $url = '/uploads/' . $video->path;

        $full = $this->browser->get($url);
        self::assertSame(200, $full->getStatusCode());
        self::assertSame('video/mp4', $full->getHeaderLine('Content-Type'));
        self::assertSame('', $full->getHeaderLine('Content-Disposition'), 'a video plays on the page');
        self::assertSame('bytes', $full->getHeaderLine('Accept-Ranges'));
        self::assertSame('100', $full->getHeaderLine('Content-Length'));
        self::assertSame($bytes, (string) $full->getBody());

        // From the start to the end (what Safari asks first).
        $open = $this->browser->get($url, ['Range' => 'bytes=0-']);
        self::assertSame(206, $open->getStatusCode());
        self::assertSame('bytes 0-99/100', $open->getHeaderLine('Content-Range'));
        self::assertSame('100', $open->getHeaderLine('Content-Length'));
        self::assertSame($bytes, (string) $open->getBody());
        self::assertSame("default-src 'none'; sandbox", $open->getHeaderLine('Content-Security-Policy'));
        self::assertSame('public, max-age=31536000, immutable', $open->getHeaderLine('Cache-Control'));

        $first = $this->browser->get($url, ['Range' => 'bytes=0-1']);
        self::assertSame([206, 'bytes 0-1/100', '2', substr($bytes, 0, 2)], [$first->getStatusCode(), $first->getHeaderLine('Content-Range'), $first->getHeaderLine('Content-Length'), (string) $first->getBody()]);

        $middle = $this->browser->get($url, ['Range' => 'bytes=10-19']);
        self::assertSame([206, 'bytes 10-19/100', '10', substr($bytes, 10, 10)], [$middle->getStatusCode(), $middle->getHeaderLine('Content-Range'), $middle->getHeaderLine('Content-Length'), (string) $middle->getBody()]);

        $tail = $this->browser->get($url, ['Range' => 'bytes=-16']);
        self::assertSame([206, 'bytes 84-99/100', '16', substr($bytes, -16)], [$tail->getStatusCode(), $tail->getHeaderLine('Content-Range'), $tail->getHeaderLine('Content-Length'), (string) $tail->getBody()]);

        $longTail = $this->browser->get($url, ['Range' => 'bytes=-500']);
        self::assertSame([206, 'bytes 0-99/100'], [$longTail->getStatusCode(), $longTail->getHeaderLine('Content-Range')], 'a suffix longer than the file is the whole file');

        $clamped = $this->browser->get($url, ['Range' => 'bytes=90-5000']);
        self::assertSame([206, 'bytes 90-99/100', substr($bytes, 90)], [$clamped->getStatusCode(), $clamped->getHeaderLine('Content-Range'), (string) $clamped->getBody()]);

        $last = $this->browser->get($url, ['Range' => 'bytes=99-99']);
        self::assertSame([206, 'bytes 99-99/100', '1'], [$last->getStatusCode(), $last->getHeaderLine('Content-Range'), $last->getHeaderLine('Content-Length')]);

        foreach (['bytes=100-', 'bytes=150-200', 'bytes=-0'] as $range) {
            $past = $this->browser->get($url, ['Range' => $range]);
            self::assertSame(416, $past->getStatusCode(), $range);
            self::assertSame('bytes */100', $past->getHeaderLine('Content-Range'), $range);
            self::assertSame('', (string) $past->getBody(), $range);
        }

        foreach (['bytes=abc', 'items=0-1', 'bytes=5-2', 'bytes=0-1,4-5', 'bytes=', 'bytes=1-2-3'] as $range) {
            $ignored = $this->browser->get($url, ['Range' => $range]);
            self::assertSame(200, $ignored->getStatusCode(), $range);
            self::assertSame($bytes, (string) $ignored->getBody(), $range);
            self::assertSame('', $ignored->getHeaderLine('Content-Range'), $range);
        }
    }

    public function testRangesApplyToEveryOriginal(): void
    {
        $pdf = $this->upload(TestImages::pdf(), 'report.pdf', 'application/pdf');

        $part = $this->browser->get('/uploads/' . $pdf->path, ['Range' => 'bytes=0-4']);

        self::assertSame(206, $part->getStatusCode());
        self::assertSame('%PDF-', (string) $part->getBody());
        self::assertSame('attachment', $part->getHeaderLine('Content-Disposition'), 'a PDF stays a download');
    }

    public function testGeneratesAndCachesVariants(): void
    {
        $photo = $this->upload(TestImages::jpeg(1200, 800), 'photo.jpg');

        $webp = $this->browser->get('/media/medium/' . $photo->path . '.webp?v=whatever');
        self::assertSame(200, $webp->getStatusCode());
        self::assertSame('image/webp', $webp->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $webp->getHeaderLine('Cache-Control'));
        self::assertSame([800, 533], self::imageSize($webp));
        self::assertFileExists($this->storage->variant('medium', $photo->path . '.webp'));

        $jpeg = $this->browser->get('/media/thumb/' . $photo->path);
        self::assertSame('image/jpeg', $jpeg->getHeaderLine('Content-Type'));
        self::assertSame([320, 320], self::imageSize($jpeg));

        $admin = $this->browser->get('/media/admin/' . $photo->path . '.webp');
        self::assertSame([320, 213], self::imageSize($admin));

        unlink($this->storage->original($photo->path));
        self::assertSame((string) $webp->getBody(), (string) $this->browser->get('/media/medium/' . $photo->path . '.webp')->getBody(), 'served from the cache');
    }

    public function testGeneratesAvifVariantsWhenGdWritesAvif(): void
    {
        if (!ImageFormats::gdWritesAvif()) {
            self::markTestSkipped('This PHP has no GD with AVIF (imageavif); the dev image (docker/php/Dockerfile) has it');
        }
        $this->app->container()->set(ImageFormats::class, new ImageFormats(true));
        $photo = $this->upload(TestImages::jpeg(1200, 800), 'photo.jpg');
        $webp = $this->upload(TestImages::webp(400, 300), 'photo.webp', 'image/webp');

        $avif = $this->browser->get('/media/medium/' . $photo->path . '.avif?v=whatever');

        self::assertSame(200, $avif->getStatusCode());
        self::assertSame('image/avif', $avif->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $avif->getHeaderLine('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $avif->getHeaderLine('Content-Security-Policy'));
        self::assertSame([800, 533], self::imageSize($avif));
        self::assertSame('image/avif', (getimagesizefromstring((string) $avif->getBody()) ?: [])['mime'] ?? null);
        self::assertFileExists($this->storage->variant('medium', $photo->path . '.avif'));
        self::assertSame('image/avif', $this->browser->get('/media/thumb/' . $webp->path . '.avif')->getHeaderLine('Content-Type'), 'WebP originals too');
        self::assertSame(404, $this->browser->get('/media/icon_192/' . $photo->path . '.avif')->getStatusCode(), 'the site icon is PNG only');

        $this->app->container()->service(MediaService::class)->delete($photo, null, null);
        self::assertFileDoesNotExist($this->storage->variant('medium', $photo->path . '.avif'));
    }

    public function testAvifVariantsAreNotFoundWhenAvifIsOff(): void
    {
        $this->app->container()->set(ImageFormats::class, new ImageFormats(false));
        $photo = $this->upload(TestImages::jpeg(1200, 800), 'photo.jpg');

        self::assertSame(404, $this->browser->get('/media/medium/' . $photo->path . '.avif')->getStatusCode());
        self::assertFileDoesNotExist($this->storage->variant('medium', $photo->path . '.avif'));
    }

    public function testTheIconVariantsAreSquarePngsOnly(): void
    {
        $jpeg = $this->upload(TestImages::jpeg(600, 600), 'icon.jpg');
        $png = $this->upload(TestImages::png(600, 600), 'icon.png', 'image/png');

        $icon = $this->browser->get('/media/icon_192/' . $jpeg->path . '.png');
        self::assertSame(200, $icon->getStatusCode());
        self::assertSame('image/png', $icon->getHeaderLine('Content-Type'));
        self::assertSame([192, 192], self::imageSize($icon));
        self::assertSame('image/png', $this->browser->get('/media/icon_32/' . $png->path)->getHeaderLine('Content-Type'));

        foreach ([
            '/media/icon_192/' . $jpeg->path,
            '/media/icon_192/' . $jpeg->path . '.webp',
            '/media/icon_192/' . $png->path . '.png',
            '/media/thumb/' . $jpeg->path . '.png',
        ] as $url) {
            self::assertSame(404, $this->browser->get($url)->getStatusCode(), $url);
        }
    }

    public function testAWebpOriginalHasNoSecondWebpVersion(): void
    {
        $photo = $this->upload(TestImages::webp(400, 300), 'photo.webp', 'image/webp');

        self::assertSame('image/webp', $this->browser->get('/media/thumb/' . $photo->path)->getHeaderLine('Content-Type'));
        self::assertSame(404, $this->browser->get('/media/thumb/' . $photo->path . '.webp')->getStatusCode());
    }

    public function testEverythingElseIsNotFoundAndGeneratesNothing(): void
    {
        $photo = $this->upload(TestImages::jpeg(), 'photo.jpg');
        $pdf = $this->upload(TestImages::pdf(), 'report.pdf', 'application/pdf');
        $missing = '2026/09/' . str_repeat('0', 32) . '.jpg';

        foreach ([
            '/media/huge/' . $photo->path,
            '/media/Thumb/' . $photo->path,
            '/media/thumb/' . $pdf->path,
            '/media/thumb/' . $pdf->path . '.webp',
            '/media/thumb/' . $missing,
            '/media/thumb/' . $missing . '.webp',
            '/media/thumb/' . strtoupper($photo->path),
            '/media/thumb/../../' . $photo->path,
            '/media/thumb/2026/09/..%2F..%2F' . basename($photo->path),
            '/ru/media/thumb/' . $photo->path,
            '/uploads/' . $missing,
            '/uploads/' . $photo->path . '.php',
            '/uploads/2026/09/' . str_repeat('a', 32) . '.php',
            '/uploads/../.env',
            '/ru/uploads/' . $photo->path,
        ] as $url) {
            self::assertSame(404, $this->browser->get($url)->getStatusCode(), $url);
        }
        // Only the admin thumbnail written at upload exists: no request generated anything.
        self::assertSame(
            [$this->tempDir() . '/cache/media/admin/' . $photo->path . '.webp'],
            glob($this->tempDir() . '/cache/media/*/*/*/*') ?: [],
        );
    }

    public function testACachedVariantIsNotRegenerated(): void
    {
        $photo = $this->upload(TestImages::jpeg(1200, 800), 'photo.jpg');
        $first = $this->browser->get('/media/thumb/' . $photo->path);
        $file = $this->storage->variant('thumb', $photo->path);
        touch($file, 1_000_000_000);
        clearstatcache();

        $second = $this->browser->get('/media/thumb/' . $photo->path);

        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($file), 'served from the cache, not regenerated');
    }

    private function upload(string $bytes, string $name, string $type = 'image/jpeg'): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded($bytes, $name, $type), null, null);
    }

    /**
     * @return array{int, int}
     */
    private static function imageSize(ResponseInterface $response): array
    {
        $info = getimagesizefromstring((string) $response->getBody());

        return is_array($info) ? [$info[0], $info[1]] : [0, 0];
    }
}
