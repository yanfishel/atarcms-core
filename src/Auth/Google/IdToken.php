<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use AtarCms\Auth\LoginFailure;
use JsonException;

final class IdToken
{
    /**
     * Decodes the payload of a JWT. The signature is not checked: the token comes straight from
     * Google's token endpoint over TLS (OpenID Connect Core 3.1.3.7); claims are checked by IdTokenValidator.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new GoogleLoginFailed(LoginFailure::InvalidToken, 'ID token is not a JWT');
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($json === false) {
            throw new GoogleLoginFailed(LoginFailure::InvalidToken, 'ID token payload is not base64url');
        }
        try {
            $claims = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GoogleLoginFailed(LoginFailure::InvalidToken, 'ID token payload is not JSON', $e);
        }
        if (!is_array($claims)) {
            throw new GoogleLoginFailed(LoginFailure::InvalidToken, 'ID token payload is not an object');
        }
        /** @var array<string, mixed> $claims */

        return $claims;
    }
}
