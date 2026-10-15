<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Media;

use AtarCms\Kernel\Container;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;

/**
 * Videos in the media library (Plan 31): uploaded as they are, the Video type filter, and the picker's video kind.
 */
final class MediaVideoTest extends ContentFunctionalTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function configureContainer(Container $container): void
    {
        parent::configureContainer($container);
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
        $container->set(MediaStorage::class, $this->storage);
    }

    public function testAnMp4IsUploadedAsItIsAndListedAsAFileCard(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $this->browser->upload('/admin/media', ['_csrf' => $this->csrfToken()], ['files' => [TestImages::uploaded(TestImages::mp4(), 'Waves.mp4', 'video/mp4')]]);

        $media = $this->app->container()->service(MediaRepository::class)->page(new \AtarCms\Media\MediaQuery())->items;
        self::assertCount(1, $media);
        self::assertSame(['video/mp4', 'Waves.mp4'], [$media[0]->mime, $media[0]->originalName]);
        self::assertSame(TestImages::mp4(), file_get_contents($this->storage->original($media[0]->path)));

        $body = (string) $this->browser->get('/admin/media')->getBody();
        self::assertStringContainsString('<span class="admin-media-thumb admin-media-file">MP4</span>', $body);
        self::assertStringContainsString('Allowed: JPEG, PNG, WebP, GIF, PDF, MP4, WebM; up to 10 MB each, videos up to 100 MB.', $body);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,video/webm"', $body);
    }

    public function testTheTypeFilterHasVideos(): void
    {
        $video = $this->insertMedia('waves.mp4', 'video/mp4');
        $this->insertMedia('report.pdf', 'application/pdf');
        $this->insertMedia('photo.jpg', 'image/jpeg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $library = (string) $this->browser->get('/admin/media')->getBody();
        self::assertMatchesRegularExpression('~name="type" value="video"~', $library);
        self::assertStringContainsString('Videos', $library);

        $videos = (string) $this->browser->get('/admin/media?type=video')->getBody();
        self::assertStringContainsString('waves.mp4', $videos);
        self::assertStringNotContainsString('report.pdf', $videos);
        self::assertStringNotContainsString('photo.jpg', $videos);

        $documents = (string) $this->browser->get('/admin/media?type=document')->getBody();
        self::assertStringContainsString('report.pdf', $documents);
        self::assertStringNotContainsString('waves.mp4', $documents);
        self::assertGreaterThan(0, $video);
    }

    public function testThePickerOfAVideoListsVideosOnly(): void
    {
        $this->insertMedia('waves.mp4', 'video/mp4');
        $this->insertMedia('report.pdf', 'application/pdf');
        $this->insertMedia('photo.jpg', 'image/jpeg');
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        // A crafted type cannot widen the video kind.
        $picker = (string) $this->browser->get('/admin/media/picker?kind=video&type=document')->getBody();

        self::assertStringContainsString('waves.mp4', $picker);
        self::assertStringNotContainsString('report.pdf', $picker, 'a document is never offered as a video');
        self::assertStringNotContainsString('photo.jpg', $picker);
        self::assertStringContainsString('aria-label="Type: Videos. Only videos can be chosen here"', $picker, 'the type is fixed');
        self::assertStringNotContainsString('name="type"', $picker);
        self::assertStringContainsString('<input type="hidden" name="kind" value="video">', $picker);
        self::assertStringContainsString('accept="video/mp4,video/webm"', $picker);
        self::assertStringContainsString('Allowed: MP4, WebM; up to 100 MB each.', $picker);
        self::assertStringContainsString('kind=video', $picker, 'uploads and links keep the kind');
    }

    public function testAnUploadIntoTheVideoPickerSelectsOnlyVideos(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        $body = (string) $this->browser->upload('/admin/media?context=picker&kind=video', ['_csrf' => $this->csrfToken()], ['files' => [
            TestImages::uploaded(TestImages::mp4(), 'clip.mp4', 'video/mp4'),
            TestImages::uploaded(TestImages::png(), 'still.png', 'image/png'),
        ]], ['HX-Request' => 'true'])->getBody();

        self::assertMatchesRegularExpression('~data-name="clip\.mp4"[^>]* data-uploaded>~', $body);
        self::assertStringNotContainsString('still.png', $body, 'the picture is stored but not offered as a video');
    }

    private function insertMedia(string $name, string $mime): int
    {
        $now = $this->clock->now();
        $extension = ['video/mp4' => '.mp4', 'application/pdf' => '.pdf'][$mime] ?? '.jpg';
        $image = str_starts_with($mime, 'image/');

        return $this->app->container()->service(MediaRepository::class)->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . $extension,
            'original_name' => $name,
            'mime' => $mime,
            'size' => 100,
            'width' => $image ? 64 : null,
            'height' => $image ? 48 : null,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
