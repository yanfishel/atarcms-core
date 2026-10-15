<?php

declare(strict_types=1);

namespace AtarCms\Http\Session;

interface SessionStorage
{
    /**
     * @return array<mixed>|null null when no session with this id exists
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $id, array $payload): void;

    public function destroy(string $id): void;
}
