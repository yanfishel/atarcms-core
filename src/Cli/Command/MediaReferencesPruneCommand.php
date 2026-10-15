<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryMedia;
use AtarCms\Media\MediaRepository;

/**
 * Entries of a collection removed from config/collections.php, and fields removed from a collection, keep their media
 * references, which block deleting those files and cannot be edited any more. This releases them: entry_media rows of
 * unconfigured collections or fields. The entries stay.
 */
#[AsCommand('media:references:prune', 'Release media held by removed collections or fields, so the files can be deleted')]
final class MediaReferencesPruneCommand implements Command
{
    public function __construct(
        private readonly Collections $collections,
        private readonly MediaRepository $media,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $fields = [];
        foreach ($this->collections->all() as $collection) {
            $fields[$collection->name] = EntryMedia::fields($collection);
        }
        $references = $this->media->pruneReferences($fields);
        $output->line(sprintf(
            'Removed %d media %s of removed collections or fields.',
            $references,
            $references === 1 ? 'reference' : 'references',
        ));

        return 0;
    }
}
