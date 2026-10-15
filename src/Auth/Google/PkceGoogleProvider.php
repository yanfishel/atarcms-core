<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use League\OAuth2\Client\Provider\Google;

/**
 * The league Google provider with S256 PKCE switched on.
 */
final class PkceGoogleProvider extends Google
{
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
