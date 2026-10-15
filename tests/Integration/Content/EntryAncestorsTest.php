<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Content\TreeOrder;
use AtarCms\Tests\Support\QueryCounter;

final class EntryAncestorsTest extends ContentTestCase
{
    public function testLoadsEveryAncestorInOneQuery(): void
    {
        $chain = $this->chain(5);

        $ancestors = [];
        $queries = QueryCounter::measure($this->db, function () use ($chain, &$ancestors): void {
            $ancestors = $this->entries->ancestors([$chain[4]]);
        });

        self::assertSame(1, $queries);
        self::assertSame(array_slice($chain, 0, 4), array_keys($ancestors));
        self::assertSame(['a', 'b', 'c', 'd'], array_map(static fn($entry): string => $entry->slug, array_values($ancestors)));
    }

    public function testSeveralEntriesShareTheirAncestors(): void
    {
        $chain = $this->chain(3);
        $sibling = $this->insertEntry(['collection' => 'pages', 'slug' => 'sibling', 'parent_id' => $chain[1]]);

        self::assertSame([$chain[0], $chain[1]], array_keys($this->entries->ancestors([$chain[2], $sibling])));
        self::assertSame([$chain[0]], array_keys($this->entries->ancestors([$chain[1], $chain[0]])), 'an entry is included when it is another one\'s ancestor');
    }

    public function testTopLevelAndEmptyInput(): void
    {
        $top = $this->insertEntry(['collection' => 'pages', 'slug' => 'top']);

        self::assertSame([], $this->entries->ancestors([$top]));
        self::assertSame(0, QueryCounter::measure($this->db, fn(): array => $this->entries->ancestors([])));
    }

    public function testACycleTerminates(): void
    {
        [$a, $b] = $this->chain(2);
        $this->db->execute('UPDATE entries SET parent_id = ? WHERE id = ?', [$b, $a]);

        self::assertSame([$a, $b], array_keys($this->entries->ancestors([$b])));
    }

    public function testDepthIsBounded(): void
    {
        $chain = $this->chain(TreeOrder::MAX_DEPTH + 3);

        $ancestors = $this->entries->ancestors([$chain[count($chain) - 1]]);

        self::assertCount(TreeOrder::MAX_DEPTH + 1, $ancestors);
    }

    public function testSlugPathCostsOneQueryWhateverTheDepth(): void
    {
        $chain = $this->chain(5);
        $urls = $this->entryUrls();
        $deepest = $this->entries->find($chain[4]);
        self::assertNotNull($deepest);

        $path = '';
        $queries = QueryCounter::measure($this->db, function () use ($urls, $deepest, &$path): void {
            $path = $urls->slugPath($deepest);
        });

        self::assertSame('a/b/c/d/e', $path);
        self::assertSame(1, $queries);
        $known = $this->entries->byIds($chain);
        self::assertSame(0, QueryCounter::measure($this->db, static fn(): string => $urls->slugPath($deepest, $known)));
    }

    public function testSlugPathLoadsWhatAPartialKnownMapLacks(): void
    {
        $chain = $this->chain(4);
        $deepest = $this->entries->find($chain[3]);
        self::assertNotNull($deepest);

        self::assertSame('a/b/c/d', $this->entryUrls()->slugPath($deepest, $this->entries->byIds([$chain[2]])));
    }

    /**
     * Nested "pages" entries a/b/c/…, each the child of the previous one.
     *
     * @return list<int> their ids, root first
     */
    private function chain(int $length): array
    {
        $ids = [];
        $parent = null;
        for ($i = 0; $i < $length; $i++) {
            $slug = $i < 26 ? chr(ord('a') + $i) : 'p' . $i;
            $parent = $this->insertEntry(['collection' => 'pages', 'slug' => $slug, 'title' => strtoupper($slug), 'parent_id' => $parent]);
            $ids[] = $parent;
        }

        return $ids;
    }
}
