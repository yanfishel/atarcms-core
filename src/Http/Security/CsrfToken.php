<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use AtarCms\Http\Session\Session;

/**
 * Synchronizer token per session. The token is stable until rotate() (called on login),
 * so forms open in other tabs keep working.
 */
final class CsrfToken
{
    public const string SESSION_KEY = '_csrf';
    public const string FIELD = '_csrf';
    public const string HEADER = 'X-CSRF-Token';

    public static function get(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' ? $token : self::rotate($session);
    }

    public static function rotate(Session $session): string
    {
        $token = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public static function matches(Session $session, mixed $candidate): bool
    {
        $token = $session->get(self::SESSION_KEY);

        return is_string($token) && $token !== '' && is_string($candidate) && hash_equals($token, $candidate);
    }
}
