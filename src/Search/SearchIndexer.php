<?php

declare(strict_types=1);

namespace AtarCms\Search;

use AtarCms\Content\Collections;
use AtarCms\Content\Entry;

/**
 * Keeps an entry's search_index row current. EntryService calls index() inside the transaction of every save, so
 * the row always matches the saved entry; every status is indexed (the publishing rule is applied when searching).
 */
final class SearchIndexer
{
    public function __construct(
        private readonly Collections $collections,
        private readonly TextNormalizer $normalizer,
        private readonly SearchRepository $index,
    ) {}

    /**
     * Writes the entry's title and text (EntryText) as stems of its locale; an entry of a collection outside search
     * (no route, or ->searchable(false)) gets no row, and a row it had from before is deleted.
     */
    public function index(Entry $entry): void
    {
        if (!isset($this->collections->searchable()[$entry->collection->name])) {
            $this->index->delete($entry->id);

            return;
        }
        $this->index->replace(
            $entry->id,
            $entry->collection->name,
            $entry->locale,
            $this->normalizer->normalize($entry->title, $entry->locale),
            $this->normalizer->normalize(implode(' ', EntryText::of($entry)), $entry->locale),
        );
    }
}
