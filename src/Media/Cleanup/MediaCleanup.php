<?php

declare(strict_types=1);

namespace AtarCms\Media\Cleanup;

use AtarCms\Content\EntryRepository;
use AtarCms\Kernel\Clock;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;

/**
 * bin/atar media:cleanup: deletes media files nothing refers to. Originals without a media row (a failed best-effort
 * delete, a process killed between writing the upload and inserting its row), variants of such originals, share
 * images no entry records (entries.share_image), staged uploads and the temporary files of atomic writes (both left
 * only by a killed process). Every writer puts the file in place before the row that owns it, so files modified less
 * than MIN_AGE ago are never touched: that is the only guard against a write in progress (no locks). The files are
 * listed first and the database is read afterwards.
 */
final class MediaCleanup
{
    /** Files modified less than a day ago stay. */
    public const int MIN_AGE = 86400;

    /** Media paths looked up per query. */
    public const int BATCH = 1000;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaRepository $media,
        private readonly EntryRepository $entries,
        private readonly Clock $clock,
    ) {}

    /**
     * @param bool $dryRun only report what would be deleted
     */
    public function sweep(bool $dryRun): CleanupReport
    {
        $candidates = $this->candidates();

        return $dryRun ? new CleanupReport($candidates, [], true) : $this->delete($candidates);
    }

    /**
     * The files nothing owns, older than MIN_AGE, sorted by StoredFile::$display.
     *
     * @return list<StoredFile>
     */
    private function candidates(): array
    {
        $before = $this->clock->now()->getTimestamp() - self::MIN_AGE;
        $old = array_values(array_filter($this->storage->inventory(), static fn(StoredFile $file): bool => $file->modified < $before));
        $paths = [];
        foreach ($old as $file) {
            if (($file->kind === StoredFileKind::Original || $file->kind === StoredFileKind::Variant) && $file->key !== null) {
                $paths[$file->key] = true;
            }
        }
        $owned = [];
        foreach (array_chunk(array_keys($paths), self::BATCH) as $batch) {
            $owned += $this->media->idsByPaths($batch);
        }
        $shares = in_array(StoredFileKind::Share, array_map(static fn(StoredFile $file): StoredFileKind => $file->kind, $old), true)
            ? array_flip($this->entries->shareImageNames())
            : [];

        return array_values(array_filter($old, static fn(StoredFile $file): bool => match ($file->kind) {
            StoredFileKind::Original, StoredFileKind::Variant => !isset($owned[(string) $file->key]),
            StoredFileKind::Share => !isset($shares[(string) $file->key]),
            StoredFileKind::Staged, StoredFileKind::Temporary => true,
        }));
    }

    /**
     * Deletes the files, each on its own: one that cannot be deleted is reported and does not stop the others; one
     * written again since it was listed is kept and left out of the report (MediaStorage::removeStored()).
     *
     * @param list<StoredFile> $files
     */
    public function delete(array $files): CleanupReport
    {
        $deleted = [];
        $failed = [];
        foreach ($files as $file) {
            match ($this->storage->removeStored($file)) {
                Removal::Removed => $deleted[] = $file,
                Removal::Failed => $failed[] = $file,
                Removal::Kept => null,
            };
        }

        return new CleanupReport($deleted, $failed, false);
    }
}
