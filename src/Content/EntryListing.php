<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Database\TimeSlot;
use DateTimeImmutable;

/**
 * Admin reads over entries of every status: lists with filters, option lists and dashboard queries.
 * Public reads go through PublicContent, writes through EntryService.
 */
final class EntryListing
{
    public const int PER_PAGE = 25;

    /** Columns an admin list may be sorted by. */
    public const array SORTS = ['title', 'status', 'published_at', 'updated_at', 'position', 'slug'];

    private const int MAX_SEARCH = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
    ) {}

    /**
     * The list's order without a chosen one: a hierarchical collection by its manual position, any other by the
     * latest update first.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public static function defaultSort(Collection $collection): array
    {
        return $collection->hierarchical ? ['position', 'asc'] : ['updated_at', 'desc'];
    }

    /**
     * @param list<int> $termIds entries with any of these terms (a term and its descendants); none: no term filter
     */
    public function page(Collection $collection, string $locale, ?EntryStatus $status, string $search, string $sort, string $direction, int $page, array $termIds = []): EntryPage
    {
        $page = max(1, $page);
        $where = 'collection = ? AND locale = ?';
        $bindings = [$collection->name, $locale];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $bindings[] = $status->value;
        }
        $search = mb_substr(trim($search), 0, self::MAX_SEARCH);
        if ($search !== '') {
            $where .= ' AND title LIKE ?';
            $bindings[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        if ($termIds !== []) {
            $where .= ' AND id IN (SELECT entry_id FROM entry_terms WHERE term_id IN (' . implode(', ', array_fill(0, count($termIds), '?')) . '))';
            array_push($bindings, ...$termIds);
        }
        if (!in_array($sort, self::SORTS, true) || !in_array($direction, ['asc', 'desc'], true)) {
            [$sort, $direction] = self::defaultSort($collection);
        }
        $order = sprintf('%s %s, id %s', Connection::quoteIdentifier($sort), strtoupper($direction), strtoupper($direction));

        $entries = $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            [...$bindings, self::PER_PAGE, ($page - 1) * self::PER_PAGE],
        );
        $total = Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entries WHERE ' . $where, $bindings)], 'count');

        return new EntryPage($entries, $page, self::PER_PAGE, $total);
    }

    /**
     * @param list<string> $groups
     *
     * @return array<string, list<string>> translation group => locales
     */
    public function localesByGroup(array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT translation_group, locale FROM entries WHERE translation_group IN ('
                . implode(', ', array_fill(0, count($groups), '?')) . ') ORDER BY translation_group, locale',
            $groups,
        );
        $locales = [];
        foreach ($rows as $row) {
            $locales[Row::string($row, 'translation_group')][] = Row::string($row, 'locale');
        }

        return $locales;
    }

    /**
     * @return array<int, string> id => title, for pickers (parent, relation)
     */
    public function titles(string $collection, string $locale, int $limit = 500): array
    {
        $titles = [];
        foreach ($this->db->select(
            'SELECT id, title FROM entries WHERE collection = ? AND locale = ? ORDER BY title, id LIMIT ?',
            [$collection, $locale, $limit],
        ) as $row) {
            $titles[Row::int($row, 'id')] = Row::string($row, 'title');
        }

        return $titles;
    }

    /**
     * @param list<string> $collections
     *
     * @return list<Entry>
     */
    public function recentlyUpdated(array $collections, int $limit): array
    {
        return $this->dashboard($collections, '', [], 'updated_at DESC, id DESC', $limit);
    }

    /**
     * @param list<string> $collections
     *
     * @return list<Entry>
     */
    public function drafts(array $collections, int $limit): array
    {
        return $this->dashboard($collections, ' AND status = ?', [EntryStatus::Draft->value], 'updated_at DESC, id DESC', $limit);
    }

    /**
     * @param list<string> $collections
     *
     * @return list<Entry> the entries waiting for their time (scheduled, or published with a date still ahead: what
     *                     statusCounts() calls scheduled), soonest first
     */
    public function scheduled(array $collections, DateTimeImmutable $now, int $limit): array
    {
        return $this->dashboard($collections, ' AND status IN (?, ?) AND published_at > ?', [...EntryStatus::PUBLIC, $now], 'published_at ASC, id ASC', $limit);
    }

    /**
     * The entries of each collection by status, every language: public now, waiting for their time (scheduled ahead,
     * or published with a time still ahead) and drafts. Collections without entries are left out.
     *
     * @param list<string> $collections
     *
     * @return array<string, array{published: int, scheduled: int, draft: int}> in the order of $collections
     */
    public function statusCounts(array $collections, DateTimeImmutable $now): array
    {
        if ($collections === []) {
            return [];
        }
        [$public, $bindings] = Entry::publicSql($now);
        $rows = $this->db->select(
            'SELECT collection, COUNT(*) AS total, SUM(CASE WHEN ' . $public . ' THEN 1 ELSE 0 END) AS published,'
                . ' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft FROM entries WHERE collection IN ('
                . self::placeholders($collections) . ') GROUP BY collection',
            [...$bindings, EntryStatus::Draft->value, ...$collections],
        );
        $found = [];
        foreach ($rows as $row) {
            $published = Row::int($row, 'published');
            $draft = Row::int($row, 'draft');
            $found[Row::string($row, 'collection')] = [
                'published' => $published,
                'scheduled' => Row::int($row, 'total') - $published - $draft,
                'draft' => $draft,
            ];
        }

        $ordered = [];
        foreach ($collections as $collection) {
            if (isset($found[$collection])) {
                $ordered[$collection] = $found[$collection];
            }
        }

        return $ordered;
    }

    /**
     * Public entries by the 15-minute slot of their publication time (TimeSlot), from $since to $now.
     *
     * @param list<string> $collections
     *
     * @return array<int, int> slot => entries, in slot order
     */
    public function publishedSlots(array $collections, DateTimeImmutable $since, DateTimeImmutable $now): array
    {
        if ($collections === []) {
            return [];
        }
        [$public, $bindings] = Entry::publicSql($now);

        return TimeSlot::counts($this->db->select(
            'SELECT ' . TimeSlot::sql('published_at') . ' AS slot, COUNT(*) AS n FROM entries WHERE collection IN ('
                . self::placeholders($collections) . ') AND ' . $public . ' AND published_at >= ? GROUP BY slot ORDER BY slot',
            [...$collections, ...$bindings, $since],
        ));
    }

    /**
     * How far each language is translated: the translation groups of the collections, and in how many of them each
     * language has an entry.
     *
     * @param list<string> $collections
     *
     * @return array{groups: int, locales: array<string, int>} locales by code
     */
    public function translationCoverage(array $collections): array
    {
        if ($collections === []) {
            return ['groups' => 0, 'locales' => []];
        }
        $in = 'collection IN (' . self::placeholders($collections) . ')';
        $locales = [];
        foreach ($this->db->select(
            'SELECT locale, COUNT(DISTINCT translation_group) AS n FROM entries WHERE ' . $in . ' GROUP BY locale ORDER BY locale',
            $collections,
        ) as $row) {
            $locales[Row::string($row, 'locale')] = Row::int($row, 'n');
        }

        return [
            'groups' => Row::int(['n' => $this->db->scalar('SELECT COUNT(DISTINCT translation_group) FROM entries WHERE ' . $in, $collections)], 'n'),
            'locales' => $locales,
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param list<string> $collections
     * @param list<mixed> $bindings
     *
     * @return list<Entry>
     */
    private function dashboard(array $collections, string $condition, array $bindings, string $order, int $limit): array
    {
        if ($collections === []) {
            return [];
        }

        return $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE collection IN ('
                . implode(', ', array_fill(0, count($collections), '?')) . ')' . $condition . ' ORDER BY ' . $order . ' LIMIT ?',
            [...$collections, ...$bindings, $limit],
        );
    }
}
