<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Content\Field\TagsType;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use InvalidArgumentException;
use PDOException;

/**
 * The only write path for terms: validation, slugs, the tree, translations and the audit log.
 * Error values are translation keys; ICU parameters travel in ValidationFailed::$params.
 */
final class TermService
{
    public const int MAX_NAME = 200;

    /** The sections' title for search engines: as long as an entry's (EntryService). */
    public const int MAX_META_TITLE = 255;

    /** The sections' description for search engines: as long as an entry's (EntryService). */
    public const int MAX_META_DESCRIPTION = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly TermRepository $terms,
        private readonly Taxonomies $taxonomies,
        private readonly Locales $locales,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ReservedSlugs $reservedSlugs,
        private readonly Collections $collections,
        private readonly SlugHistoryRepository $slugHistory,
    ) {}

    /**
     * @throws ValidationFailed
     */
    public function create(Taxonomy $taxonomy, string $locale, TermInput $input, ?User $actor, ?string $ip): Term
    {
        $errors = new ValidationErrors();
        if (!$this->locales->isSupported($locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        }
        $values = $this->values($taxonomy, $locale, $input, null, $errors);
        $errors->throwIfAny();

        $now = $this->clock->now();
        $row = [
            ...$values,
            'taxonomy' => $taxonomy->name,
            'locale' => $locale,
            'translation_group' => EntryRepository::newTranslationGroup(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $this->db->transaction(function () use ($row, $actor, $ip): Term {
            $row['position'] ??= $this->terms->nextPosition($row['taxonomy'], $row['locale'], $row['parent_id']);
            $term = $this->mustFind($this->insert($row));
            $this->audit->record('term.created', $actor?->id, 'term', $term->id, self::auditMeta($term), $ip);

            return $term;
        });
    }

    /**
     * @throws ValidationFailed
     */
    public function update(Term $term, TermInput $input, ?User $actor, ?string $ip): Term
    {
        $errors = new ValidationErrors();
        $values = $this->values($this->taxonomies->get($term->taxonomy), $term->locale, $input, $term, $errors);
        $errors->throwIfAny();

        return $this->db->transaction(function () use ($term, $values, $actor, $ip): Term {
            $values['position'] ??= $values['parent_id'] === $term->parentId
                ? $term->position
                : $this->terms->nextPosition($term->taxonomy, $term->locale, $values['parent_id']);
            if ($values['slug'] !== $term->slug) {
                // The term's page (and, in the root tree, its entries' addresses) had the old slug: it answers 301.
                $this->slugHistory->recordTerm($term->taxonomy, $term->locale, $term->slug, $term->id, $this->clock->now());
            }
            try {
                $this->terms->update($term->id, [...$values, 'updated_at' => $this->clock->now()]);
            } catch (PDOException $e) {
                throw self::duplicateError($e) ?? $e;
            }
            $updated = $this->mustFind($term->id);
            $this->audit->record('term.updated', $actor?->id, 'term', $updated->id, self::auditMeta($updated), $ip);

            return $updated;
        });
    }

    /**
     * Deletes one locale of a term; entries lose it (their entry_terms rows cascade), other locales stay.
     *
     * @throws ValidationFailed while the term has subterms, or while entries carry it whose URL starts with its slug
     *                          (a collection routed "/{taxonomy}/{slug}": they would lose their address)
     */
    public function delete(Term $term, ?User $actor, ?string $ip): void
    {
        if ($this->terms->hasChildren($term->id)) {
            throw new ValidationFailed(['term' => 'validation.term.has_children']);
        }
        if ($this->addressesEntries($term)) {
            throw new ValidationFailed(['term' => 'validation.term.has_entries']);
        }

        try {
            $this->db->transaction(function () use ($term, $actor, $ip): void {
                $entries = $this->terms->entryCounts([$term->id])[$term->id] ?? 0;
                $this->terms->delete($term->id);
                $this->audit->record('term.deleted', $actor?->id, 'term', $term->id, [...self::auditMeta($term), 'slug' => $term->slug, 'entries' => $entries], $ip);
            });
        } catch (PDOException $e) {
            // A subterm created meanwhile: the parent foreign key (ON DELETE RESTRICT) refuses the delete.
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw new ValidationFailed(['term' => 'validation.term.has_children']);
            }

            throw $e;
        }
    }

    /**
     * Why delete() would refuse each of $terms, for a screen that disables Delete up front (the Sections screen): a
     * term with a child among $terms (all terms of one taxonomy and locale) has validation.term.has_children, else one
     * that entries take their address from has validation.term.has_entries. One query at most (the entry counts, only
     * for a taxonomy a collection is routed by); delete() still checks on its own.
     *
     * @param list<Term> $terms
     *
     * @return array<int, string> term id => validation key, in the order of $terms; deletable terms are omitted
     */
    public function deleteBlockers(array $terms): array
    {
        $parents = [];
        foreach ($terms as $term) {
            if ($term->parentId !== null) {
                $parents[$term->parentId] = true;
            }
        }
        $counts = $terms !== [] && $this->routesEntries($terms[0]->taxonomy)
            ? $this->terms->entryCounts(array_map(static fn(Term $term): int => $term->id, $terms))
            : [];
        $blockers = [];
        foreach ($terms as $term) {
            if (isset($parents[$term->id])) {
                $blockers[$term->id] = 'validation.term.has_children';
            } elseif (($counts[$term->id] ?? 0) > 0) {
                $blockers[$term->id] = 'validation.term.has_entries';
            }
        }

        return $blockers;
    }

    /**
     * Moves the terms of a hierarchical taxonomy in one locale to the submitted tree, atomically: the rows are
     * locked, $version must still match the stored order (validation.tree.stale), and $tree must hold exactly the
     * stored terms (validation.tree.invalid) at most TreeOrder::MAX_DEPTH levels deep, and a term that becomes top-level
     * must not use a reserved slug (validation.tree.reserved: a page of the root takes it). Moved terms get a new
     * updated_at, so term forms opened before the move turn stale.
     *
     * @param list<TreeNode> $tree
     *
     * @return int the number of terms that moved
     *
     * @throws ValidationFailed
     */
    public function reorder(Taxonomy $taxonomy, string $locale, array $tree, string $version, ?User $actor, ?string $ip): int
    {
        if (!$taxonomy->hierarchical) {
            throw new InvalidArgumentException(sprintf('Taxonomy "%s" is flat; only hierarchical taxonomies have an order', $taxonomy->name));
        }
        if (!$this->locales->isSupported($locale)) {
            throw new ValidationFailed(['locale' => 'validation.locale.unsupported']);
        }

        return $this->db->transaction(function () use ($taxonomy, $locale, $tree, $version, $actor, $ip): int {
            $terms = $this->terms->forTaxonomy($taxonomy->name, $locale, true);
            if (!hash_equals(TreeOrder::version($terms), $version)) {
                throw new ValidationFailed(['tree' => 'validation.tree.stale']);
            }
            $placements = TreeOrder::placements($tree, array_map(static fn(Term $term): int => $term->id, $terms), TreeOrder::MAX_DEPTH);
            $moved = TreeOrder::changed($terms, $placements);
            foreach ($moved as $term) {
                $toTopLevel = $placements[$term->id]['parentId'] === null && $term->parentId !== null;
                if ($toTopLevel && $this->reservedSlugs->isReserved($taxonomy, $term->slug, $locale)) {
                    throw new ValidationFailed(['tree' => 'validation.tree.reserved'], ['tree' => ['title' => $term->name]]);
                }
            }
            $now = $this->clock->now();
            foreach ($moved as $term) {
                $this->terms->update($term->id, [
                    'parent_id' => $placements[$term->id]['parentId'],
                    'position' => $placements[$term->id]['position'],
                    'updated_at' => $now,
                ]);
            }
            if ($moved !== []) {
                $this->audit->record('term.reordered', $actor?->id, 'taxonomy', $taxonomy->name, [
                    'taxonomy' => $taxonomy->name,
                    'locale' => $locale,
                    'moved' => count($moved),
                ], $ip);
            }

            return count($moved);
        });
    }

    /**
     * Copies $source into $locale in the same translation group; its parent becomes the parent's translation in
     * $locale, when there is one. The texts for search engines are not copied: they belong to the source's language.
     *
     * @throws ValidationFailed
     */
    public function createTranslation(Term $source, string $locale, ?User $actor, ?string $ip): Term
    {
        $errors = new ValidationErrors();
        if ($locale === $source->locale || !$this->locales->isSupported($locale)) {
            $errors->add('locale', 'validation.locale.unsupported');
        } elseif (array_filter($this->terms->translations($source->translationGroup), static fn(Term $t): bool => $t->locale === $locale) !== []) {
            $errors->add('locale', 'validation.locale.exists');
        }
        if (!$this->taxonomies->get($source->taxonomy)->hierarchical && str_contains($source->name, ',')) {
            $errors->add('name', 'validation.term.comma');
        }
        $errors->throwIfAny();

        $parentId = $source->parentId === null ? null : ($this->terms->translationIds([$source->parentId], $locale)[$source->parentId] ?? null);
        $now = $this->clock->now();
        $row = [
            'taxonomy' => $source->taxonomy,
            'locale' => $locale,
            'translation_group' => $source->translationGroup,
            'slug' => $this->freeSlug($this->taxonomies->get($source->taxonomy), $locale, $source->slug, $parentId === null),
            'name' => $source->name,
            'parent_id' => $parentId,
            'position' => $source->position,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $this->db->transaction(function () use ($row, $source, $actor, $ip): Term {
            $term = $this->mustFind($this->insert($row));
            $this->audit->record('term.translated', $actor?->id, 'term', $term->id, [...self::auditMeta($term), 'source' => $source->id], $ip);

            return $term;
        });
    }

    /**
     * The ids of the tags named $names in $taxonomy and $locale, creating the missing ones. Runs inside the
     * caller's transaction (EntryService); $names must already be clean (TagsType::names(), at most MAX_NAME
     * characters). Names are matched case-insensitively; two names that match one term give one id.
     *
     * @param list<string> $names
     *
     * @return list<int> in the order of $names
     */
    public function tagIds(Taxonomy $taxonomy, string $locale, array $names, ?User $actor, ?string $ip): array
    {
        $ids = [];
        foreach ($names as $name) {
            $existing = $this->terms->findByName($taxonomy->name, $locale, $name);
            if ($existing !== null) {
                $ids[$existing->id] = $existing->id;

                continue;
            }
            $now = $this->clock->now();
            $id = $this->terms->insert([
                'taxonomy' => $taxonomy->name,
                'locale' => $locale,
                'translation_group' => EntryRepository::newTranslationGroup(),
                'slug' => $this->freeSlug($taxonomy, $locale, Slug::fromText($name) ?: 'tag'),
                'name' => $name,
                'parent_id' => null,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->record('term.created', $actor?->id, 'term', $id, ['taxonomy' => $taxonomy->name, 'locale' => $locale, 'name' => $name, 'via' => 'tags'], $ip);
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /**
     * Maps a unique-key violation on terms (a concurrent save) to the error the user can act on; null otherwise.
     */
    public static function duplicateError(PDOException $e): ?ValidationFailed
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return null;
        }
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'terms_taxonomy_locale_slug_unique') => new ValidationFailed(['slug' => 'validation.term.slug_taken']),
            str_contains($message, 'terms_group_locale_unique') => new ValidationFailed(['locale' => 'validation.locale.exists']),
            default => null,
        };
    }

    /**
     * Only the sections (a root tree) keep a title and a description for search engines; for any other taxonomy what
     * the input carries (a crafted post) is dropped.
     *
     * @return array{name: string, slug: string, parent_id: int|null, position: int|null, meta_title: string|null, meta_description: string|null} position null: not given
     */
    private function values(Taxonomy $taxonomy, string $locale, TermInput $input, ?Term $existing, ValidationErrors $errors): array
    {
        $name = TagsType::cleanName($input->name);
        if ($name === '') {
            $errors->add('name', 'validation.field.required');
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors->add('name', 'validation.field.too_long', ['max' => self::MAX_NAME]);
        } elseif (!$taxonomy->hierarchical && str_contains($name, ',')) {
            // A tags field (the only way a flat taxonomy's terms are picked) splits comma-separated
            // names (TagsType::names()); a comma in a stored name would silently split into two tags
            // on the next save that re-renders and re-parses it (EntryForm::values()/parse()).
            $errors->add('name', 'validation.term.comma');
        }
        if ($input->position !== null && ($input->position < EntryService::MIN_POSITION || $input->position > EntryService::MAX_POSITION)) {
            $errors->add('position', 'validation.field.invalid');
        }

        return [
            'name' => $name,
            'slug' => $this->slug($taxonomy, $locale, $input, $name, $existing, $errors),
            'parent_id' => $this->parent($taxonomy, $locale, $input->parentId, $existing, $errors),
            'position' => $input->position,
            'meta_title' => $taxonomy->isRootTree() ? self::line($input->metaTitle, self::MAX_META_TITLE, 'meta_title', $errors) : null,
            'meta_description' => $taxonomy->isRootTree() ? self::line($input->metaDescription, self::MAX_META_DESCRIPTION, 'meta_description', $errors) : null,
        ];
    }

    /**
     * Single-line text as an entry's SEO texts (EntryService): control characters and whitespace collapse to single
     * spaces, '' becomes null, longer than $max is an error.
     */
    private static function line(string $value, int $max, string $field, ValidationErrors $errors): ?string
    {
        $value = preg_replace(['/[\x00-\x1F\x7F]+/', '/\s+/u'], ' ', $value);
        if ($value === null) {
            $errors->add($field, 'validation.field.invalid');

            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors->add($field, 'validation.field.too_long', ['max' => $max]);
        }

        return $value;
    }

    /**
     * A term's slug: only a top-level term (no parent in $input) competes with the pages at the root.
     */
    private function slug(Taxonomy $taxonomy, string $locale, TermInput $input, string $name, ?Term $existing, ValidationErrors $errors): string
    {
        $slug = trim($input->slug);
        $topLevel = $input->parentId === null;
        if ($slug === '') {
            if ($existing !== null && $topLevel && $existing->parentId !== null && $this->reservedSlugs->isReserved($taxonomy, $existing->slug, $locale)) {
                // A blank slug keeps the stored one, which a page may already hold at the root.
                $errors->add('slug', 'validation.slug.reserved');
            }

            return $existing !== null ? $existing->slug : $this->freeSlug($taxonomy, $locale, Slug::fromText($name) ?: 'term', $topLevel);
        }
        if (!Slug::isValid($slug)) {
            $errors->add('slug', 'validation.slug.invalid');
        } elseif ($this->reservedSlugs->isReserved($taxonomy, $slug, $locale, $topLevel)) {
            $errors->add('slug', 'validation.slug.reserved');
        } elseif ($this->terms->slugExists($taxonomy->name, $locale, $slug, $existing?->id)) {
            $errors->add('slug', 'validation.term.slug_taken');
        }

        return $slug;
    }

    private function freeSlug(Taxonomy $taxonomy, string $locale, string $base, bool $topLevel = true): string
    {
        $slug = $base;
        for (
            $n = 2;
            $this->terms->slugExists($taxonomy->name, $locale, $slug) || $this->reservedSlugs->isReserved($taxonomy, $slug, $locale, $topLevel);
            $n++
        ) {
            $slug = Slug::withSuffix($base, $n);
        }

        return $slug;
    }

    private function parent(Taxonomy $taxonomy, string $locale, ?int $parentId, ?Term $existing, ValidationErrors $errors): ?int
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $taxonomy->hierarchical ? $this->terms->find($parentId) : null;
        if (
            $parent === null
            || $parent->taxonomy !== $taxonomy->name
            || $parent->locale !== $locale
            || ($existing !== null && $this->isInChain($parent, $existing->id))
        ) {
            $errors->add('parent_id', 'validation.term.parent');

            return null;
        }

        return $parentId;
    }

    /**
     * Whether $termId is $start or one of its ancestors; an existing cycle also counts as true.
     */
    private function isInChain(Term $start, int $termId): bool
    {
        $seen = [];
        for ($current = $start; $current !== null; $current = $current->parentId === null ? null : $this->terms->find($current->parentId)) {
            if ($current->id === $termId || isset($seen[$current->id])) {
                return true;
            }
            $seen[$current->id] = true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws ValidationFailed on a unique-key violation
     */
    private function insert(array $row): int
    {
        try {
            return $this->terms->insert($row);
        } catch (PDOException $e) {
            throw self::duplicateError($e) ?? $e;
        }
    }

    private function mustFind(int $id): Term
    {
        return $this->terms->find($id) ?? throw new NotFound(sprintf('Term #%d no longer exists', $id));
    }

    /**
     * Whether $term's taxonomy is the route placeholder of a collection ("/{sections}/{slug}") and entries carry it.
     */
    private function addressesEntries(Term $term): bool
    {
        return $this->routesEntries($term->taxonomy) && ($this->terms->entryCounts([$term->id])[$term->id] ?? 0) > 0;
    }

    /**
     * Whether a collection takes its entries' addresses from $taxonomy's terms ("/{taxonomy}/{slug}").
     */
    private function routesEntries(string $taxonomy): bool
    {
        foreach ($this->collections->all() as $collection) {
            if ($collection->routeTaxonomy === $taxonomy) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function auditMeta(Term $term): array
    {
        return ['taxonomy' => $term->taxonomy, 'locale' => $term->locale, 'name' => $term->name];
    }
}
