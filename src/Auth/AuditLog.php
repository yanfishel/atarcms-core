<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Kernel\Clock;

final class AuditLog
{
    /** Who may read the log: the activity history screen and the dashboard's recent activity. */
    public const string PERMISSION = 'audit.view';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public function record(
        string $action,
        ?int $userId = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        array $meta = [],
        ?string $ip = null,
    ): void {
        $this->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'meta' => json_encode(
                (object) $meta,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            'ip' => $ip,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return list<AuditEntry> newest first
     */
    public function latest(int $limit, int $offset = 0): array
    {
        $rows = $this->db->select(
            'SELECT a.id, a.user_id, u.email AS user_email, a.action, a.subject_type, a.subject_id, a.meta, a.ip, a.created_at
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return array_map(static fn(array $row): AuditEntry => new AuditEntry(
            id: Row::int($row, 'id'),
            userId: Row::nullableInt($row, 'user_id'),
            userEmail: Row::nullableString($row, 'user_email'),
            action: Row::string($row, 'action'),
            subjectType: Row::nullableString($row, 'subject_type'),
            subjectId: Row::nullableString($row, 'subject_id'),
            meta: Row::json($row, 'meta'),
            ip: Row::nullableString($row, 'ip'),
            createdAt: Row::dateTime($row, 'created_at'),
        ), $rows);
    }

    public function count(): int
    {
        return Row::int(['count' => $this->db->scalar('SELECT COUNT(*) FROM audit_log')], 'count');
    }
}
