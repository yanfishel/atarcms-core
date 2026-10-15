<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Http;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\Http\RequirePermission;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\CallableHandler;
use AtarCms\Http\Exception\Forbidden;
use AtarCms\Http\Routing\Route;
use DateTimeImmutable;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class RequirePermissionTest extends TestCase
{
    public function testOnAddsMiddlewareAndPermissionAttribute(): void
    {
        $route = RequirePermission::on(new Route(['GET'], '/x', [stdClass::class, 'x']), 'users.manage');

        self::assertSame([RequirePermission::class], $route->middlewareList());
        self::assertSame([RequirePermission::class => 'users.manage'], $route->attributes());
    }

    public function testAllowsUsersWithThePermission(): void
    {
        self::assertSame(204, $this->process(self::user('admin'), 'users.manage')->getStatusCode());
    }

    public function testForbidsUsersWithoutThePermission(): void
    {
        $this->expectException(Forbidden::class);

        $this->process(self::user('editor'), 'users.manage');
    }

    public function testMisconfiguredRouteIsALogicError(): void
    {
        $this->expectException(LogicException::class);

        $this->process(self::user('admin'), null);
    }

    private function process(User $user, ?string $permission): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/x')->withAttribute(User::class, $user);
        if ($permission !== null) {
            $request = $request->withAttribute(RequirePermission::class, $permission);
        }

        return (new RequirePermission(new Authorizer(new Roles(Roles::DEFAULTS))))->process(
            $request,
            new CallableHandler(static fn(ServerRequestInterface $r): ResponseInterface => (new Psr17Factory())->createResponse(204)),
        );
    }

    private static function user(string $role): User
    {
        return new User(1, 'u@example.com', '', $role, null, UserStatus::Active, null, null, null, new DateTimeImmutable());
    }
}
