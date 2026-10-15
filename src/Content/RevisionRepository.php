<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Database\TimeSlot;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use DateTimeImmutable;

/**
 * Entry snapshots written on every save; only the newest content.revisions (default 20) are kept per entry. An
 * autosave's snapshot carries "autosave": true (recordAutosave()).
 */
final class RevisionRepository
{
    private const string COLUMNS = 'id, entry_id, data, author_id, created_at';

    private readonly int $keep;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        Config $config,
    ) {
        $keep = $config->int('content.revisions', 20);
        if ($keep < 1) {
            throw new ConfigException('Config "content.revisions" must be at least 1');
        }
        $this->keep = $keep;
    }

    /**
     * @param list<int> $termIds the entry's terms after the save (EntryService::restore() brings them back)
     */
    public function record(Entry $entry, ?int $authorId, array $termIds = []): int
    {
        return $this->insert($entry, $authorId, $termIds, false);
    }

    /**
     * An autosave's revision: it overwrites the entry's newest revision (snapshot and time) when that one is an
     * autosave by the same author, so an editing session between two manual saves leaves one autosave revision;
     * otherwise it adds one, flagged "autosave" in its snapshot.
     *
     * @param list<int> $termIds
     *
     * @return bool whether a revision was added (false: the newest one was overwritten)
     */
    public function recordAutosave(Entry $entry, ?int $authorId, array $termIds = []): bool
    {
        $latest = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM entry_revisions WHERE entry_id = ? ORDER BY id DESC LIMIT 1',
            [$entry->id],
        );
        $latest = $latest === null ? null : self::hydrate($latest);
        if ($latest !== null && $authorId !== null && $latest->authorId === $authorId && $latest->isAutosave()) {
            $this->db->execute(
                'UPDATE entry_revisions SET data = ?, created_at = ? WHERE id = ?',
                [self::json($entry, $termIds, true), $this->clock->now(), $latest->id],
            );

            return false;
        }
        $this->insert($entry, $authorId, $termIds, true);

        return true;
    }

    /**
     * @param list<int> $termIds
     */
    private function insert(Entry $entry, ?int $authorId, array $termIds, bool $autosave): int
    {
        $id = $this->db->insert('entry_revisions', [
            'entry_id' => $entry->id,
            'data' => self::json($entry, $termIds, $autosave),
            'author_id' => $authorId,
            'created_at' => $this->clock->now(),
        ]);
        $cutoff = $this->db->scalar(
            'SELECT id FROM entry_revisions WHERE entry_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?',
            [$entry->id, $this->keep],
        );
        if ($cutoff !== null) {
            $this->db->execute(
                'DELETE FROM entry_revisions WHERE entry_id = ? AND id <= ?',
                [$entry->id, Row::int(['id' => $cutoff], 'id')],
            );
        }

        return $id;
    }

    /**
     * @return list<Revision> newest first
     */
    public function forEntry(int $entryId): array
    {
        return array_map(
            self::hydrate(...),
            $this->db->select('SELECT ' . self::COLUMNS . ' FROM entry_revisions WHERE entry_id = ? ORDER BY id DESC', [$entryId]),
        );
    }

    /**
     * The stored versions of the entries of $collections by the 15-minute slot of their time (TimeSlot), since
     * $since: the dashboard's edits (an editing session's autosaves are one version; versions beyond the newest kept
     * ones are gone).
     *
     * @param list<string> $collections
     *
     * @return array<int, int> slot => versions, in slot order
     */
    public function editSlots(array $collections, DateTimeImmutable $since): array
    {
        if ($collections === []) {
            return [];
        }

        return TimeSlot::counts($this->db->select(
            'SELECT ' . TimeSlot::sql('r.created_at') . ' AS slot, COUNT(*) AS n FROM entry_revisions r'
                . ' JOIN entries e ON e.id = r.entry_id WHERE e.collection IN (' . implode(', ', array_fill(0, count($collections), '?')) . ')'
                . ' AND r.created_at >= ? GROUP BY slot ORDER BY slot',
            [...$collections, $since],
        ));
    }

    public function find(int $entryId, int $revisionId): ?Revision
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM entry_revisions WHERE entry_id = ? AND id = ?',
            [$entryId, $revisionId],
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Entry $entry): array
    {
        return [
            'slug' => $entry->slug,
            'title' => $entry->title,
            'status' => $entry->status->value,
            'published_at' => $entry->publishedAt?->format('Y-m-d H:i:s'),
            'parent_id' => $entry->parentId,
            'position' => $entry->position,
            'meta_title' => $entry->seo->metaTitle,
            'meta_description' => $entry->seo->metaDescription,
            'data' => $entry->data,
        ];
    }

    /**
     * @param list<int> $termIds
     */
    private static function json(Entry $entry, array $termIds, bool $autosave): string
    {
        return json_encode(
            [...self::snapshot($entry), 'data' => (object) $entry->data, 'terms' => $termIds, ...($autosave ? ['autosave' => true] : [])],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Revision
    {
        return new Revision(
            id: Row::int($row, 'id'),
            entryId: Row::int($row, 'entry_id'),
            snapshot: Row::json($row, 'data'),
            authorId: Row::nullableInt($row, 'author_id'),
            createdAt: Row::dateTime($row, 'created_at'),
        );
    }
}
