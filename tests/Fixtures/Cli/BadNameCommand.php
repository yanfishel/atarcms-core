<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

#[AsCommand('Bad Name', 'An invalid command name')]
final class BadNameCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
