<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

/**
 * Takes the name of a built-in console command.
 */
#[AsCommand('list', 'Would hide the built-in list')]
final class ListNamedCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
