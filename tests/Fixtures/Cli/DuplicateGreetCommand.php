<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

#[AsCommand('greet', 'A second command with the same name')]
final class DuplicateGreetCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
