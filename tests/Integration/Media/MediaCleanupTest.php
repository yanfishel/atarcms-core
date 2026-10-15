<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Media;

use AtarCms\Media\Cleanup\MediaCleanup;
use AtarCms\Media\Cleanup\StoredFile;
use AtarCms\Media\Cleanup\StoredFileKind;
use AtarCms\Media\MediaStorage;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\TempDirectory;

final class MediaCleanupTest extends ContentTestCase
{
    use TempDirectory;

    private MediaStorage $storage;

    private int $old;

    private int $young;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->mediaStorage();
        $now = $this->clock->now()->getTimestamp();
        $this->old = $now - MediaCleanup::MIN_AGE - 1;
        $this->young = $now - MediaCleanup::MIN_AGE + 60;
    }

    public function testDeletesOldFilesNothingOwnsAndKeepsTheRest(): void
    {
        $live = '2026/09/' . str_repeat('a', 32) . '.jpg';
        $gone = '2026/09/' . str_repeat('b', 32) . '.jpg';
        $missing = '2026/09/' . str_repeat('c', 32) . '.png';
        $this->insertMedia(['path' => $live]);
        $this->insertMedia(['path' => $missing, 'mime' => 'image/png']);
        $entry = $this->insertEntry();
        $card = $entry . '-0123456789abcdef.jpg';
        $this->entries->setShareImage($entry, $card, null);

        $kept = [
            $this->old('uploads/' . $live),
            $this->old('cache/media/thumb/' . $live),
            $this->old('cache/media/thumb/' . $live . '.webp'),
            $this->old('cache/media/thumb/' . $missing), // the original's row exists, its file does not
            $this->old('uploads/share/' . $card),
            $this->old('uploads/2026/09/notes.txt'), // not a media name
            $this->old('uploads/.gitkeep'),
        ];
        $deleted = [
            $this->old('uploads/' . $gone),
            $this->old('cache/media/thumb/' . $gone),
            $this->old('cache/media/icon_32/' . $gone . '.png'),
            $this->old('uploads/share/' . ($entry + 1) . '-0123456789abcdef.jpg'),
            $this->old('uploads/share/' . $entry . '-fedcba9876543210.jpg'), // a card the entry no longer names
            $this->old('tmp/' . str_repeat('d', 32)),
            $this->old('uploads/2026/09/.' . str_repeat('e', 32) . '.jpg.0123456789ab.tmp'),
        ];

        $report = $this->cleanup()->sweep(false);

        self::assertFalse($report->dryRun);
        self::assertSame([], $report->failed);
        self::assertSame([
            'cache/media/icon_32/' . $gone . '.png',
            'cache/media/thumb/' . $gone,
            'tmp/uploads/' . str_repeat('d', 32),
            'uploads/2026/09/.' . str_repeat('e', 32) . '.jpg.0123456789ab.tmp',
            'uploads/' . $gone,
            'uploads/share/' . $entry . '-fedcba9876543210.jpg',
            'uploads/share/' . ($entry + 1) . '-0123456789abcdef.jpg',
        ], array_map(static fn(StoredFile $file): string => $file->display, $report->files));
        self::assertSame(
            ['original' => 1, 'variant' => 2, 'share image' => 2, 'staged upload' => 1, 'temporary file' => 1],
            array_combine(array_map(static fn(StoredFileKind $kind): string => $kind->value, StoredFileKind::cases()), array_map($report->count(...), StoredFileKind::cases())),
        );
        foreach ($kept as $file) {
            self::assertFileExists($file);
        }
        foreach ($deleted as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertSame([], $this->cleanup()->sweep(false)->files, 'a second run finds nothing');
    }

    public function testFilesYoungerThanADayStay(): void
    {
        $gone = '2026/09/' . str_repeat('b', 32) . '.jpg';
        $files = [
            $this->young('uploads/' . $gone),
            $this->young('cache/media/thumb/' . $gone),
            $this->young('uploads/share/7-0123456789abcdef.jpg'),
            $this->young('tmp/' . str_repeat('d', 32)),
            $this->young('cache/media/thumb/2026/09/.' . str_repeat('b', 32) . '.jpg.0123456789ab.tmp'),
        ];

        self::assertSame([], $this->cleanup()->sweep(false)->files);
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
    }

    public function testADryRunListsTheSameFilesAndDeletesNothing(): void
    {
        $this->insertMedia(['path' => '2026/09/' . str_repeat('a', 32) . '.jpg']);
        $this->old('uploads/2026/09/' . str_repeat('a', 32) . '.jpg');
        $files = [
            $this->old('uploads/2026/09/' . str_repeat('b', 32) . '.pdf'),
            $this->old('cache/media/thumb/2026/09/' . str_repeat('b', 32) . '.gif.webp'),
            $this->old('tmp/' . str_repeat('d', 32)),
        ];

        $dry = $this->cleanup()->sweep(true);

        self::assertTrue($dry->dryRun);
        foreach ($files as $file) {
            self::assertFileExists($file);
        }
        self::assertEquals($dry->files, $this->cleanup()->sweep(false)->files);
        foreach ($files as $file) {
            self::assertFileDoesNotExist($file);
        }
    }

    public function testManyFilesAreLookedUpInBatches(): void
    {
        $paths = [];
        for ($i = 0; $i < MediaCleanup::BATCH + 5; $i++) {
            $paths[] = '2026/09/' . sprintf('%032x', $i) . '.jpg';
        }
        foreach ($paths as $i => $path) {
            $this->old('uploads/' . $path);
            if ($i % 2 === 0) {
                $this->insertMedia(['path' => $path]);
            }
        }

        $report = $this->cleanup()->sweep(true);

        self::assertSame(intdiv(MediaCleanup::BATCH + 5, 2), $report->count(StoredFileKind::Original));
    }

    public function testAFileThatCannotBeDeletedIsReportedAndTheSweepGoesOn(): void
    {
        $stuck = $this->old('tmp/' . str_repeat('d', 32));
        $other = $this->old('tmp/' . str_repeat('e', 32));
        [$first, $second] = $this->storage->inventory();
        unlink($stuck);
        mkdir($stuck);

        $report = $this->cleanup()->delete([$first, $second]);

        self::assertSame([$first], $report->failed);
        self::assertSame([$second], $report->files);
        self::assertFileDoesNotExist($other);
        self::assertSame(1, $report->count(StoredFileKind::Staged), 'the summary counts deleted files only');
    }

    public function testAFileRewrittenAfterTheListingIsNeitherDeletedNorAFailure(): void
    {
        $card = $this->old('uploads/share/5-0123456789abcdef.jpg');
        $files = $this->storage->inventory();
        touch($card, $this->young);

        $report = $this->cleanup()->delete($files);

        self::assertSame([[], []], [$report->files, $report->failed]);
        self::assertFileExists($card);
    }

    private function cleanup(): MediaCleanup
    {
        return new MediaCleanup($this->storage, $this->mediaRepository(), $this->entries, $this->clock);
    }

    private function old(string $relative): string
    {
        $file = $this->writeFile($relative, 'x');
        touch($file, $this->old);

        return $file;
    }

    private function young(string $relative): string
    {
        $file = $this->writeFile($relative, 'x');
        touch($file, $this->young);

        return $file;
    }
}
