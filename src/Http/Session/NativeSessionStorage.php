<?php

declare(strict_types=1);

namespace AtarCms\Http\Session;

use LogicException;
use RuntimeException;

/**
 * Stores session payloads through PHP's native session save handler (files by default, per php.ini).
 * PHP's own cookie handling is disabled: SessionMiddleware owns ids and cookies.
 * This is the only class that touches $_SESSION.
 */
final class NativeSessionStorage implements SessionStorage
{
    public function __construct(private readonly int $lifetime) {}

    public function read(string $id): ?array
    {
        $this->start($id);
        $payload = $_SESSION;
        $_SESSION = [];

        // The files save handler creates the session file with O_CREAT as soon as it opens,
        // even for an id nothing was ever written under. Reading must not leave that file
        // behind, or an unauthenticated client could create one file per request.
        if ($payload === []) {
            session_destroy();

            return null;
        }
        session_abort();

        return $payload;
    }

    public function write(string $id, array $payload): void
    {
        $this->start($id);
        $_SESSION = $payload;
        session_write_close();
    }

    public function destroy(string $id): void
    {
        $this->start($id);
        session_destroy();
    }

    private function start(string $id): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new LogicException('Another native session is already active');
        }
        session_id($id);
        $started = session_start([
            'use_cookies' => 0,
            'use_strict_mode' => 0,
            'cache_limiter' => '',
            'gc_maxlifetime' => $this->lifetime,
        ]);
        if (!$started) {
            throw new RuntimeException('Could not start the native session');
        }
    }
}
