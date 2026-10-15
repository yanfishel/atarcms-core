<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use DateTimeImmutable;

/**
 * All SQL on the slug_history table: the previous slugs of published entries (EntryService) and of terms
 * (TermService), so an old address answers 301 to the current one (SlugRedirects). A slug of one collection or
 * taxonomy and locale names one owner; the owner's delete cascades.
 */
final class SlugHistoryRepository
{
    public const string ENTRY = 'entry';

    public const string TERM = 'term';

    public function __construct(private readonly Connection $db) {}

    /**
     * Remembers that $slug of a $collection entry in $locale now belongs to entry $id (the last owner wins).
     */
    public function recordEntry(string $collection, string $locale, string $slug, int $id, DateTimeImmutable $now): void
    {
        $this->record(self::ENTRY, $collection, $locale, $slug, 'entry_id', $id, $now);
    }

    /**
     * Remembers that $slug of a $taxonomy term in $locale now belongs to term $id (the last owner wins).
     */
    public function recordTerm(string $taxonomy, string $locale, string $slug, int $id, DateTimeImmutable $now): void
    {
        $this->record(self::TERM, $taxonomy, $locale, $slug, 'term_id', $id, $now);
    }

    public function entryId(string $collection, string $locale, string $slug): ?int
    {
        return $this->owner(self::ENTRY, $collection, $locale, $slug, 'entry_id');
    }

    public function termId(string $taxonomy, string $locale, string $slug): ?int
    {
        return $this->owner(self::TERM, $taxonomy, $locale, $slug, 'term_id');
    }

    private function record(string $kind, string $scope, string $locale, string $slug, string $column, int $id, DateTimeImmutable $now): void
    {
        $this->db->execute(
            'INSERT INTO slug_history (kind, scope, locale, slug, ' . Connection::quoteIdentifier($column) . ', created_at) VALUES (?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE entry_id = VALUES(entry_id), term_id = VALUES(term_id), created_at = VALUES(created_at)',
            [$kind, $scope, $locale, $slug, $id, $now],
        );
    }

    private function owner(string $kind, string $scope, string $locale, string $slug, string $column): ?int
    {
        $id = $this->db->scalar(
            'SELECT ' . Connection::quoteIdentifier($column) . ' FROM slug_history WHERE kind = ? AND scope = ? AND locale = ? AND slug = ?',
            [$kind, $scope, $locale, $slug],
        );

        return is_numeric($id) ? (int) $id : null;
    }
}
