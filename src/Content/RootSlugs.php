<?php

declare(strict_types=1);

namespace AtarCms\Content;

/**
 * The slugs in use at the top level of the site by the owners of the root route "/{slug}": a collection routed there
 * (its top-level entries, such as pages) and a root-routed taxonomy (its top-level terms, such as sections: a
 * subsection's URL starts with its parent's, "/about/team"). They share one route, so one locale must not have a page
 * and a top-level section with the same slug: the page would hide the section. ReservedSlugs asks this for slugs
 * under the root prefix.
 */
final class RootSlugs
{
    public function __construct(
        private readonly Collections $collections,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
    ) {}

    /**
     * Whether another owner of "/{slug}" uses $slug at the top level in $locale. $owner's own slugs are not looked at:
     * its slug checks (EntryRepository::slugExists(), TermRepository::slugExists()) already keep them unique.
     */
    public function taken(Collection|Taxonomy $owner, string $locale, string $slug): bool
    {
        foreach ($this->collections->all() as $collection) {
            $self = $owner instanceof Collection && $owner->name === $collection->name;
            // A preset page's slug is taken in every locale, also where the page is not created yet.
            if (!$self && $collection->route === Taxonomy::ROOT_ROUTE && (isset($collection->presets[$slug]) || $this->entries->topLevelSlugExists($collection->name, $locale, $slug))) {
                return true;
            }
        }
        foreach ($this->collections->taxonomies->all() as $taxonomy) {
            $self = $owner instanceof Taxonomy && $owner->name === $taxonomy->name;
            if (!$self && $taxonomy->isRoot() && $this->terms->topLevelSlugExists($taxonomy->name, $locale, $slug)) {
                return true;
            }
        }

        return false;
    }
}
