<?php

declare(strict_types=1);

namespace AtarCms\Cli;

/**
 * A console command. Its name and description come from the #[AsCommand] attribute on the class, so listing the
 * commands never constructs them (and never connects to the database).
 */
interface Command
{
    public function run(Input $input, Output $output): int;
}
