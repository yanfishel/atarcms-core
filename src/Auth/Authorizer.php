<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Http\Exception\Forbidden;

final class Authorizer
{
    public function __construct(private readonly Roles $roles) {}

    public function can(?User $user, string $permission): bool
    {
        return $user !== null && $user->isActive() && $this->roles->allows($user->role, $permission);
    }

    public function authorize(?User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new Forbidden(sprintf('Missing permission "%s"', $permission));
        }
    }
}
