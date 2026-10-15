<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\Session;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDOException;

final class LoginServiceTest extends DatabaseTestCase
{
    public function testLogInRegeneratesSessionRotatesCsrfAndAudits(): void
    {
        $this->migrateCore();
        $clock = new FrozenClock();
        $users = new UserRepository($this->db, $clock);
        $audit = new AuditLog($this->db, $clock);
        $user = $users->create('ann@example.com', 'admin');
        $session = new Session(str_repeat('a', 64), [CsrfToken::SESSION_KEY => 'old-token']);
        $request = (new Psr17Factory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withAttribute(Session::class, $session);

        (new LoginService($users, $audit))->logIn($request, $user, 'google');

        self::assertTrue($session->needsNewId());
        self::assertSame($user->id, LoginService::userId($session));
        self::assertNotSame('old-token', CsrfToken::get($session));
        self::assertNotNull($users->find($user->id)?->lastLoginAt);
        $entry = $audit->latest(1)[0];
        self::assertSame('auth.login', $entry->action);
        self::assertSame(['method' => 'google'], $entry->meta);
        self::assertSame('203.0.113.7', $entry->ip);
    }

    public function testLogInRefusesADisabledUser(): void
    {
        $this->migrateCore();
        $clock = new FrozenClock();
        $users = new UserRepository($this->db, $clock);
        $audit = new AuditLog($this->db, $clock);
        $user = $users->create('ann@example.com', 'admin');
        $users->setStatus($user->id, UserStatus::Disabled);
        $session = new Session(str_repeat('a', 64));
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(Session::class, $session);

        try {
            (new LoginService($users, $audit))->logIn($request, $users->find($user->id) ?? self::fail('no user'), 'google');
            self::fail('Expected LogicException');
        } catch (LogicException $e) {
            self::assertStringContainsString('disabled', $e->getMessage());
        }
        self::assertNull(LoginService::userId($session));
        self::assertFalse($session->needsNewId());
        self::assertSame([], $audit->latest(1));
    }

    public function testAFailingAuditLeavesTheSessionUntouched(): void
    {
        $this->migrateCore();
        $clock = new FrozenClock();
        $users = new UserRepository($this->db, $clock);
        $audit = new AuditLog($this->db, $clock);
        $user = $users->create('ann@example.com', 'admin');
        $session = new Session(str_repeat('a', 64));
        $request = (new Psr17Factory())->createServerRequest('GET', '/')->withAttribute(Session::class, $session);
        $this->db->execute('DROP TABLE audit_log');

        try {
            (new LoginService($users, $audit))->logIn($request, $user, 'google');
            self::fail('Expected the audit write to fail');
        } catch (PDOException) {
        }

        self::assertNull(LoginService::userId($session));
        self::assertFalse($session->needsNewId());
    }

    public function testLogOutInvalidatesAndAudits(): void
    {
        $this->migrateCore();
        $clock = new FrozenClock();
        $users = new UserRepository($this->db, $clock);
        $audit = new AuditLog($this->db, $clock);
        $user = $users->create('ann@example.com', 'admin');
        $session = new Session(str_repeat('a', 64), [LoginService::SESSION_KEY => $user->id]);
        $request = (new Psr17Factory())->createServerRequest('POST', '/admin/logout')
            ->withAttribute(Session::class, $session)
            ->withAttribute(User::class, $user);

        (new LoginService($users, $audit))->logOut($request);

        self::assertTrue($session->isInvalidated());
        self::assertNull(LoginService::userId($session));
        self::assertSame('auth.logout', $audit->latest(1)[0]->action);
    }
}
