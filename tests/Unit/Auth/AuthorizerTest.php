<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Exception\Forbidden;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthorizerTest extends TestCase
{
    public function testChecksRolePermissionsForActiveUsersOnly(): void
    {
        $authorizer = new Authorizer(new Roles(Roles::DEFAULTS));

        self::assertTrue($authorizer->can(self::user('admin'), 'users.manage'));
        self::assertFalse($authorizer->can(self::user('editor'), 'users.manage'));
        self::assertFalse($authorizer->can(self::user('admin', UserStatus::Disabled), 'users.manage'));
        self::assertFalse($authorizer->can(null, 'entries.news.view'));
    }

    public function testAuthorizeThrowsForbidden(): void
    {
        $authorizer = new Authorizer(new Roles(Roles::DEFAULTS));
        $authorizer->authorize(self::user('admin'), 'audit.view');

        $this->expectException(Forbidden::class);

        $authorizer->authorize(self::user('author'), 'audit.view');
    }

    private static function user(string $role, UserStatus $status = UserStatus::Active): User
    {
        return new User(1, 'u@example.com', '', $role, null, $status, null, null, null, new DateTimeImmutable());
    }
}
