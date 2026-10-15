<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Search;

use AtarCms\Cli\Command\SearchReindexCommand;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Content\Entry;
use AtarCms\Search\SearchQuery;
use AtarCms\Search\SearchRepository;
use AtarCms\Search\TextNormalizer;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\Cast;
use RuntimeException;

final class SearchReindexCommandTest extends ContentTestCase
{
    public function testRebuildsTheIndexFromScratch(): void
    {
        $article = $this->insertEntry(['slug' => 'article', 'title' => 'Running club', 'data' => ['summary' => 'Weekly runs']]);
        $draft = $this->insertEntry(['slug' => 'draft', 'title' => 'Draft', 'status' => 'draft']);
        $page = $this->insertEntry(['collection' => 'pages', 'slug' => 'page', 'title' => 'Страница', 'locale' => 'ru']);
        $event = $this->insertEntry(['collection' => 'events', 'slug' => 'event', 'title' => 'Concert']);
        $this->insertEntry(['collection' => 'notes', 'slug' => 'note', 'title' => 'Note']);
        (new SearchRepository($this->db))->replace($event, 'events', 'en', 'stale', '');

        self::assertSame("Indexed 3 entries.\n", $this->reindex());

        self::assertSame(
            [
                [$article, 'articles', 'en', 'run club', 'week run'],
                [$draft, 'articles', 'en', 'draft', ''],
                [$page, 'pages', 'ru', 'страниц', ''],
            ],
            array_map(
                static fn(array $row): array => [Cast::int($row['entry_id']), Cast::string($row['collection']), Cast::string($row['locale']), Cast::string($row['title']), Cast::string($row['body'])],
                $this->db->select('SELECT entry_id, collection, locale, title, body FROM search_index ORDER BY entry_id'),
            ),
        );
        self::assertSame("Indexed 3 entries.\n", $this->reindex());
    }

    public function testEntriesAreReadInBatchesById(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $slug) {
            $ids[] = $this->insertEntry(['slug' => $slug]);
        }
        $this->insertEntry(['collection' => 'notes', 'slug' => 'note']);

        $first = $this->entries->batchAfter(['articles'], 0, 2);
        $rest = $this->entries->batchAfter(['articles'], $first[1]->id, 2);

        self::assertSame([$ids[0], $ids[1]], array_map(static fn(Entry $entry): int => $entry->id, $first));
        self::assertSame([$ids[2]], array_map(static fn(Entry $entry): int => $entry->id, $rest));
        self::assertSame([], $this->entries->batchAfter(['articles'], $ids[2], 2));
    }

    public function testStopwordsStayIndexedAfterAReindex(): void
    {
        $id = $this->insertEntry(['slug' => 'about', 'title' => 'About us']);

        $this->reindex();

        $page = (new SearchRepository($this->db))->search(
            SearchQuery::parse('about', 'en', new TextNormalizer()),
            'en',
            ['articles'],
            $this->clock->now(),
            1,
            10,
        );
        self::assertSame([$id], $page->ids);
    }

    private function reindex(): string
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $command = new SearchReindexCommand($this->collections, $this->entries, new SearchRepository($this->db), $this->searchIndexer());

        self::assertSame(0, $command->run(Input::parse([]), new Output($stdout, $stderr)));
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }
}
