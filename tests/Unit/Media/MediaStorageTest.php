<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Kernel\Paths;
use AtarCms\Media\Cleanup\Removal;
use AtarCms\Media\Cleanup\StoredFile;
use AtarCms\Media\Cleanup\StoredFileKind;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Support\TempDirectory;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MediaStorageTest extends TestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MediaStorage($this->tempDir() . '/uploads', $this->tempDir() . '/cache/media', $this->tempDir() . '/tmp');
    }

    public function testNewPathsAreRandomAndDated(): void
    {
        $now = new DateTimeImmutable('2026-01-31 23:30:00', new DateTimeZone('Europe/Moscow'));
        $path = $this->storage->newPath($now, 'png');

        self::assertMatchesRegularExpression('~^2026/01/[0-9a-f]{32}\.png$~', $path, 'the month is taken in UTC');
        self::assertNotSame($path, $this->storage->newPath($now, 'png'));
    }

    public function testAFailedWriteLeavesNoTemporaryFile(): void
    {
        $path = '2026/09/' . str_repeat('c', 32) . '.jpg';
        mkdir($this->tempDir() . '/uploads/' . $path, 0775, true);

        // rename() onto a directory warns; PhpErrorHandler turns that warning into an exception in production.
        set_error_handler(static fn(int $severity, string $message): never => throw new \ErrorException($message, 0, $severity));

        try {
            $this->storage->write($path, 'original');
            self::fail('Expected an exception');
        } catch (\ErrorException) {
            self::assertSame([], glob($this->tempDir() . '/uploads/2026/09/.*.tmp') ?: []);
        } finally {
            restore_error_handler();
        }
    }

    public function testWritesAtomicallyAndDeletesVariantsWithTheOriginal(): void
    {
        $path = '2026/09/' . str_repeat('a', 32) . '.jpg';
        $other = '2026/09/' . str_repeat('b', 32) . '.jpg';
        $this->storage->write($path, 'original');
        $this->storage->write($other, 'other');
        $this->storage->writeVariant('thumb', $path, 'thumb');
        $this->storage->writeVariant('thumb', $path . '.webp', 'thumb webp');
        $this->storage->writeVariant('medium', $path . '.webp', 'medium webp');
        $this->storage->writeVariant('medium', $path . '.avif', 'medium avif');
        $this->storage->writeVariant('thumb', $other, 'other thumb');

        self::assertSame('original', file_get_contents($this->tempDir() . '/uploads/' . $path));
        self::assertSame('thumb webp', file_get_contents($this->storage->variant('thumb', $path . '.webp')));
        self::assertSame([], glob($this->tempDir() . '/uploads/2026/09/.*.tmp') ?: [], 'no temporary files are left');

        $this->storage->delete($path);

        self::assertFileDoesNotExist($this->storage->original($path));
        self::assertFileDoesNotExist($this->storage->variant('thumb', $path));
        self::assertFileDoesNotExist($this->storage->variant('thumb', $path . '.webp'));
        self::assertFileDoesNotExist($this->storage->variant('medium', $path . '.webp'));
        self::assertFileDoesNotExist($this->storage->variant('medium', $path . '.avif'));
        self::assertFileExists($this->storage->original($other));
        self::assertFileExists($this->storage->variant('thumb', $other));
    }

    public function testCopiesFilesAndStagesUploadsOutsideTheUploadsDirectory(): void
    {
        $source = $this->writeFile('source.pdf', '%PDF-1.4');
        $path = '2026/09/' . str_repeat('c', 32) . '.pdf';
        $this->storage->copy($source, $path);
        $staging = $this->storage->stagingFile();

        self::assertSame('%PDF-1.4', file_get_contents($this->storage->original($path)));
        self::assertStringStartsWith($this->tempDir() . '/tmp/', $staging);
        self::assertDirectoryExists(dirname($staging));
        self::assertFileDoesNotExist($staging);
    }

    public function testClearsCachedVariants(): void
    {
        $path = '2026/09/' . str_repeat('a', 32) . '.jpg';
        $this->storage->writeVariant('thumb', $path, 'x');
        $this->storage->writeVariant('thumb', $path . '.webp', 'x');
        $this->storage->writeVariant('medium', $path, 'x');

        self::assertSame(2, $this->storage->clearVariants('thumb'));
        self::assertFileExists($this->storage->variant('medium', $path));
        self::assertSame(1, $this->storage->clearVariants());
        self::assertSame(0, $this->storage->clearVariants());
        self::assertSame(0, $this->storage->clearVariants('large'));
    }

    public function testRejectsPathsOfAnyOtherShape(): void
    {
        foreach ([
            fn() => $this->storage->original('../../.env'),
            fn() => $this->storage->write('2026/09/x.php', '<?php'),
            fn() => $this->storage->variant('../thumb', '2026/09/' . str_repeat('a', 32) . '.jpg'),
            fn() => $this->storage->variant('thumb', '2026/09/' . str_repeat('a', 32) . '.pdf'),
            fn() => $this->storage->clearVariants('../uploads'),
        ] as $call) {
            try {
                $call();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testWritingAVariantOfADeletedOriginalLeavesNothing(): void
    {
        $path = '2026/09/' . str_repeat('a', 32) . '.jpg';
        $gone = '2026/09/' . str_repeat('b', 32) . '.jpg';
        $this->storage->write($path, 'original');

        self::assertTrue($this->storage->writeVariantOf($path, 'thumb', $path . '.webp', 'variant'));
        self::assertFalse($this->storage->writeVariantOf($gone, 'thumb', $gone . '.webp', 'variant'));

        self::assertFileExists($this->storage->variant('thumb', $path . '.webp'));
        self::assertFileDoesNotExist($this->storage->variant('thumb', $gone . '.webp'));
        self::assertSame([], glob($this->tempDir() . '/cache/media/thumb/2026/09/.*.tmp') ?: []);
    }

    public function testAVariantWrittenTwiceKeepsOneWholeFile(): void
    {
        // A second write (as from a concurrent first request) replaces the first whole file and leaves no temp file.
        $path = '2026/09/' . str_repeat('c', 32) . '.jpg';
        $this->storage->write($path, 'original');

        self::assertTrue($this->storage->writeVariantOf($path, 'thumb', $path, 'first'));
        self::assertTrue($this->storage->writeVariantOf($path, 'thumb', $path, 'second'));

        self::assertStringEqualsFile($this->storage->variant('thumb', $path), 'second');
        self::assertSame([], glob($this->tempDir() . '/cache/media/thumb/2026/09/.*.tmp') ?: []);
    }

    public function testCopiesLargeFilesAsStreams(): void
    {
        $path = '2026/09/' . str_repeat('d', 32) . '.pdf';
        $source = $this->writeFile('staged', random_bytes(3 * 1024 * 1024));

        $this->storage->copy($source, $path);

        self::assertFileEquals($source, $this->storage->original($path));
        self::assertSame([], glob($this->tempDir() . '/uploads/2026/09/.*.tmp') ?: []);
    }

    public function testTheInventoryClassifiesOnlyStrictlyNamedFiles(): void
    {
        $hex = str_repeat('a', 32);
        $original = $this->writeFile('uploads/2026/09/' . $hex . '.jpg', 'o');
        $video = $this->writeFile('uploads/2026/10/' . str_repeat('b', 32) . '.mp4', 'v');
        $share = $this->writeFile('uploads/share/12-0123456789abcdef.jpg', 's');
        $variant = $this->writeFile('cache/media/thumb/2026/09/' . $hex . '.jpg', 'v');
        $webp = $this->writeFile('cache/media/thumb/2026/09/' . $hex . '.jpg.webp', 'v');
        $png = $this->writeFile('cache/media/icon_32/2026/09/' . $hex . '.jpg.png', 'v');
        $avif = $this->writeFile('cache/media/thumb/2026/09/' . $hex . '.jpg.avif', 'v');
        $staged = $this->writeFile('tmp/' . str_repeat('c', 32), 'u');
        $temporaryOriginal = $this->writeFile('uploads/2026/09/.' . $hex . '.jpg.0123456789ab.tmp', 't');
        $temporaryShare = $this->writeFile('uploads/share/.12-0123456789abcdef.jpg.0123456789ab.tmp', 't');
        $temporaryVariant = $this->writeFile('cache/media/thumb/2026/09/.' . $hex . '.jpg.webp.0123456789ab.tmp', 't');
        $temporaryAvif = $this->writeFile('cache/media/thumb/2026/09/.' . $hex . '.jpg.avif.0123456789ab.tmp', 't');
        touch($original, 1_700_000_000);
        // Never listed:
        $this->writeFile('uploads/.gitkeep', '');
        $this->writeFile('uploads/share/.gitkeep', '');
        $this->writeFile('cache/media/.gitkeep', '');
        $this->writeFile('tmp/.gitkeep', '');
        $this->writeFile('uploads/2026/09/' . strtoupper($hex) . '.jpg', 'x');
        $this->writeFile('uploads/2026/13/' . $hex . '.jpg', 'x');
        $this->writeFile('uploads/2026/09/' . $hex . '.svg', 'x');
        $this->writeFile('uploads/2026/09/notes.txt', 'x');
        $this->writeFile('uploads/' . $hex . '.jpg', 'x');
        $this->writeFile('uploads/share/0-0123456789abcdef.jpg', 'x');
        $this->writeFile('uploads/share/2026/09/' . $hex . '.jpg', 'x');
        $this->writeFile('cache/media/thumb/2026/09/' . $hex . '.pdf', 'x');
        $this->writeFile('cache/media/Thumb/2026/09/' . $hex . '.jpg', 'x');
        $this->writeFile('cache/media/2026/09/' . $hex . '.jpg', 'x');
        $this->writeFile('tmp/' . str_repeat('c', 31), 'x');
        $this->writeFile('tmp/nested/' . str_repeat('d', 32), 'x');
        $this->writeFile('tmp/.' . str_repeat('d', 32) . '.0123456789ab.tmp', 'x');
        $this->writeFile('uploads/2026/09/.other.0123456789ab.tmp', 'x');
        mkdir($this->tempDir() . '/uploads/2026/11/' . str_repeat('e', 32) . '.jpg', 0777, true);
        $links = [$this->tempDir() . '/uploads/2026/09/' . str_repeat('f', 32) . '.jpg', $this->tempDir() . '/uploads/2027'];
        symlink($video, $links[0]);
        symlink($this->tempDir() . '/uploads/2026', $links[1]);

        $found = [];
        try {
            foreach ($this->storage->inventory() as $file) {
                $found[$file->display] = [$file->kind, $file->key, $file->file, $file->modified];
            }
        } finally {
            array_map(unlink(...), $links);
        }

        self::assertSame([
            'cache/media/icon_32/2026/09/' . $hex . '.jpg.png' => [StoredFileKind::Variant, '2026/09/' . $hex . '.jpg', $png, filemtime($png)],
            'cache/media/thumb/2026/09/.' . $hex . '.jpg.avif.0123456789ab.tmp' => [StoredFileKind::Temporary, null, $temporaryAvif, filemtime($temporaryAvif)],
            'cache/media/thumb/2026/09/.' . $hex . '.jpg.webp.0123456789ab.tmp' => [StoredFileKind::Temporary, null, $temporaryVariant, filemtime($temporaryVariant)],
            'cache/media/thumb/2026/09/' . $hex . '.jpg' => [StoredFileKind::Variant, '2026/09/' . $hex . '.jpg', $variant, filemtime($variant)],
            'cache/media/thumb/2026/09/' . $hex . '.jpg.avif' => [StoredFileKind::Variant, '2026/09/' . $hex . '.jpg', $avif, filemtime($avif)],
            'cache/media/thumb/2026/09/' . $hex . '.jpg.webp' => [StoredFileKind::Variant, '2026/09/' . $hex . '.jpg', $webp, filemtime($webp)],
            'tmp/uploads/' . str_repeat('c', 32) => [StoredFileKind::Staged, null, $staged, filemtime($staged)],
            'uploads/2026/09/.' . $hex . '.jpg.0123456789ab.tmp' => [StoredFileKind::Temporary, null, $temporaryOriginal, filemtime($temporaryOriginal)],
            'uploads/2026/09/' . $hex . '.jpg' => [StoredFileKind::Original, '2026/09/' . $hex . '.jpg', $original, 1_700_000_000],
            'uploads/2026/10/' . str_repeat('b', 32) . '.mp4' => [StoredFileKind::Original, '2026/10/' . str_repeat('b', 32) . '.mp4', $video, filemtime($video)],
            'uploads/share/.12-0123456789abcdef.jpg.0123456789ab.tmp' => [StoredFileKind::Temporary, null, $temporaryShare, filemtime($temporaryShare)],
            'uploads/share/12-0123456789abcdef.jpg' => [StoredFileKind::Share, '12-0123456789abcdef.jpg', $share, filemtime($share)],
        ], $found);
    }

    public function testTheInventoryOfMissingDirectoriesIsEmpty(): void
    {
        self::assertSame([], $this->storage->inventory());
    }

    public function testRemovesAStoredFile(): void
    {
        $this->writeFile('tmp/' . str_repeat('c', 32), 'u');
        [$staged] = $this->storage->inventory();

        self::assertSame(Removal::Removed, $this->storage->removeStored($staged));
        self::assertFileDoesNotExist($staged->file);
        self::assertSame(Removal::Removed, $this->storage->removeStored($staged), 'a file that is already gone counts as removed');
    }

    public function testAFileRewrittenAfterTheInventoryIsKept(): void
    {
        // A share card's name is deterministic: an entry reverted to an earlier state writes the same file again.
        $this->writeFile('uploads/share/12-0123456789abcdef.jpg', 'old');
        touch($this->tempDir() . '/uploads/share/12-0123456789abcdef.jpg', 1_700_000_000);
        [$card] = $this->storage->inventory();
        touch($card->file, 1_700_000_100);

        self::assertSame(Removal::Kept, $this->storage->removeStored($card));
        self::assertFileExists($card->file);
    }

    public function testOnlyFilesBelowTheirOwnDirectoryAreRemoved(): void
    {
        $outside = $this->writeFile('elsewhere/' . str_repeat('c', 32), 'keep');
        $inside = $this->writeFile('tmp/' . str_repeat('c', 32), 'u');
        $modified = (int) filemtime($outside);
        $forged = [
            new StoredFile(StoredFileKind::Staged, $outside, 'tmp/uploads/' . str_repeat('c', 32), null, $modified),
            new StoredFile(StoredFileKind::Staged, $this->tempDir() . '/tmp/../elsewhere/' . str_repeat('c', 32), 'tmp/uploads/../../elsewhere/' . str_repeat('c', 32), null, $modified),
            new StoredFile(StoredFileKind::Staged, $outside, 'elsewhere/' . str_repeat('c', 32), null, $modified),
            new StoredFile(StoredFileKind::Original, $inside, 'uploads/../tmp/' . str_repeat('c', 32), null, (int) filemtime($inside)),
        ];

        foreach ($forged as $file) {
            self::assertSame(Removal::Failed, $this->storage->removeStored($file), $file->display);
        }
        self::assertFileExists($outside);
        self::assertFileExists($inside);
    }

    public function testAFileThatCannotBeRemovedIsReportedNotThrown(): void
    {
        // Listed as a file, replaced by a directory before the removal: unlink() fails even for root.
        $this->writeFile('tmp/' . str_repeat('c', 32), 'u');
        [$staged] = $this->storage->inventory();
        unlink($staged->file);
        mkdir($staged->file);

        set_error_handler(static fn(int $severity, string $message): never => throw new \ErrorException($message, 0, $severity));
        try {
            self::assertSame(Removal::Failed, $this->storage->removeStored($staged));
        } finally {
            restore_error_handler();
        }
        self::assertDirectoryExists($staged->file);
    }

    public function testUsesTheSiteStorageDirectories(): void
    {
        $storage = MediaStorage::fromPaths(new Paths('/srv/site'));

        self::assertSame('/srv/site/storage/uploads/2026/09/' . str_repeat('a', 32) . '.jpg', $storage->original('2026/09/' . str_repeat('a', 32) . '.jpg'));
        self::assertSame('/srv/site/storage/cache/media/thumb/2026/09/' . str_repeat('a', 32) . '.jpg.webp', $storage->variant('thumb', '2026/09/' . str_repeat('a', 32) . '.jpg.webp'));
    }
}
