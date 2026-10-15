<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Database\Migrator;

/**
 * Applies the pending migrations, then creates the preset pages that are missing (PresetPages: a new site, a new
 * language or a new preset), one line for each. A preset whose address something else uses in a language is reported
 * on stderr and left for the site's owner to resolve; it does not fail the command (a deploy goes on).
 */
#[AsCommand('migrate', 'Apply pending database migrations and create missing preset pages')]
final class MigrateCommand implements Command
{
    /**
     * @param PresetPages|null $presets null only where there is no content to keep (a bare migrator in tests)
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ?PresetPages $presets = null,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $applied = $this->migrator->migrate();
        if ($applied === []) {
            $output->line('Nothing to migrate.');
        }
        foreach ($applied as $id) {
            $output->line('Migrated: ' . $id);
        }
        foreach ($this->presets?->sync() ?? [] as $change) {
            if ($change->action === 'refused') {
                $output->error($change->line());
            } else {
                $output->line($change->line());
            }
        }

        return 0;
    }
}
