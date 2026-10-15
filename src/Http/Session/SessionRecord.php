<?php

declare(strict_types=1);

namespace AtarCms\Http\Session;

/**
 * What SessionMiddleware stores per session id.
 */
final readonly class SessionRecord
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public array $data,
        public int $createdAt,
        public int $lastSeen,
    ) {}

    /**
     * @param array<mixed>|null $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }
        $data = $payload['data'] ?? null;
        $createdAt = $payload['created_at'] ?? null;
        $lastSeen = $payload['last_seen'] ?? null;
        if (!is_array($data) || !is_int($createdAt) || !is_int($lastSeen)) {
            return null;
        }
        /** @var array<string, mixed> $data */

        return new self($data, $createdAt, $lastSeen);
    }

    /**
     * @return array{data: array<string, mixed>, created_at: int, last_seen: int}
     */
    public function toPayload(): array
    {
        return ['data' => $this->data, 'created_at' => $this->createdAt, 'last_seen' => $this->lastSeen];
    }
}
