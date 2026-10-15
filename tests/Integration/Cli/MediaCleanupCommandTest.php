<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Cli;

use AtarCms\Cli\Command\MediaCleanupCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Media\Cleanup\CleanupReport;
use AtarCms\Media\Cleanup\MediaCleanup;
use AtarCms\Media\Cleanup\StoredFile;
use AtarCms\Media\Cleanup\StoredFileKind;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\TempDirectory;
use RuntimeException;

final class MediaCleanupCommandTest extends ContentTestCase
{
    use TempDirectory;

    private string $original;

    private string $variant;

    private string $staged;

    protected function setUp(): void
    {
        parent::setUp();
        $hex = str_repeat('b', 32);
        $this->original = $this->old('uploads/2026/09/' . $hex . '.jpg');
        $this->variant = $this->old('cache/media/thumb/2026/09/' . $hex . '.jpg.webp');
        $this->staged = $this->old('tmp/' . str_repeat('d', 32));
    }

    public function testADryRunListsTheFilesAndDeletesNothing(): void
    {
        $hex = str_repeat('b', 32);

        self::assertSame([0, implode("\n", [
            'cache/media/thumb/2026/09/' . $hex . '.jpg.webp',
            'tmp/uploads/' . str_repeat('d', 32),
            'uploads/2026/09/' . $hex . '.jpg',
            'Dry run: would delete 1 original, 1 variant, 0 share images, 1 staged upload, 0 temporary files. Nothing was deleted.',
        ]) . "\n", ''], $this->sweep(['--dry-run']));
        self::assertFileExists($this->original);
        self::assertFileExists($this->variant);
        self::assertFileExists($this->staged);
    }

    public function testARunDeletesAndListsTheFiles(): void
    {
        $hex = str_repeat('b', 32);

        self::assertSame([0, implode("\n", [
            'cache/media/thumb/2026/09/' . $hex . '.jpg.webp',
            'tmp/uploads/' . str_repeat('d', 32),
            'uploads/2026/09/' . $hex . '.jpg',
            'Deleted 1 original, 1 variant, 0 share images, 1 staged upload, 0 temporary files.',
        ]) . "\n", ''], $this->sweep([]));
        self::assertFileDoesNotExist($this->original);
        self::assertFileDoesNotExist($this->variant);
        self::assertFileDoesNotExist($this->staged);

        self::assertSame([0, "Deleted 0 originals, 0 variants, 0 share images, 0 staged uploads, 0 temporary files.\n", ''], $this->sweep([]));
    }

    public function testFailuresGoToStandardErrorAndExitWithOne(): void
    {
        $deleted = new StoredFile(StoredFileKind::Share, '/x/a', 'uploads/share/3-0123456789abcdef.jpg', '3-0123456789abcdef.jpg', 0);
        $stuck = new StoredFile(StoredFileKind::Temporary, '/x/b', 'uploads/share/.3-0123456789abcdef.jpg.0123456789ab.tmp', null, 0);

        [$stdout, $stderr] = self::streams();
        $code = MediaCleanupCommand::print(new CleanupReport([$deleted, $deleted], [$stuck], false), new Output($stdout, $stderr));

        self::assertSame([1, implode("\n", [
            'uploads/share/3-0123456789abcdef.jpg',
            'uploads/share/3-0123456789abcdef.jpg',
            'Deleted 0 originals, 0 variants, 2 share images, 0 staged uploads, 0 temporary files.',
        ]) . "\n", "Could not delete uploads/share/.3-0123456789abcdef.jpg.0123456789ab.tmp\n"], [$code, ...self::contents($stdout, $stderr)]);
    }

    public function testRunsThroughTheConsoleWithTheSitesServices(): void
    {
        putenv('APP_DEBUG=0');
        $app = Application::fromSite(__DIR__ . '/../../Fixtures/site');
        $root = $this->tempDir();
        $app->container()->set(MediaStorage::class, new MediaStorage($root . '/uploads', $root . '/cache/media', $root . '/tmp'));
        [$stdout, $stderr] = self::streams();

        $code = $app->console()->run(['media:cleanup', '--dry-run'], new Output($stdout, $stderr));

        [$output, $error] = self::contents($stdout, $stderr);
        self::assertSame(0, $code, $error);
        self::assertStringEndsWith("Dry run: would delete 1 original, 1 variant, 0 share images, 1 staged upload, 0 temporary files. Nothing was deleted.\n", $output);
        self::assertFileExists($this->original);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private function sweep(array $argv): array
    {
        [$stdout, $stderr] = self::streams();
        $storage = $this->mediaStorage();
        $command = new MediaCleanupCommand(new MediaCleanup($storage, $this->mediaRepository(), $this->entries, $this->clock));
        $code = $command->run(Input::parse($argv), new Output($stdout, $stderr));

        return [$code, ...self::contents($stdout, $stderr)];
    }

    private function old(string $relative): string
    {
        $file = $this->writeFile($relative, 'x');
        // Older than a day both for the test's frozen clock and for the real one.
        touch($file, min($this->clock->now()->getTimestamp(), time()) - MediaCleanup::MIN_AGE - 60);

        return $file;
    }

    /**
     * @return array{resource, resource}
     */
    private static function streams(): array
    {
        return [
            fopen('php://memory', 'w+') ?: throw new RuntimeException('stream'),
            fopen('php://memory', 'w+') ?: throw new RuntimeException('stream'),
        ];
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{string, string}
     */
    private static function contents($stdout, $stderr): array
    {
        rewind($stdout);
        rewind($stderr);

        return [(string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
