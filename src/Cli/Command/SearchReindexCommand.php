<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryRepository;
use AtarCms\Search\SearchIndexer;
use AtarCms\Search\SearchRepository;

/**
 * Rebuilds search_index: empties it, then indexes every entry of every searchable collection, BATCH entries at a
 * time. Run it after installing on existing content and after changing collections or their fields.
 */
#[AsCommand('search:reindex', 'Rebuild the search index from the entries of the searchable collections')]
final class SearchReindexCommand implements Command
{
    public const int BATCH = 200;

    public function __construct(
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly SearchRepository $index,
        private readonly SearchIndexer $indexer,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $this->index->clear();
        $collections = array_keys($this->collections->searchable());
        $count = 0;
        $after = 0;
        while ($collections !== []) {
            $batch = $this->entries->batchAfter($collections, $after, self::BATCH);
            foreach ($batch as $entry) {
                $this->indexer->index($entry);
                $after = $entry->id;
                $count++;
            }
            if (count($batch) < self::BATCH) {
                break;
            }
        }
        $output->line(sprintf('Indexed %d %s.', $count, $count === 1 ? 'entry' : 'entries'));

        return 0;
    }
}
