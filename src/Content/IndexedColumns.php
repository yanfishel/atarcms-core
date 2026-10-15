<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Kernel\ConfigException;

/**
 * Virtual generated columns (f_{field}) with an index for ->indexed() fields, and the ALTER TABLE statements
 * that bring the entries table in line with the config. Fields with the same name share one column.
 */
final class IndexedColumns
{
    public const string PREFIX = 'f_';

    public function __construct(
        private readonly Connection $db,
        private readonly Collections $collections,
    ) {}

    public static function column(string $field): string
    {
        return self::PREFIX . $field;
    }

    /**
     * @return array<string, string> column => SQL type, sorted by column
     */
    public function wanted(): array
    {
        $wanted = [];
        $owners = [];
        foreach ($this->collections->all() as $collection) {
            foreach ($collection->fields as $field) {
                if (!$field->indexed) {
                    continue;
                }
                $type = $field->type->columnType($field)
                    ?? throw new ConfigException(sprintf('The %s field "%s" cannot be indexed', $field->type->name(), $field->name));
                $column = self::column($field->name);
                if (isset($wanted[$column]) && $wanted[$column] !== $type) {
                    throw new ConfigException(sprintf(
                        'The indexed field "%s" is %s in collection "%s" but %s in "%s"; indexed fields with the same name must have the same type',
                        $field->name,
                        $type,
                        $collection->name,
                        $wanted[$column],
                        $owners[$column],
                    ));
                }
                $wanted[$column] = $type;
                $owners[$column] = $collection->name;
            }
        }
        ksort($wanted);

        return $wanted;
    }

    /**
     * @return array<string, string> existing generated f_* columns => lowercase data type
     */
    public function existing(): array
    {
        $rows = $this->db->select(
            "SELECT column_name AS name, data_type AS type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'entries' AND column_name LIKE ? AND is_generated = 'ALWAYS'
             ORDER BY column_name",
            [self::PREFIX . '%'],
        );
        $existing = [];
        foreach ($rows as $row) {
            $existing[Row::string($row, 'name')] = strtolower(Row::string($row, 'type'));
        }

        return $existing;
    }

    /**
     * @return list<string> drops (removed or retyped columns) first, then adds
     */
    public function statements(): array
    {
        $wanted = $this->wanted();
        $existing = $this->existing();
        $statements = [];
        foreach ($existing as $column => $type) {
            if (!isset($wanted[$column]) || self::baseType($wanted[$column]) !== $type) {
                $statements[] = sprintf(
                    'ALTER TABLE entries DROP INDEX IF EXISTS %s, DROP COLUMN %s',
                    Connection::quoteIdentifier(self::index($column)),
                    Connection::quoteIdentifier($column),
                );
            }
        }
        foreach ($wanted as $column => $type) {
            if (($existing[$column] ?? null) !== self::baseType($type)) {
                $statements[] = self::add($column, $type);
            }
        }

        return $statements;
    }

    private static function add(string $column, string $type): string
    {
        $value = sprintf("JSON_VALUE(data, '$.%s')", substr($column, strlen(self::PREFIX)));
        if (preg_match('/^VARCHAR\((\d+)\)$/', $type, $matches) === 1) {
            // LEFT() cuts silently; a plain VARCHAR conversion would make long values fail in strict mode.
            $value = sprintf('LEFT(%s, %d)', $value, (int) $matches[1]);
        }

        return sprintf(
            'ALTER TABLE entries ADD COLUMN %1$s %2$s AS (%3$s) VIRTUAL, ADD INDEX %4$s (collection, locale, %1$s)',
            Connection::quoteIdentifier($column),
            $type,
            $value,
            Connection::quoteIdentifier(self::index($column)),
        );
    }

    private static function index(string $column): string
    {
        return 'entries_' . $column . '_index';
    }

    private static function baseType(string $type): string
    {
        return strtolower((string) strtok($type, '('));
    }
}
