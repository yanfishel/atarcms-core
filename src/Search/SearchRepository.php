<?php

declare(strict_types=1);

namespace AtarCms\Search;

use AtarCms\Content\Entry;
use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * All SQL on search_index. Rows hold normalised stems (TextNormalizer); deleting an entry deletes its row through
 * the foreign key.
 */
final class SearchRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Writes the entry's row, replacing the one it had.
     */
    public function replace(int $entryId, string $collection, string $locale, string $title, string $body): void
    {
        $this->db->execute(
            'INSERT INTO search_index (entry_id, collection, locale, title, body) VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE collection = VALUES(collection), locale = VALUES(locale), title = VALUES(title), body = VALUES(body)',
            [$entryId, $collection, $locale, $title, $body],
        );
    }

    public function delete(int $entryId): void
    {
        $this->db->execute('DELETE FROM search_index WHERE entry_id = ?', [$entryId]);
    }

    /**
     * Empties the index (search:reindex rebuilds it). A DELETE, not TRUNCATE: TRUNCATE rebuilds the FULLTEXT indexes
     * under the session's innodb_ft_enable_stopword (ON by default), which would bring the stopword list back.
     */
    public function clear(): void
    {
        $this->db->execute('DELETE FROM search_index');
    }

    /**
     * A page of the public entries (Entry::publicSql() at $now) of $collections in $locale that contain every stem of
     * $query: a match in the title counts twice, ties go to the newest. An empty query or no collections returns an
     * empty page without touching the database.
     *
     * @param list<string> $collections
     */
    public function search(SearchQuery $query, string $locale, array $collections, DateTimeImmutable $now, int $number, int $perPage): SearchPage
    {
        if ($number < 1 || $perPage < 1) {
            throw new InvalidArgumentException('The page number and the hits per page must be at least 1');
        }
        if ($query->isEmpty() || $collections === []) {
            return new SearchPage([], 0, $number, $perPage);
        }
        $against = $query->boolean();
        [$public, $publicBindings] = Entry::publicSql($now);
        $where = 's.locale = ? AND s.collection IN (' . implode(', ', array_fill(0, count($collections), '?')) . ')'
            . ' AND ' . $public
            . ' AND MATCH (s.title, s.body) AGAINST (? IN BOOLEAN MODE)';
        $bindings = [$locale, ...$collections, ...$publicBindings, $against];
        $from = ' FROM search_index s JOIN entries e ON e.id = s.entry_id WHERE ' . $where;

        $rows = $this->db->select(
            'SELECT s.entry_id' . $from
            . ' ORDER BY (2 * MATCH (s.title) AGAINST (? IN BOOLEAN MODE) + MATCH (s.body) AGAINST (? IN BOOLEAN MODE)) DESC,'
            . ' e.published_at DESC, e.id DESC LIMIT ? OFFSET ?',
            [...$bindings, $against, $against, $perPage, ($number - 1) * $perPage],
        );
        $total = Row::int(['count' => $this->db->scalar('SELECT COUNT(*)' . $from, $bindings)], 'count');

        return new SearchPage(array_map(static fn(array $row): int => Row::int($row, 'entry_id'), $rows), $total, $number, $perPage);
    }
}
