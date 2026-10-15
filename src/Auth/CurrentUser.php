<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use LogicException;
use Psr\Http\Message\ServerRequestInterface;

final class CurrentUser
{
    public static function of(ServerRequestInterface $request): ?User
    {
        $user = $request->getAttribute(User::class);

        return $user instanceof User ? $user : null;
    }

    public static function require(ServerRequestInterface $request): User
    {
        return self::of($request) ?? throw new LogicException('No authenticated user; protect the route with RequireAuthentication');
    }
}
