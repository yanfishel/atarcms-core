<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Database\Connection;
use AtarCms\Kernel\Container;
use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\QueryCounter;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * Media files, robots.txt, the sitemap and the feeds run without the session: no Set-Cookie, no session or user
 * lookup, even for a logged-in visitor. Everything else keeps the full session stack.
 */
final class StatelessRoutesTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $container->set(MediaStorage::class, new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp'));
    }

    public function testMediaFilesRunWithoutTheSession(): void
    {
        $photo = $this->upload();
        $this->actingAs($this->createUser('admin@example.com'));
        $db = $this->app->container()->service(Connection::class);
        $responses = [];

        $queries = QueryCounter::measure($db, function () use ($photo, &$responses): void {
            $responses[] = $this->browser->get('/uploads/' . $photo->path);
            $responses[] = $this->browser->get('/media/admin/' . $photo->path . '.webp');
        });

        self::assertSame(0, $queries, 'no session read and no user lookup');
        foreach ($responses as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertFalse($response->hasHeader('Set-Cookie'));
            self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testGeneratedShareImagesAreServedWithoutTheSession(): void
    {
        $entry = $this->entry('articles', 'Trip', ['slug' => 'trip', 'metaTitle' => 'Рыбалка на Оке', 'fields' => ['cover' => $this->upload()->id]]);
        $name = $entry->shareImage ?? self::fail('no share image');
        $this->actingAs($this->createUser('admin@example.com'));
        $db = $this->app->container()->service(Connection::class);
        $response = null;

        $queries = QueryCounter::measure($db, function () use ($name, &$response): void {
            $response = $this->browser->get('/uploads/share/' . $name);
        });

        self::assertSame(0, $queries);
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(404, $this->browser->get('/uploads/share/' . $entry->id . '-0000000000000000.jpg')->getStatusCode(), 'an unknown picture');
        self::assertSame(404, $this->browser->get('/uploads/share/.' . $name)->getStatusCode(), 'only the strict name pattern');
    }

    public function testAStaleSessionCookieGetsNoExpiringCookieOnMedia(): void
    {
        $photo = $this->upload();
        $this->browser->setCookie('__Host-atar', str_repeat('a', 64));

        $response = $this->browser->get('/uploads/' . $photo->path);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testRobotsSitemapAndFeedsAreStateless(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        foreach (['/robots.txt', '/sitemap.xml', '/articles/feed.xml'] as $path) {
            $response = $this->browser->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertFalse($response->hasHeader('Set-Cookie'), $path);
            self::assertNotSame('no-store, private', $response->getHeaderLine('Cache-Control'), $path);
        }
    }

    public function testOtherRoutesKeepTheSessionStack(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $admin = $this->browser->get('/admin');
        self::assertSame(200, $admin->getStatusCode());
        self::assertStringContainsString('admin@example.com', (string) $admin->getBody());
        self::assertSame('no-store, private', $admin->getHeaderLine('Cache-Control'));
        self::assertSame(419, $this->browser->post('/admin/logout')->getStatusCode(), 'CSRF still applies');
    }

    public function testQueryCounterIgnoresItself(): void
    {
        self::assertSame(0, QueryCounter::measure($this->app->container()->service(Connection::class), static fn(): null => null));
    }

    private function upload(): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded(TestImages::jpeg(640, 480), 'photo.jpg', 'image/jpeg'), null, null);
    }
}
