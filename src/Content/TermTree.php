<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * Orders the terms of one taxonomy and locale as a tree for lists and pickers.
 */
final class TermTree
{
    /**
     * Depth-first: every term follows its parent, siblings keep the given order (TermRepository::forTaxonomy()
     * sorts by position and name). A term whose parent is not in the list is a root; a cycle is broken at the
     * first term reached.
     *
     * @param list<Term> $terms
     *
     * @return list<array{term: Term, depth: int}>
     */
    public static function flatten(array $terms): array
    {
        $ids = [];
        foreach ($terms as $term) {
            $ids[$term->id] = true;
        }
        $children = [];
        foreach ($terms as $term) {
            $parent = $term->parentId !== null && isset($ids[$term->parentId]) ? $term->parentId : 0;
            $children[$parent][] = $term;
        }

        $flat = [];
        $seen = [];
        self::walk($children, 0, 0, $seen, $flat);
        foreach ($terms as $term) {
            if (!isset($seen[$term->id])) {
                $seen[$term->id] = true;
                $flat[] = ['term' => $term, 'depth' => 0];
                self::walk($children, $term->id, 1, $seen, $flat);
            }
        }

        return $flat;
    }

    /**
     * The leaf reached from $start by always taking the first child (in the order of $terms, which
     * TermRepository::forTaxonomy() sorts by position and name); $start itself when it has no children. A cycle
     * stops the walk at the last term before it repeats.
     *
     * @param list<Term> $terms the terms of $start's taxonomy and locale
     */
    public static function firstLeaf(array $terms, Term $start): Term
    {
        $firstChild = [];
        foreach ($terms as $term) {
            if ($term->parentId !== null) {
                $firstChild[$term->parentId] ??= $term;
            }
        }
        $current = $start;
        $seen = [$start->id => true];
        while (isset($firstChild[$current->id]) && !isset($seen[$firstChild[$current->id]->id])) {
            $current = $firstChild[$current->id];
            $seen[$current->id] = true;
        }

        return $current;
    }

    /**
     * $term and its ancestors, root first (breadcrumbs). An ancestor missing from $terms ends the trail; a cycle stops
     * it before a term repeats.
     *
     * @param list<Term> $terms the terms of $term's taxonomy and locale
     *
     * @return list<Term>
     */
    public static function trail(array $terms, Term $term): array
    {
        $byId = [];
        foreach ($terms as $candidate) {
            $byId[$candidate->id] = $candidate;
        }
        $trail = [$term];
        $seen = [$term->id => true];
        for ($parent = $byId[$term->parentId ?? 0] ?? null; $parent !== null && !isset($seen[$parent->id]); $parent = $byId[$parent->parentId ?? 0] ?? null) {
            $seen[$parent->id] = true;
            array_unshift($trail, $parent);
        }

        return $trail;
    }

    /**
     * @param array<int, list<Term>> $children parent id (0 for roots) => children
     * @param array<int, true> $seen
     * @param list<array{term: Term, depth: int}> $flat
     */
    private static function walk(array $children, int $parent, int $depth, array &$seen, array &$flat): void
    {
        foreach ($children[$parent] ?? [] as $term) {
            if (isset($seen[$term->id])) {
                continue;
            }
            $seen[$term->id] = true;
            $flat[] = ['term' => $term, 'depth' => $depth];
            self::walk($children, $term->id, $depth + 1, $seen, $flat);
        }
    }
}
