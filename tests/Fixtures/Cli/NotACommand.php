<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures\Cli;

use AtarCms\Cli\AsCommand;

#[AsCommand('not-a-command', 'Has the attribute but does not implement Command')]
final class NotACommand {}
