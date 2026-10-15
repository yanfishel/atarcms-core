<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Database\TimeSlot;
use DateTimeImmutable;

/**
 * The only class that runs SQL on the messages table.
 */
final class MessageRepository
{
    private const string COLUMNS = 'id, form, locale, name, email, phone, body, fields, page_url, created_at, read_at, mailed_at';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $row columns; "fields" an array<string, string> (stored as JSON, null when empty)
     */
    public function insert(array $row): int
    {
        if (array_key_exists('fields', $row)) {
            $row['fields'] = is_array($row['fields']) && $row['fields'] !== []
                ? json_encode($row['fields'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
        }

        return $this->db->insert('messages', $row);
    }

    public function find(int $id): ?Message
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' FROM messages WHERE id = ?', [$id]);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @param ?string $form only this form's messages; null for all
     *
     * @return list<Message> newest first
     */
    public function latest(int $limit, int $offset, ?string $form = null): array
    {
        [$where, $bindings] = self::whereForm($form);

        return array_map(
            self::hydrate(...),
            $this->db->select('SELECT ' . self::COLUMNS . ' FROM messages' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?', [...$bindings, $limit, $offset]),
        );
    }

    /**
     * The messages received since $since by the 15-minute slot of their time (TimeSlot).
     *
     * @return array<int, int> slot => messages, in slot order
     */
    public function receivedSlots(DateTimeImmutable $since): array
    {
        return TimeSlot::counts($this->db->select(
            'SELECT ' . TimeSlot::sql('created_at') . ' AS slot, COUNT(*) AS n FROM messages WHERE created_at >= ? GROUP BY slot ORDER BY slot',
            [$since],
        ));
    }

    /**
     * @param ?string $form only this form's messages; null for all
     */
    public function count(?string $form = null): int
    {
        [$where, $bindings] = self::whereForm($form);

        return $this->number('SELECT COUNT(*) FROM messages' . $where, $bindings);
    }

    /**
     * The unread messages of every form.
     */
    public function unreadCount(): int
    {
        return $this->number('SELECT COUNT(*) FROM messages WHERE read_at IS NULL');
    }

    /**
     * @return array<string, int> form => its unread messages, by form key; forms without any are left out
     */
    public function unreadCountsByForm(): array
    {
        $counts = [];
        foreach ($this->db->select('SELECT form, COUNT(*) AS n FROM messages WHERE read_at IS NULL GROUP BY form ORDER BY form') as $row) {
            $counts[Row::string($row, 'form')] = Row::int($row, 'n');
        }

        return $counts;
    }

    /**
     * The messages sent from one address (its hash) since $since: the rate limit, shared by all forms.
     */
    public function countFrom(string $ipHash, DateTimeImmutable $since): int
    {
        return $this->number('SELECT COUNT(*) FROM messages WHERE ip_hash = ? AND created_at >= ?', [$ipHash, $since]);
    }

    public function setRead(int $id, ?DateTimeImmutable $readAt): void
    {
        $this->db->execute('UPDATE messages SET read_at = ? WHERE id = ?', [$readAt, $id]);
    }

    public function setMailed(int $id, DateTimeImmutable $mailedAt): void
    {
        $this->db->execute('UPDATE messages SET mailed_at = ? WHERE id = ?', [$mailedAt, $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM messages WHERE id = ?', [$id]);
    }

    /**
     * Deletes every message (bin/atar demo:seed); returns how many there were.
     */
    public function deleteAll(): int
    {
        return $this->db->execute('DELETE FROM messages');
    }

    /**
     * @return array{string, list<string>}
     */
    private static function whereForm(?string $form): array
    {
        return $form === null ? ['', []] : [' WHERE form = ?', [$form]];
    }

    /**
     * @param list<mixed> $bindings
     */
    private function number(string $sql, array $bindings = []): int
    {
        return Row::int(['count' => $this->db->scalar($sql, $bindings)], 'count');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Message
    {
        $fields = [];
        if (($row['fields'] ?? null) !== null) {
            foreach (Row::json($row, 'fields') as $name => $value) {
                if (is_scalar($value)) {
                    $fields[(string) $name] = (string) $value;
                }
            }
        }

        return new Message(
            id: Row::int($row, 'id'),
            form: Row::string($row, 'form'),
            locale: Row::string($row, 'locale'),
            name: Row::nullableString($row, 'name'),
            email: Row::nullableString($row, 'email'),
            phone: Row::nullableString($row, 'phone'),
            body: Row::nullableString($row, 'body'),
            fields: $fields,
            pageUrl: Row::string($row, 'page_url'),
            createdAt: Row::dateTime($row, 'created_at'),
            readAt: Row::nullableDateTime($row, 'read_at'),
            mailedAt: Row::nullableDateTime($row, 'mailed_at'),
        );
    }
}
