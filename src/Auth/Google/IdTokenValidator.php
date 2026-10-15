<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Clock;

final class IdTokenValidator
{
    private const array ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const int LEEWAY = 60;

    public function __construct(private readonly Clock $clock) {}

    /**
     * @param array<string, mixed> $claims
     *
     * @throws GoogleLoginFailed
     */
    public function validate(array $claims, string $clientId): GoogleIdentity
    {
        $now = $this->clock->now()->getTimestamp();

        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw self::invalid('unexpected issuer');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
        // azp (the client the token was issued to) must be ours when present, and is required with several audiences.
        $authorizedParty = $claims['azp'] ?? null;
        if (
            !in_array($clientId, $audiences, true)
            || ($authorizedParty !== null && $authorizedParty !== $clientId)
            || (count($audiences) > 1 && $authorizedParty === null)
        ) {
            throw self::invalid('audience mismatch');
        }
        $expires = $claims['exp'] ?? null;
        if (!is_int($expires) || $expires + self::LEEWAY < $now) {
            throw self::invalid('expired');
        }
        $issuedAt = $claims['iat'] ?? null;
        if (!is_int($issuedAt) || $issuedAt - self::LEEWAY > $now) {
            throw self::invalid('issued in the future');
        }
        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || $subject === '') {
            throw self::invalid('missing subject');
        }
        $email = $claims['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw self::invalid('missing email');
        }
        $verified = $claims['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new GoogleLoginFailed(LoginFailure::EmailUnverified, 'Google reports the email as unverified');
        }
        $domain = $claims['hd'] ?? null;
        $name = $claims['name'] ?? null;
        $picture = $claims['picture'] ?? null;

        return new GoogleIdentity(
            $subject,
            UserRepository::normalizeEmail($email),
            is_string($domain) && $domain !== '' ? strtolower($domain) : null,
            is_string($name) ? $name : '',
            is_string($picture) && $picture !== '' ? $picture : null,
        );
    }

    private static function invalid(string $why): GoogleLoginFailed
    {
        return new GoogleLoginFailed(LoginFailure::InvalidToken, 'Invalid ID token: ' . $why);
    }
}
