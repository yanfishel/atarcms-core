<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Auth\Avatar\AvatarDownloader;
use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Container;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use AtarCms\Tests\Functional\FunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use ReflectionProperty;

/**
 * The profile photo (stored at a Google sign-in) as the top bar's avatar, served by /admin/avatar/{id} to signed-in
 * users only.
 */
final class AdminAvatarTest extends FunctionalTestCase
{
    use TempDirectory;

    protected function configureContainer(Container $container): void
    {
        $container->set(Avatars::class, new Avatars($this->tempDir() . '/avatars', new MediaConfig(), new ImageProcessor()));
    }

    public function testTheAvatarIsServedToSignedInUsersOnly(): void
    {
        $owner = $this->createUser('ann@example.com');
        $version = $this->app->container()->service(Avatars::class)->save($owner->id, TestImages::jpeg(120, 120));

        $guest = $this->browser->get('/admin/avatar/' . $owner->id);
        self::assertSame(302, $guest->getStatusCode());
        self::assertStringStartsWith('/admin/login', $guest->getHeaderLine('Location'));

        $this->actingAs($this->createUser('bob@example.com', 'editor'));
        $response = $this->browser->get('/admin/avatar/' . $owner->id . '?v=' . $version);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->getHeaderLine('Content-Type'));
        self::assertSame('private, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(file_get_contents($this->tempDir() . '/avatars/' . $owner->id . '.webp'), (string) $response->getBody());

        self::assertSame(404, $this->browser->get('/admin/avatar/9999')->getStatusCode(), 'a user without a photo');
        self::assertSame(404, $this->browser->get('/admin/avatar/abc')->getStatusCode());
    }

    public function testTheContainerGivesTheDownloaderTheProductionClient(): void
    {
        $downloader = $this->app->container()->service(AvatarDownloader::class);
        $client = new ReflectionProperty(AvatarDownloader::class, 'client')->getValue($downloader);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame(5, $client->getConfig(RequestOptions::TIMEOUT));
        self::assertSame(3, $client->getConfig(RequestOptions::CONNECT_TIMEOUT));
        self::assertSame(3, $client->getConfig(RequestOptions::READ_TIMEOUT));
        self::assertTrue($client->getConfig(RequestOptions::STREAM));
        self::assertFalse($client->getConfig(RequestOptions::DECODE_CONTENT));
        self::assertFalse($client->getConfig(RequestOptions::ALLOW_REDIRECTS));
    }

    public function testTheTopBarShowsThePhotoElseTheInitials(): void
    {
        $user = $this->createUser('ann@example.com', 'admin', 'Ann Lee');
        $this->actingAs($user);

        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true">AL</span>', $page);
        self::assertStringNotContainsString('admin-avatar-image', $page);

        $version = $this->app->container()->service(Avatars::class)->save($user->id, TestImages::png(100, 100));
        $this->app->container()->service(UserRepository::class)->setAvatarVersion($user->id, $version);

        $page = (string) $this->browser->get('/admin')->getBody();
        self::assertStringContainsString('<span class="admin-avatar admin-role admin-role-admin" aria-hidden="true"><img class="admin-avatar-image" src="/admin/avatar/' . $user->id . '?v=' . $version . '" alt="" width="36" height="36"></span>', $page);
        self::assertStringNotContainsString('style=', $page);
    }
}
