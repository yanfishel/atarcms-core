<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Media\Cleanup\CleanupReport;
use AtarCms\Media\Cleanup\MediaCleanup;
use AtarCms\Media\Cleanup\StoredFileKind;

/**
 * Deletes media files nothing refers to (see MediaCleanup), printing each one; exits with 1 when a file could not be
 * deleted.
 */
#[AsCommand('media:cleanup', 'Delete media files nothing refers to (left by failed deletes or killed uploads), older than a day: media:cleanup [--dry-run]')]
final class MediaCleanupCommand implements Command
{
    public function __construct(private readonly MediaCleanup $cleanup) {}

    public function run(Input $input, Output $output): int
    {
        return self::print($this->cleanup->sweep($input->option('dry-run') === true), $output);
    }

    /**
     * @return int the exit code: 1 when a file could not be deleted
     */
    public static function print(CleanupReport $report, Output $output): int
    {
        foreach ($report->files as $file) {
            $output->line($file->display);
        }
        foreach ($report->failed as $file) {
            $output->error('Could not delete ' . $file->display);
        }
        $counts = implode(', ', array_map(static fn(StoredFileKind $kind): string => $kind->count($report->count($kind)), StoredFileKind::cases()));
        $output->line($report->dryRun ? sprintf('Dry run: would delete %s. Nothing was deleted.', $counts) : sprintf('Deleted %s.', $counts));

        return $report->failed === [] ? 0 : 1;
    }
}
