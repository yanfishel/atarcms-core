<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\Http\AuthenticationMiddleware;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\CallableHandler;
use AtarCms\Http\Session\Session;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthenticationMiddlewareTest extends DatabaseTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->users = new UserRepository($this->db, new FrozenClock());
    }

    public function testResolvesActiveUserFromSession(): void
    {
        $user = $this->users->create('ann@example.com', 'admin');

        self::assertSame('ann@example.com', $this->resolve(new Session(str_repeat('a', 64), [LoginService::SESSION_KEY => $user->id])));
    }

    public function testGuestWithoutSessionIsAnonymous(): void
    {
        self::assertSame('guest', $this->resolve(new Session()));
    }

    public function testDisabledOrMissingUserEndsTheSession(): void
    {
        $user = $this->users->create('ann@example.com', 'admin');
        $this->users->setStatus($user->id, UserStatus::Disabled);

        foreach ([$user->id, 999] as $id) {
            $session = new Session(str_repeat('a', 64), [LoginService::SESSION_KEY => $id]);

            self::assertSame('guest', $this->resolve($session));
            self::assertTrue($session->isInvalidated());
        }
    }

    public function testADeletedUsersSessionEnds(): void
    {
        $user = $this->users->create('ann@example.com', 'admin');
        $session = new Session(str_repeat('a', 64), [LoginService::SESSION_KEY => $user->id]);
        $this->users->delete($user->id);

        self::assertSame('guest', $this->resolve($session));
        self::assertTrue($session->isInvalidated());
    }

    private function resolve(Session $session): string
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(Session::class, $session);
        $response = (new AuthenticationMiddleware($this->users))->process($request, new CallableHandler(
            static fn(ServerRequestInterface $r): ResponseInterface => (new Psr17Factory())->createResponse()
                ->withHeader('X-User', CurrentUser::of($r)->email ?? 'guest'),
        ));

        return $response->getHeaderLine('X-User');
    }
}
