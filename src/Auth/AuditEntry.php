<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use DateTimeImmutable;

final readonly class AuditEntry
{
    /**
     * @param array<mixed> $meta
     */
    public function __construct(
        public int $id,
        public ?int $userId,
        public ?string $userEmail,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $meta,
        public ?string $ip,
        public DateTimeImmutable $createdAt,
    ) {}
}
