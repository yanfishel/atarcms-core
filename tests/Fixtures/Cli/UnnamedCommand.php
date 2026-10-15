<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

final class UnnamedCommand implements Command
{
    public function run(Input $input, Output $output): int
    {
        return 0;
    }
}
