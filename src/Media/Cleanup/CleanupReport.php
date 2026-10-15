<?php

declare(strict_types=1);

namespace AtarCms\Media\Cleanup;

/**
 * What MediaCleanup deleted (or, in a dry run, would delete) and what it could not delete.
 */
final readonly class CleanupReport
{
    /**
     * @param list<StoredFile> $files deleted, or in a dry run the files a real run would delete
     * @param list<StoredFile> $failed files that could not be deleted (never in a dry run)
     */
    public function __construct(
        public array $files,
        public array $failed,
        public bool $dryRun,
    ) {}

    public function count(StoredFileKind $kind): int
    {
        return count(array_filter($this->files, static fn(StoredFile $file): bool => $file->kind === $kind));
    }
}
