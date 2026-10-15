<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\MediaStorage;

#[AsCommand('media:variants:clear', 'Delete cached image variants (they are generated again on request): media:variants:clear [--variant=<name>]')]
final class MediaVariantsClearCommand implements Command
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function run(Input $input, Output $output): int
    {
        $variant = $input->option('variant');
        if ($variant === true) {
            throw new CliException('--variant needs a name, e.g. --variant=thumb');
        }
        if ($variant !== null && preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1) {
            throw new CliException(sprintf('Invalid variant name "%s"', $variant));
        }
        $count = $this->storage->clearVariants($variant);
        $output->line(sprintf('Deleted %d cached variant %s.', $count, $count === 1 ? 'file' : 'files'));

        return 0;
    }
}
