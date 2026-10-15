<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Routing;

use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Auth\Http\RequirePermission;
use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequireDefaultLocale;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RouteStatelessTest extends TestCase
{
    public function testGetRouteCanBeStateless(): void
    {
        $route = (new Router())->get('/file', [stdClass::class, 'file'])->middleware(RequireDefaultLocale::class)->stateless();

        self::assertTrue($route->isStateless());
        self::assertFalse((new Router())->get('/page', [stdClass::class, 'page'])->isStateless());
    }

    public function testNonGetRoutesCannotBeStateless(): void
    {
        foreach ([['POST'], ['GET', 'POST'], ['HEAD']] as $methods) {
            try {
                (new Route($methods, '/x', [stdClass::class, 'x']))->stateless();
                self::fail('Expected a LogicException for ' . implode(', ', $methods));
            } catch (LogicException $e) {
                self::assertStringContainsString('Only GET routes can be stateless', $e->getMessage());
            }
        }
    }

    public function testAPublicFormIsAStatelessPost(): void
    {
        $route = (new Router())->post('/contacts', [stdClass::class, 'send'])->publicForm('contact');

        self::assertTrue($route->isStateless());
        self::assertSame('contact', $route->publicForm);
        self::assertNull((new Router())->post('/x', [stdClass::class, 'x'])->publicForm);

        foreach ([['GET'], ['GET', 'POST']] as $methods) {
            try {
                (new Route($methods, '/x', [stdClass::class, 'x']))->publicForm('contact');
                self::fail('Expected a LogicException for ' . implode(', ', $methods));
            } catch (LogicException $e) {
                self::assertStringContainsString('Only POST routes can be public forms', $e->getMessage());
            }
        }
        $this->expectException(LogicException::class);
        (new Router())->post('/y', [stdClass::class, 'y'])->middleware(RequireAuthentication::class)->publicForm('contact');
    }

    public function testStatelessRejectsRequireAuthenticationInEitherOrder(): void
    {
        try {
            (new Route(['GET'], '/x', [stdClass::class, 'x']))->middleware(RequireAuthentication::class)->stateless();
            self::fail('Expected a LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('cannot use ' . RequireAuthentication::class, $e->getMessage());
        }

        $this->expectException(LogicException::class);
        (new Route(['GET'], '/x', [stdClass::class, 'x']))->stateless()->middleware(RequireAuthentication::class);
    }

    public function testRequirePermissionOnAStatelessRouteFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot use ' . RequirePermission::class);

        RequirePermission::on((new Route(['GET'], '/x', [stdClass::class, 'x']))->stateless(), 'users.manage');
    }
}
