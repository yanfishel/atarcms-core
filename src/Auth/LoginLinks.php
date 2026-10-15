<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Kernel\Clock;
use DateInterval;

/**
 * One-time emergency login links (bin/atar user:login-link). Only SHA-256 hashes are stored.
 */
final class LoginLinks
{
    public const int TTL_SECONDS = 600;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {}

    public function create(User $user): string
    {
        $now = $this->clock->now();
        $this->db->execute('DELETE FROM login_links WHERE expires_at <= ?', [$now]);

        $token = bin2hex(random_bytes(32));
        $this->db->insert('login_links', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
            'created_at' => $now,
        ]);

        return $token;
    }

    /**
     * Returns the user id of a valid, unexpired link. A known link is deleted whether or not it was still valid.
     */
    public function consume(string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $now = $this->clock->now();

        return $this->db->transaction(static function (Connection $db) use ($hash, $now): ?int {
            $row = $db->selectOne('SELECT user_id, expires_at FROM login_links WHERE token_hash = ? FOR UPDATE', [$hash]);
            if ($row === null) {
                return null;
            }
            $db->execute('DELETE FROM login_links WHERE token_hash = ?', [$hash]);

            return Row::dateTime($row, 'expires_at') > $now ? Row::int($row, 'user_id') : null;
        });
    }
}
