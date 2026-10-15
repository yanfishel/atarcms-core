<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

#[AsCommand('greet', 'Say hello: greet [<name>] [--loud]')]
final class GreetCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        $output->line('Hello ' . ($input->argument(0) ?? 'world') . ($input->option('loud') === true ? '!' : ''));

        return 0;
    }
}
