<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use DateTimeImmutable;

/**
 * All SQL on the settings table. A site-wide setting is stored with the locale ''. Validation lives in SettingsService.
 */
final class SettingsRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<string, string> name => value: the site-wide settings and those of $locale
     */
    public function forLocale(string $locale): array
    {
        $values = [];
        foreach ($this->db->select("SELECT name, value FROM settings WHERE locale IN ('', ?) ORDER BY locale, name", [$locale]) as $row) {
            $values[Row::string($row, 'name')] = Row::string($row, 'value');
        }

        return $values;
    }

    /**
     * Deletes every setting except those named in $keep (in every locale): bin/atar demo:seed.
     *
     * @param list<string> $keep
     *
     * @return int the number of deleted rows
     */
    public function deleteExcept(array $keep): int
    {
        return $keep === []
            ? $this->db->execute('DELETE FROM settings')
            : $this->db->execute('DELETE FROM settings WHERE name NOT IN (' . implode(', ', array_fill(0, count($keep), '?')) . ')', $keep);
    }

    /**
     * Stores $value under $name and $locale ('' for a site-wide setting); null removes the setting.
     */
    public function put(string $name, string $locale, ?string $value, DateTimeImmutable $now): void
    {
        if ($value === null) {
            $this->db->execute('DELETE FROM settings WHERE name = ? AND locale = ?', [$name, $locale]);

            return;
        }
        $this->db->execute(
            'INSERT INTO settings (name, locale, value, updated_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $locale, $value, $now],
        );
    }
}
