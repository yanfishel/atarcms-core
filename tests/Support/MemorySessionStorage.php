<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Http\Session\SessionStorage;

final class MemorySessionStorage implements SessionStorage
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    public function read(string $id): ?array
    {
        return $this->sessions[$id] ?? null;
    }

    public function write(string $id, array $payload): void
    {
        $this->sessions[$id] = $payload;
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }
}
