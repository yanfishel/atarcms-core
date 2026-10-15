<?php

declare(strict_types=1);

namespace AtarCms\Http;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIp
{
    public static function of(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
