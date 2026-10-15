<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\MediaConfig;
use AtarCms\Media\MediaStorage;
use AtarCms\Media\MediaUploader;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;
use AtarCms\Tests\Support\TestImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

final class MediaUploaderTest extends TestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
    }

    public function testStoresAReencodedJpegWithoutItsMetadata(): void
    {
        $stored = $this->uploader()->store(TestImages::uploaded(TestImages::jpeg(120, 80, TestImages::exif()), 'Holiday.JPG', 'image/jpeg'));

        self::assertMatchesRegularExpression('~^2026/09/[0-9a-f]{32}\.jpg$~', $stored->path);
        self::assertSame(['Holiday.JPG', 'image/jpeg', 120, 80], [$stored->originalName, $stored->mime, $stored->width, $stored->height]);
        $bytes = (string) file_get_contents($this->storage->original($stored->path));
        self::assertSame(strlen($bytes), $stored->size);
        self::assertStringNotContainsString('SECRET-GPS', $bytes);
        self::assertSame('image/jpeg', getimagesizefromstring($bytes)['mime'] ?? null);
        self::assertSame([], glob($this->tempDir() . '/tmp/*') ?: [], 'the staged upload is removed');
    }

    public function testAppliesTheExifOrientation(): void
    {
        $stored = $this->uploader()->store(TestImages::uploaded(TestImages::jpeg(300, 100, TestImages::exif(6)), 'portrait.jpg', 'image/jpeg'));

        self::assertSame([100, 300], [$stored->width, $stored->height]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function formats(): iterable
    {
        yield 'png' => [TestImages::png(), 'image/png', 'png'];
        yield 'gif' => [TestImages::gif(), 'image/gif', 'gif'];
        yield 'webp' => [TestImages::webp(), 'image/webp', 'webp'];
    }

    #[DataProvider('formats')]
    public function testKeepsTheFormatOfEachAllowedImageType(string $bytes, string $mime, string $extension): void
    {
        // The client's name and type are ignored: the extension comes from the detected type.
        $stored = $this->uploader()->store(TestImages::uploaded($bytes, 'image.jpg', 'image/jpeg'));

        self::assertSame($mime, $stored->mime);
        self::assertStringEndsWith('.' . $extension, $stored->path);
        self::assertSame([64, 48], [$stored->width, $stored->height]);
        self::assertSame($mime, getimagesize($this->storage->original($stored->path))['mime'] ?? null);
    }

    public function testRejectsADisguisedPhpScript(): void
    {
        $this->assertRejected(
            TestImages::uploaded('<?php system($_GET["c"]); ?>', 'photo.jpg', 'image/jpeg'),
            'validation.media.type',
            ['name' => 'photo.jpg'],
        );
    }

    public function testNeutralizesAnImagePolyglot(): void
    {
        $stored = $this->uploader()->store(TestImages::uploaded(TestImages::gif() . '<?php echo "pwned"; ?>', 'x.gif', 'image/gif'));

        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->storage->original($stored->path)));
    }

    public function testRejectsSvgAndHtml(): void
    {
        $this->assertRejected(
            TestImages::uploaded('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml'),
            'validation.media.type',
            ['name' => 'logo.svg'],
        );
        $this->assertRejected(
            TestImages::uploaded('<html><body><script>alert(1)</script></body></html>', 'report.pdf', 'application/pdf'),
            'validation.media.type',
            ['name' => 'report.pdf'],
        );
    }

    public function testStoresPdfFilesAsTheyAre(): void
    {
        $stored = $this->uploader()->store(TestImages::uploaded(TestImages::pdf(), 'Annual report.pdf', 'application/pdf'));

        self::assertSame(['application/pdf', null, null], [$stored->mime, $stored->width, $stored->height]);
        self::assertStringEndsWith('.pdf', $stored->path);
        self::assertSame(TestImages::pdf(), file_get_contents($this->storage->original($stored->path)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function videos(): iterable
    {
        yield 'mp4' => [TestImages::mp4(), 'video/mp4', 'mp4'];
        yield 'webm' => [TestImages::webm(), 'video/webm', 'webm'];
    }

    #[DataProvider('videos')]
    public function testStoresVideosAsTheyAre(string $bytes, string $mime, string $extension): void
    {
        // finfo decides: the client's name and type say something else.
        $stored = $this->uploader()->store(TestImages::uploaded($bytes, 'clip.mov', 'application/octet-stream'));

        self::assertSame([$mime, null, null, strlen($bytes)], [$stored->mime, $stored->width, $stored->height, $stored->size]);
        self::assertStringEndsWith('.' . $extension, $stored->path);
        self::assertSame($bytes, file_get_contents($this->storage->original($stored->path)));
    }

    public function testVideosHaveTheirOwnSizeLimit(): void
    {
        $config = new MediaConfig(maxSize: 100, maxVideoSize: 2 * 1048576);

        // A video above max_size but within max_video_size is stored; an image is held to max_size.
        $stored = $this->uploader($config)->store(TestImages::uploaded(TestImages::mp4(5000), 'clip.mp4', 'video/mp4'));
        self::assertSame('video/mp4', $stored->mime);
        unlink($this->storage->original($stored->path));
        $this->assertRejected(TestImages::uploaded(TestImages::png(), 'big.png', 'image/png'), 'validation.media.too_large', ['name' => 'big.png', 'max' => 0.0], $config);

        $small = new MediaConfig(maxSize: 100, maxVideoSize: 1000);
        $this->assertRejected(TestImages::uploaded(TestImages::mp4(5000), 'long.mp4', 'video/mp4'), 'validation.media.too_large', ['name' => 'long.mp4', 'max' => 0.0], $small);
        // PHP's own limit: a client that says video sees the video limit.
        $this->assertRejected(TestImages::uploaded('', 'huge.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE), 'validation.media.too_large', ['name' => 'huge.mp4', 'max' => 100.0]);
    }

    public function testRejectsVideoTypesTheSiteDoesNotAllow(): void
    {
        $this->assertRejected(TestImages::uploaded(TestImages::mp4(), 'clip.mp4', 'video/mp4'), 'validation.media.type', ['name' => 'clip.mp4'], new MediaConfig(['image/png', 'application/pdf']));
        $this->assertRejected(TestImages::uploaded(TestImages::matroska(), 'clip.webm', 'video/webm'), 'validation.media.type', ['name' => 'clip.webm']);
    }

    public function testRejectsOversizedFiles(): void
    {
        $config = new MediaConfig(maxSize: 1000);

        $this->assertRejected(TestImages::uploaded(TestImages::jpeg(400, 400), 'big.jpg', 'image/jpeg'), 'validation.media.too_large', ['name' => 'big.jpg', 'max' => 0.0], $config);
        $this->assertRejected(TestImages::uploaded('', 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE), 'validation.media.too_large', ['name' => 'huge.jpg', 'max' => 10.0]);
    }

    public function testRejectsAPixelBombBeforeDecodingIt(): void
    {
        $this->assertRejected(TestImages::uploaded(TestImages::pngBomb(), 'bomb.png', 'image/png'), 'validation.media.too_many_pixels', ['name' => 'bomb.png', 'max' => 25.0]);
        $this->assertRejected(
            TestImages::uploaded(TestImages::png(100, 100), 'small.png', 'image/png'),
            'validation.media.too_many_pixels',
            ['name' => 'small.png', 'max' => 0.0],
            new MediaConfig(maxPixels: 9999),
        );
    }

    public function testRejectsBrokenImages(): void
    {
        $this->assertRejected(TestImages::uploaded("\xFF\xD8\xFF\xE0garbage", 'broken.jpg', 'image/jpeg'), 'validation.media.broken', ['name' => 'broken.jpg']);
    }

    public function testRejectsTypesTheSiteDoesNotAllow(): void
    {
        $this->assertRejected(
            TestImages::uploaded(TestImages::jpeg(), 'photo.jpg', 'image/jpeg'),
            'validation.media.type',
            ['name' => 'photo.jpg'],
            new MediaConfig(['image/png']),
        );
    }

    public function testScalesDownImagesLargerThanTheMaximumDimension(): void
    {
        $stored = $this->uploader(new MediaConfig(maxDimension: 100))->store(TestImages::uploaded(TestImages::jpeg(400, 200), 'wide.jpg', 'image/jpeg'));

        self::assertSame([100, 50], [$stored->width, $stored->height]);
    }

    public function testMapsUploadErrors(): void
    {
        $this->assertRejected(TestImages::uploaded('', '', 'application/octet-stream', UPLOAD_ERR_NO_FILE), 'validation.media.missing', ['name' => 'file']);
        $this->assertRejected(TestImages::uploaded('', 'cut.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL), 'validation.media.failed', ['name' => 'cut.jpg']);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function clientNames(): iterable
    {
        yield 'unix path' => ['../../etc/passwd.jpg', 'passwd.jpg'];
        yield 'windows path' => ['C:\\Users\\ann\\photo.jpg', 'photo.jpg'];
        yield 'control characters' => ["a\x00b\x1Fc.jpg", 'abc.jpg'];
        yield 'invalid UTF-8' => ["caf\xE9.jpg", 'caf?.jpg'];
        yield 'bidi override and zero-width characters' => ["ex\u{202E}ab\u{200B}c\u{FEFF}.jpg", 'exabc.jpg'];
        yield 'dots only' => ['..', 'file'];
        yield 'none' => [null, 'file'];
        yield 'long' => [str_repeat('я', 300) . '.jpg', str_repeat('я', 255)];
    }

    #[DataProvider('clientNames')]
    public function testKeepsOnlyASafeBaseNameOfTheClientFileName(?string $name, string $expected): void
    {
        self::assertSame($expected, MediaUploader::clientName($name));
    }

    public function testWritesTheWebpAdminThumbnailOfImages(): void
    {
        $uploader = $this->uploader(null, new ImageVariants(), new MemoryLogger());
        $jpeg = $uploader->store(TestImages::uploaded(TestImages::jpeg(1200, 800), 'photo.jpg', 'image/jpeg'));
        $webp = $uploader->store(TestImages::uploaded(TestImages::webp(400, 300), 'photo.webp', 'image/webp'));
        $pdf = $uploader->store(TestImages::uploaded(TestImages::pdf(), 'report.pdf', 'application/pdf'));

        foreach ([$this->storage->variant('admin', $jpeg->path . '.webp'), $this->storage->variant('admin', $webp->path)] as $file) {
            self::assertFileExists($file);
            $info = getimagesize($file);
            self::assertIsArray($info);
            self::assertSame('image/webp', $info['mime']);
            self::assertLessThanOrEqual(320, max($info[0], $info[1]));
        }
        self::assertSame([], glob($this->tempDir() . '/cache/media/admin/*/*/' . basename($pdf->path) . '*') ?: []);
    }

    public function testAFailedThumbnailIsLoggedAndTheUploadStillSucceeds(): void
    {
        // The variant cache path is a regular file, so no thumbnail directory can be created.
        file_put_contents($this->tempDir() . '/cache-file', 'x');
        $storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache-file', $this->tempDir() . '/tmp');
        $logger = new MemoryLogger();
        $uploader = new MediaUploader(new MediaConfig(), $storage, new ImageProcessor(), new FrozenClock('2026-09-28 12:00:00'), new ImageVariants(), $logger);

        $stored = $uploader->store(TestImages::uploaded(TestImages::jpeg(200, 100), 'photo.jpg', 'image/jpeg'));

        self::assertFileExists($storage->original($stored->path));
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('Admin thumbnail of {path} failed', (string) $logger->records[0]['message']);
    }

    public function testAWarningWhileWritingTheThumbnailIsLoggedAndTheUploadStillSucceeds(): void
    {
        // The variant cache path is a regular file: the failing mkdir() warns, and the error handler raises it.
        file_put_contents($this->tempDir() . '/cache-file', 'x');
        $storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache-file', $this->tempDir() . '/tmp');
        $logger = new MemoryLogger();
        $uploader = new MediaUploader(new MediaConfig(), $storage, new ImageProcessor(), new FrozenClock('2026-09-28 12:00:00'), new ImageVariants(), $logger);
        set_error_handler(static fn(int $severity, string $message): never => throw new \ErrorException($message, 0, $severity));

        try {
            $stored = $uploader->store(TestImages::uploaded(TestImages::jpeg(200, 100), 'photo.jpg', 'image/jpeg'));
        } finally {
            restore_error_handler();
        }

        self::assertFileExists($storage->original($stored->path));
        self::assertCount(1, $logger->records);
        self::assertSame([], glob($this->tempDir() . '/cache-file/*') ?: []);
        self::assertSame([], glob($this->tempDir() . '/uploads/*/*/.*.tmp') ?: []);
    }

    private function uploader(?MediaConfig $config = null, ?ImageVariants $variants = null, ?MemoryLogger $logger = null): MediaUploader
    {
        return new MediaUploader($config ?? new MediaConfig(), $this->storage, new ImageProcessor(), new FrozenClock('2026-09-28 12:00:00'), $variants, $logger);
    }

    /**
     * @param array<string, int|float|string> $params
     */
    private function assertRejected(UploadedFileInterface $file, string $key, array $params, ?MediaConfig $config = null): void
    {
        try {
            $stored = $this->uploader($config)->store($file);
            self::fail(sprintf('Expected %s, stored %s', $key, $stored->path));
        } catch (ValidationFailed $e) {
            self::assertSame(['file' => $key], $e->errors);
            self::assertSame(['file' => $params], $e->params);
        }
        self::assertSame([], glob($this->tempDir() . '/uploads/*/*/*') ?: [], 'nothing is stored');
        self::assertSame([], glob($this->tempDir() . '/tmp/*') ?: [], 'the staged upload is removed');
    }
}
