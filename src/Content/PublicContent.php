<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use InvalidArgumentException;

/**
 * Public reads: every method applies the publishing rule (published or scheduled, published_at <= now) through
 * Entry::publicSql() or Entry::isPublicAt().
 */
final class PublicContent
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
        private readonly Collections $collections,
        private readonly Clock $clock,
        private readonly Locales $locales,
    ) {}

    /**
     * A non-translatable collection only has entries in the default locale, so it is read in that locale.
     */
    public function query(Collection|string $collection, string $locale): ContentQuery
    {
        $collection = $collection instanceof Collection ? $collection : $this->collections->get($collection);

        return new ContentQuery($this->db, $this->entries, $collection, $this->readLocale($collection, $locale), $this->clock->now());
    }

    public function isPublic(Entry $entry): bool
    {
        return $entry->isPublicAt($this->clock->now());
    }

    public function findBySlug(Collection $collection, string $locale, string $slug): ?Entry
    {
        $entry = $this->entries->findBySlug($collection->name, $locale, $slug);

        return $entry !== null && $this->isPublic($entry) ? $entry : null;
    }

    /**
     * Like query(), a non-translatable single is read in the default locale.
     */
    public function single(Collection $collection, string $locale): ?Entry
    {
        $entry = $this->entries->first($collection->name, $this->readLocale($collection, $locale));

        return $entry !== null && $this->isPublic($entry) ? $entry : null;
    }

    /**
     * The public preset pages (Collection::presets()) of every collection in $locale (a non-translatable collection's
     * in the default locale), in one query.
     *
     * @return array<string, Entry> preset slug => entry; a preset missing in the locale, or not public, is absent
     */
    public function presets(string $locale): array
    {
        $where = [];
        $bindings = [];
        foreach ($this->collections->all() as $collection) {
            if ($collection->presets !== []) {
                $where[] = '(collection = ? AND locale = ?)';
                array_push($bindings, $collection->name, $this->readLocale($collection, $locale));
            }
        }
        if ($where === []) {
            return [];
        }
        [$public, $publicBindings] = Entry::publicSql($this->clock->now());
        $presets = [];
        foreach ($this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE (' . implode(' OR ', $where) . ') AND preset IS NOT NULL AND ' . $public,
            [...$bindings, ...$publicBindings],
        ) as $entry) {
            if ($entry->isPreset()) {
                $presets[(string) $entry->preset] = $entry;
            }
        }

        return $presets;
    }

    /**
     * @return list<Entry> every public entry of $collection in every locale, by translation group, then locale
     */
    public function all(Collection $collection): array
    {
        [$where, $bindings] = $this->publicIn($collection);

        return $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $where . ' ORDER BY translation_group, locale',
            $bindings,
        );
    }

    /**
     * The number of public entries of $collection in every locale (sitemap URLs).
     */
    public function publicCount(Collection $collection): int
    {
        [$where, $bindings] = $this->publicIn($collection);

        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entries WHERE ' . $where, $bindings)], 'count');
    }

    /**
     * The number of translation groups with at least one public entry of $collection.
     */
    public function publicGroupCount(Collection $collection): int
    {
        [$where, $bindings] = $this->publicIn($collection);

        return Row::int(['count' => $this->db->scalar('SELECT COUNT(DISTINCT translation_group) FROM entries WHERE ' . $where, $bindings)], 'count');
    }

    /**
     * One sitemap page of $collection: the public entries of $groups translation groups (whole groups, so the
     * translations of a page stay together), skipping the first $offset groups, by translation group, then locale.
     *
     * @return list<Entry>
     */
    public function sitemapChunk(Collection $collection, int $offset, int $groups): array
    {
        if ($offset < 0 || $groups < 1) {
            throw new InvalidArgumentException('The sitemap chunk needs a non-negative offset and at least one group');
        }
        [$public, $bindings] = $this->publicIn($collection);

        // MariaDB does not allow LIMIT directly in an IN subquery; the derived table g carries it.
        return $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $public
            . ' AND translation_group IN (SELECT translation_group FROM (SELECT DISTINCT translation_group FROM entries WHERE ' . $public
            . ' ORDER BY translation_group LIMIT ? OFFSET ?) g) ORDER BY translation_group, locale',
            [...$bindings, ...$bindings, $groups, $offset],
        );
    }

    /**
     * A page of the public entries in the term's locale that carry $term (not its subterms) from every collection
     * with a route that uses the term's taxonomy (Collections::listedBy()): the content of a term page. They come in
     * the term's order (EntryRepository::TERM_ORDER): a section's manual order (TermEntryOrder), else newest first.
     */
    public function byTerm(Term $term, int $number, int $perPage): EntryPage
    {
        if ($number < 1 || $perPage < 1 || $perPage > 1000) {
            throw new InvalidArgumentException('The page number must be at least 1 and entries per page between 1 and 1000');
        }
        $collections = array_map(static fn(Collection $collection): string => $collection->name, $this->collections->listedBy($term->taxonomy));
        if ($collections === []) {
            return new EntryPage([], $number, $perPage, 0);
        }
        [$public, $publicBindings] = Entry::publicSql($this->clock->now());
        $where = 'collection IN (' . implode(', ', array_fill(0, count($collections), '?')) . ')'
            . ' AND locale = ? AND ' . $public
            . ' AND id IN (SELECT entry_id FROM entry_terms WHERE term_id = ?)';
        $bindings = [...$collections, $term->locale, ...$publicBindings, $term->id];
        $entries = $this->entries->many(
            'SELECT ' . EntryRepository::COLUMNS . ' FROM entries WHERE ' . $where . ' ORDER BY ' . EntryRepository::TERM_ORDER . ' LIMIT ? OFFSET ?',
            [...$bindings, $term->id, $perPage, ($number - 1) * $perPage],
        );
        $total = Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM entries WHERE ' . $where, $bindings)], 'count');

        return new EntryPage($entries, $number, $perPage, $total);
    }

    /**
     * The public entries of $collection in every locale, as an SQL condition and its bindings.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function publicIn(Collection $collection): array
    {
        [$public, $bindings] = Entry::publicSql($this->clock->now());

        return ['collection = ? AND ' . $public, [$collection->name, ...$bindings]];
    }

    private function readLocale(Collection $collection, string $locale): string
    {
        return $collection->translatable ? $locale : $this->locales->default();
    }
}
