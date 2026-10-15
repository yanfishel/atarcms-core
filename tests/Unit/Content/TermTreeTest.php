<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Term;
use AtarCms\Content\TermTree;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TermTreeTest extends TestCase
{
    public function testOrdersChildrenAfterTheirParentWithDepths(): void
    {
        $flat = TermTree::flatten([
            self::term(1, 'A', null),
            self::term(2, 'B', null),
            self::term(3, 'A1', 1),
            self::term(4, 'A1x', 3),
            self::term(5, 'Orphan', 99),
            self::term(6, 'A2', 1),
        ]);

        self::assertSame(
            [['A', 0], ['A1', 1], ['A1x', 2], ['A2', 1], ['B', 0], ['Orphan', 0]],
            array_map(static fn(array $node): array => [$node['term']->name, $node['depth']], $flat),
        );
    }

    public function testCyclesDoNotLoop(): void
    {
        $flat = TermTree::flatten([self::term(1, 'X', 2), self::term(2, 'Y', 1)]);

        self::assertSame([['X', 0], ['Y', 1]], array_map(static fn(array $node): array => [$node['term']->name, $node['depth']], $flat));
        self::assertSame([], TermTree::flatten([]));
    }

    public function testTheFirstLeafFollowsTheFirstChildDownwards(): void
    {
        // Listed as TermRepository::forTaxonomy() sorts them: by position, then name.
        $terms = [
            self::term(1, 'A', null),
            self::term(2, 'B', null),
            self::term(3, 'A1', 1),
            self::term(4, 'A1x', 3),
            self::term(5, 'A2', 1),
        ];

        self::assertSame('A1x', TermTree::firstLeaf($terms, $terms[0])->name);
        self::assertSame('A1x', TermTree::firstLeaf($terms, $terms[2])->name);
        self::assertSame('B', TermTree::firstLeaf($terms, $terms[1])->name, 'a leaf is its own first leaf');
        self::assertSame('A2', TermTree::firstLeaf($terms, $terms[4])->name);
    }

    public function testTheFirstLeafStopsAtACycle(): void
    {
        $terms = [self::term(1, 'X', 2), self::term(2, 'Y', 1)];

        self::assertSame('X', TermTree::firstLeaf($terms, $terms[1])->name);
    }

    public function testTheTrailRunsFromTheRootToTheTerm(): void
    {
        $terms = [self::term(1, 'A', null), self::term(3, 'A1', 1), self::term(4, 'A1x', 3), self::term(5, 'Orphan', 99)];
        $names = static fn(array $trail): array => array_map(static fn(Term $term): string => $term->name, self::terms($trail));

        self::assertSame(['A', 'A1', 'A1x'], $names(TermTree::trail($terms, $terms[2])));
        self::assertSame(['A'], $names(TermTree::trail($terms, $terms[0])));
        self::assertSame(['Orphan'], $names(TermTree::trail($terms, $terms[3])), 'a missing parent ends the trail');
        self::assertSame(['Y', 'X'], $names(TermTree::trail([self::term(1, 'X', 2), self::term(2, 'Y', 1)], self::term(1, 'X', 2))), 'a cycle stops');
    }

    /**
     * @param array<mixed> $terms
     *
     * @return array<Term>
     */
    private static function terms(array $terms): array
    {
        self::assertContainsOnlyInstancesOf(Term::class, $terms);

        return $terms;
    }

    private static function term(int $id, string $name, ?int $parentId): Term
    {
        $now = new DateTimeImmutable('2026-09-28 12:00:00');

        return new Term($id, 'topics', 'en', str_repeat('a', 32), strtolower($name), $name, $parentId, 0, $now, $now);
    }
}
