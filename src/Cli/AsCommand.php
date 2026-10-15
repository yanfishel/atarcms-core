<?php

declare(strict_types=1);

namespace AtarCms\Cli;

use Attribute;

/**
 * The name and description of a console command, read without constructing it: #[AsCommand('migrate', '…')].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsCommand
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
