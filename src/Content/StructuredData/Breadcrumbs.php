<?php

declare(strict_types=1);

namespace AtarCms\Content\StructuredData;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermTree;
use AtarCms\Content\TermUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;

/**
 * The PageTrail of an entry or term page, from what its controller has already loaded: no query for sectioned entries
 * and the sections, none for nested pages (their ancestors come with the page), one for a nested term of a
 * hierarchical taxonomy that is not routed as a tree.
 */
final class Breadcrumbs
{
    public function __construct(
        private readonly EntryUrls $entryUrls,
        private readonly TermUrls $termUrls,
        private readonly TermRepository $terms,
        private readonly Taxonomies $taxonomies,
        private readonly Translator $translator,
        private readonly Clock $clock,
    ) {}

    /**
     * A sectioned entry sits below its sections; else an entry of a collection with an archive below the archive; a
     * nested page below its public parents (a draft parent's page is not found, so it is left out).
     *
     * @param string $url the entry's canonical URL
     * @param list<Term> $sections the route term's trail, root first (TermTree::trail()); empty without a route
     *                             taxonomy
     * @param array<int, Term> $knownTerms the route term and its ancestors by id (TermUrls::ancestors())
     * @param array<int, Entry> $ancestors the entry's ancestors by id (EntryRepository::ancestors())
     */
    public function forEntry(Entry $entry, string $url, array $sections, array $knownTerms, array $ancestors): PageTrail
    {
        $collection = $entry->collection;
        $items = [];
        if ($collection->routeTaxonomy !== null) {
            foreach ($sections as $section) {
                $sectionUrl = $this->termUrls->absolute($section, 1, $knownTerms);
                if ($sectionUrl !== null) {
                    $items[] = ['name' => $section->name, 'url' => $sectionUrl];
                }
            }
        } else {
            $archive = $this->entryUrls->archive($collection, $entry->locale);
            if ($archive !== null) {
                $items[] = ['name' => $this->translator->label($entry->locale, $collection->label), 'url' => $archive];
            }
        }
        $now = $this->clock->now();
        foreach ($this->parents($entry, $ancestors) as $parent) {
            $parentUrl = $parent->isPublicAt($now) ? $this->entryUrls->absolute($parent, $ancestors) : null;
            if ($parentUrl !== null) {
                $items[] = ['name' => $parent->title, 'url' => $parentUrl];
            }
        }
        $items[] = ['name' => $entry->title, 'url' => $url];

        return new PageTrail($items);
    }

    /**
     * A term sits below its parents (in a hierarchical taxonomy).
     *
     * @param string $url the term page's canonical URL
     * @param array<int, Term> $tree the terms of its taxonomy and locale by id when the controller has read them,
     *                               else []
     */
    public function forTerm(Term $term, string $url, array $tree): PageTrail
    {
        if ($term->parentId !== null && $tree === [] && $this->taxonomies->find($term->taxonomy)?->hierarchical === true) {
            $tree = $this->terms->ancestors([$term->id]);
        }
        $items = [];
        foreach (array_slice(TermTree::trail(array_values($tree), $term), 0, -1) as $parent) {
            $parentUrl = $this->termUrls->absolute($parent, 1, $tree);
            if ($parentUrl !== null) {
                $items[] = ['name' => $parent->name, 'url' => $parentUrl];
            }
        }
        $items[] = ['name' => $term->name, 'url' => $url];

        return new PageTrail($items);
    }

    /**
     * The entry's parents, root first; a missing one ends the chain, a cycle stops it.
     *
     * @param array<int, Entry> $ancestors
     *
     * @return list<Entry>
     */
    private function parents(Entry $entry, array $ancestors): array
    {
        $parents = [];
        $seen = [$entry->id => true];
        for ($parentId = $entry->collection->hierarchical ? $entry->parentId : null; $parentId !== null && isset($ancestors[$parentId]) && !isset($seen[$parentId]); $parentId = $ancestors[$parentId]->parentId) {
            $seen[$parentId] = true;
            array_unshift($parents, $ancestors[$parentId]);
        }

        return $parents;
    }
}
