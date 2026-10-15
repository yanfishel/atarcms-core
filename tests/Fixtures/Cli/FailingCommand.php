<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use RuntimeException;

#[AsCommand('fail', 'Always fails')]
final class FailingCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        throw new RuntimeException('nope');
    }
}
