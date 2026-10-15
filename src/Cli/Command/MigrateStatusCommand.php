<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Database\Migrator;

#[AsCommand('migrate:status', 'List migrations and whether they are applied')]
final class MigrateStatusCommand implements Command
{
    public function __construct(private readonly Migrator $migrator) {}

    public function run(Input $input, Output $output): int
    {
        $status = $this->migrator->status();
        if ($status === []) {
            $output->line('No migrations found.');

            return 0;
        }
        foreach ($status as $migration) {
            $output->line(sprintf('[%s] %s', $migration['applied'] ? 'x' : ' ', $migration['id']));
        }

        return 0;
    }
}
