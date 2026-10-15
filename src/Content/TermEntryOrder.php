<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\ValidationFailed;

/**
 * The manual order of the entries carrying one term: the articles inside a section (the admin's "Order articles").
 * The entries are those its public page lists (Collections::listedBy()) in the term's language, in any status; the
 * order is entry_terms.entry_position (EntryRepository::TERM_ORDER): 0 for every entry until the first save, which
 * numbers them 1..n, so a new or moved-in entry (0) comes first. Saving writes no revision and leaves the entries
 * untouched: the order belongs to the section.
 */
final class TermEntryOrder
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
        private readonly TermRepository $terms,
        private readonly Collections $collections,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return list<Entry> the term's entries in its order
     */
    public function entries(Term $term, bool $lock = false): array
    {
        $collections = array_map(static fn(Collection $collection): string => $collection->name, $this->collections->listedBy($term->taxonomy));

        return $this->entries->forTerm($collections, $term->id, $term->locale, $lock);
    }

    /**
     * A token for the order as loaded: any change of the set of entries or of their order changes it.
     *
     * @param list<Entry> $entries
     */
    public static function version(array $entries): string
    {
        return sha1(implode(',', array_map(static fn(Entry $entry): int => $entry->id, $entries)));
    }

    /**
     * Puts the term's entries in the order of $entryIds, atomically: the rows are locked, $version must still match
     * the stored order (validation.tree.stale) and $entryIds must hold exactly the term's entries
     * (validation.tree.invalid). One audit row, term.entries_reordered.
     *
     * @param list<int> $entryIds
     *
     * @throws ValidationFailed
     */
    public function reorder(Term $term, array $entryIds, string $version, ?User $actor, ?string $ip): void
    {
        $this->db->transaction(function () use ($term, $entryIds, $version, $actor, $ip): void {
            $entries = $this->entries($term, true);
            if (!hash_equals(self::version($entries), $version)) {
                throw new ValidationFailed(['tree' => 'validation.tree.stale']);
            }
            $stored = array_map(static fn(Entry $entry): int => $entry->id, $entries);
            $submitted = $entryIds;
            sort($stored);
            sort($submitted);
            if ($stored !== $submitted) {
                throw new ValidationFailed(['tree' => 'validation.tree.invalid']);
            }
            $positions = [];
            foreach ($entryIds as $index => $id) {
                $positions[$id] = $index + 1;
            }
            $this->terms->setEntryPositions($term->id, $positions);
            $this->audit->record('term.entries_reordered', $actor?->id, 'term', (string) $term->id, [
                'taxonomy' => $term->taxonomy,
                'locale' => $term->locale,
                'name' => $term->name,
                'entries' => count($positions),
            ], $ip);
        });
    }
}
