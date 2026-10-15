<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Auth\User;
use AtarCms\Content\Field\TagsType;
use DateTimeImmutable;

/**
 * The terms an entry carries (entry_terms). Choices are keyed by taxonomy name; values are term ids, or tag
 * names for a tags field's taxonomy. EntryService validates them with the rest of the entry and stores them
 * in the entry's transaction.
 */
final class EntryTerms
{
    public function __construct(
        private readonly TermRepository $terms,
        private readonly TermService $termService,
        private readonly Taxonomies $taxonomies,
    ) {}

    /**
     * Checks the taxonomies present in $input; the others keep their terms. A term id must belong to that
     * taxonomy and to the entry's locale; names are only accepted for a tags field. Errors go to the tags field's
     * name, or to "terms.{taxonomy}" for a picker (and for a taxonomy the collection does not use). The taxonomy of
     * the route's placeholder ("/{sections}/{slug}") takes exactly one term: the entry's URL starts with its slug.
     *
     * @param array<string, mixed>|null $input not fully trusted: a taxonomy's value, or one of its items, may be
     *                                          any type (a tampered request body, or a caller ignoring the type)
     * @param bool $lenient drop unknown or foreign ids instead of failing (restoring a revision)
     *
     * @return array<string, list<int|string>>|null the valid choices; null keeps every stored term
     */
    public function validate(Collection $collection, string $locale, ?array $input, bool $lenient, ValidationErrors $errors): ?array
    {
        if ($input === null) {
            return null;
        }
        $allowed = $collection->termTaxonomies();
        $ids = [];
        foreach ($input as $taxonomy => $values) {
            if (!in_array($taxonomy, $allowed, true)) {
                $errors->add('terms.' . $taxonomy, 'validation.terms.invalid');

                continue;
            }
            if (!is_array($values)) {
                // Reported below, against the taxonomy's own field key (a tags field's name, or "terms.<taxonomy>").
                continue;
            }
            foreach ($values as $value) {
                if (is_int($value)) {
                    $ids[] = $value;
                }
            }
        }
        $known = $this->terms->byIds(array_values(array_unique($ids)));

        $valid = [];
        foreach ($allowed as $taxonomy) {
            if (!array_key_exists($taxonomy, $input)) {
                continue;
            }
            $field = $collection->tagsField($taxonomy);
            $key = $field === null ? 'terms.' . $taxonomy : $field->name;
            $values = $input[$taxonomy];
            if (!is_array($values)) {
                $errors->add($key, 'validation.terms.invalid');

                continue;
            }
            $invalid = false;
            if ($field !== null) {
                // A tags field's names go through the same normalizer as the admin form (which posts one
                // comma-separated string): whitespace collapsed and trimmed, blanks dropped, case-insensitive
                // duplicates removed and a name containing a comma split into separate tags. Ids, if any, stay
                // ids; any item that is neither an int nor a string is invalid.
                $intValues = array_values(array_filter($values, is_int(...)));
                $strings = array_values(array_filter($values, is_string(...)));
                $invalid = count($intValues) + count($strings) !== count($values);
                $values = [...$intValues, ...TagsType::names(implode(',', $strings))];
            }
            $chosen = [];
            $tooLong = false;
            foreach ($values as $value) {
                if (is_int($value)) {
                    $term = $known[$value] ?? null;
                    if ($term === null || $term->taxonomy !== $taxonomy || $term->locale !== $locale) {
                        $invalid = true;
                    } else {
                        $chosen['id:' . $value] = $value;
                    }
                } elseif (!is_string($value) || $field === null) {
                    $invalid = true;
                } elseif (mb_strlen($value) > TermService::MAX_NAME) {
                    $tooLong = true;
                } else {
                    $chosen['name:' . mb_strtolower($value)] = $value;
                }
            }
            $list = array_values($chosen);
            if ($tooLong) {
                $errors->add($key, 'validation.field.too_long', ['max' => TermService::MAX_NAME]);
            } elseif ($invalid && !$lenient) {
                $errors->add($key, 'validation.terms.invalid');
            } elseif ($field !== null && $field->max !== null && count($list) > $field->max) {
                $errors->add($key, 'validation.field.too_many', ['max' => $field->max]);
            } elseif ($field !== null && $field->required && $list === []) {
                $errors->add($key, 'validation.field.required');
            } elseif ($taxonomy === $collection->routeTaxonomy && $list === []) {
                $errors->add($key, 'validation.field.required');
            } elseif ($taxonomy === $collection->routeTaxonomy && count($list) > 1) {
                $errors->add($key, 'validation.terms.single');
            } else {
                $valid[$taxonomy] = $list;
            }
        }

        return $valid;
    }

    /**
     * Replaces the entry's terms of every taxonomy in $choices; tag names become terms of the entry's locale
     * (created when missing). Call it inside the entry's transaction.
     *
     * @param array<string, list<int|string>> $choices from validate(), fromSnapshot() or translated()
     */
    public function store(Entry $entry, array $choices, ?User $actor, ?string $ip): void
    {
        foreach ($choices as $taxonomy => $values) {
            $ids = array_values(array_filter($values, is_int(...)));
            $names = array_values(array_filter($values, is_string(...)));
            if ($names !== []) {
                $ids = [...$ids, ...$this->termService->tagIds($this->taxonomies->get($taxonomy), $entry->locale, $names, $actor, $ip)];
            }
            $this->terms->assign($entry->id, $taxonomy, $ids);
        }
    }

    /**
     * The terms $choices (from validate()) stand for, without storing anything (a preview): ids become their terms,
     * a tag name its term of $locale, or a Term with id 0 when no such tag exists yet. A taxonomy missing from
     * $choices (or null $choices) keeps $existing's terms.
     *
     * @param array<string, list<int|string>>|null $choices
     *
     * @return array<string, list<Term>> taxonomy => terms
     */
    public function chosen(Collection $collection, string $locale, ?array $choices, ?Entry $existing, DateTimeImmutable $now): array
    {
        $result = [];
        foreach ($existing === null ? [] : $this->terms->forEntries([$existing->id])[$existing->id] ?? [] as $term) {
            $result[$term->taxonomy][] = $term;
        }
        $ids = [];
        foreach ($choices ?? [] as $values) {
            foreach ($values as $value) {
                if (is_int($value)) {
                    $ids[] = $value;
                }
            }
        }
        $known = $this->terms->byIds(array_values(array_unique($ids)));
        foreach ($choices ?? [] as $taxonomy => $values) {
            $terms = [];
            foreach ($values as $value) {
                if (is_int($value)) {
                    if (isset($known[$value])) {
                        $terms[] = $known[$value];
                    }
                } else {
                    $terms[] = $this->terms->findByName($taxonomy, $locale, $value) ?? new Term(
                        id: 0,
                        taxonomy: $taxonomy,
                        locale: $locale,
                        translationGroup: '',
                        slug: Slug::fromText($value) ?: 'tag',
                        name: $value,
                        parentId: null,
                        position: 0,
                        createdAt: $now,
                        updatedAt: $now,
                    );
                }
            }
            $result[$taxonomy] = $terms;
        }

        return array_intersect_key($result, array_flip($collection->termTaxonomies()));
    }

    /**
     * @return list<int> the entry's term ids, for its revision snapshot
     */
    public function ids(int $entryId): array
    {
        return $this->terms->idsForEntry($entryId);
    }

    /**
     * The choices recorded in a revision: every taxonomy of the collection (so terms added since are removed),
     * with the snapshot's ids that still exist, in their order; null for a revision without terms.
     *
     * @return array<string, list<int|string>>|null
     */
    public function fromSnapshot(Collection $collection, mixed $ids): ?array
    {
        if (!is_array($ids)) {
            return null;
        }
        $ids = array_values(array_filter($ids, is_int(...)));
        $known = $this->terms->byIds($ids);
        $choices = array_fill_keys($collection->termTaxonomies(), []);
        foreach ($ids as $id) {
            $term = $known[$id] ?? null;
            if ($term !== null && isset($choices[$term->taxonomy])) {
                $choices[$term->taxonomy][] = $id;
            }
        }

        return $choices;
    }

    /**
     * The source entry's terms mapped to their translations in $locale (terms without one are dropped).
     *
     * @return array<string, list<int|string>>
     */
    public function translated(Entry $source, string $locale): array
    {
        $choices = array_fill_keys($source->collection->termTaxonomies(), []);
        $current = $this->terms->forEntries([$source->id])[$source->id] ?? [];
        $map = $this->terms->translationIds(array_map(static fn(Term $term): int => $term->id, $current), $locale);
        foreach ($current as $term) {
            if (isset($map[$term->id], $choices[$term->taxonomy])) {
                $choices[$term->taxonomy][] = $map[$term->id];
            }
        }

        return $choices;
    }
}
