<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Http;

use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Auth\User;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\CallableHandler;
use AtarCms\Http\Exception\Forbidden;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class RequireAuthenticationTest extends TestCase
{
    public function testLetsAuthenticatedUsersThrough(): void
    {
        $request = $this->request('GET', '/admin')->withAttribute(User::class, self::user());

        self::assertSame(204, $this->process($request)->getStatusCode());
    }

    public function testRedirectsGuestsToLoginKeepingTheTarget(): void
    {
        $response = $this->process($this->request('GET', '/admin/users?page=2'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login?redirect=%2Fadmin%2Fusers%3Fpage%3D2', $response->getHeaderLine('Location'));
    }

    public function testHtmxGuestsGetClientSideRedirect(): void
    {
        $response = $this->process($this->request('GET', '/admin')->withHeader('HX-Request', 'true'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/admin/login?redirect=%2Fadmin', $response->getHeaderLine('HX-Redirect'));
    }

    public function testRejectsGuestPosts(): void
    {
        $this->expectException(Forbidden::class);

        $this->process($this->request('POST', '/admin/users'));
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $router = new Router();
        $router->get('/admin/login', [stdClass::class, 'login'], 'admin.login');
        $middleware = new RequireAuthentication($router, new Responses(new Psr17Factory()));

        return $middleware->process($request, new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(204)));
    }

    private static function user(): User
    {
        return new User(1, 'ann@example.com', '', 'admin', null, UserStatus::Active, null, null, null, new DateTimeImmutable());
    }
}
