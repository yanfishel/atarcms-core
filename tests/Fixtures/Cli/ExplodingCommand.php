<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use LogicException;

/**
 * Stands for a command with expensive dependencies (a database connection): constructing it is an error.
 */
#[AsCommand('explode', 'Must never be constructed unless it runs')]
final class ExplodingCommand implements Command
{
    public function __construct()
    {
        throw new LogicException('ExplodingCommand was constructed');
    }

    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
