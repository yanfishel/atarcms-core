<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\LoginLinks;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\MemoryLogger;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class LoginLinkTest extends FunctionalTestCase
{
    public function testLinkLogsInAndReplacesAnyExistingSessionId(): void
    {
        $user = $this->createUser('ann@example.com');
        $token = $this->app->container()->service(LoginLinks::class)->create($user);
        $oldId = bin2hex(random_bytes(32));
        $this->sessions->write($oldId, ['data' => ['x' => 1], 'created_at' => time(), 'last_seen' => time()]);
        $this->browser->setCookie(SessionMiddleware::SECURE_COOKIE, $oldId);

        $response = $this->browser->get('/admin/login/link/' . $token);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertNotSame($oldId, $this->browser->cookie(SessionMiddleware::SECURE_COOKIE));
        self::assertArrayNotHasKey($oldId, $this->sessions->sessions);
        self::assertSame(200, $this->browser->get('/admin')->getStatusCode());
        self::assertSame(['auth.login'], $this->auditActions());
        self::assertSame('{"method":"login_link"}', $this->db->scalar('SELECT meta FROM audit_log'));
    }

    public function testUsedExpiredOrGarbledLinksShowFriendlyError(): void
    {
        $logger = new MemoryLogger();
        $this->app->container()->set(LoggerInterface::class, $logger);
        $user = $this->createUser('ann@example.com');
        $links = $this->app->container()->service(LoginLinks::class);
        $used = $links->create($user);
        (new Browser($this->app))->get('/admin/login/link/' . $used);
        $fresh = $links->create($user);
        $expired = bin2hex(random_bytes(32));
        $this->db->insert('login_links', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $expired),
            'expires_at' => new DateTimeImmutable('-1 minute'),
            'created_at' => new DateTimeImmutable('-11 minutes'),
        ]);

        foreach ([$used, $expired, strtoupper($fresh), substr($fresh, 0, 40), 'not-a-token'] as $token) {
            $response = $this->browser->get('/admin/login/link/' . $token);

            self::assertSame(303, $response->getStatusCode(), $token);
            self::assertSame('/admin/login?error=link_invalid', $response->getHeaderLine('Location'), $token);
        }
        self::assertStringContainsString(
            'This login link is invalid, already used or expired.',
            (string) $this->browser->get('/admin/login?error=link_invalid')->getBody(),
        );
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        // Anyone can request garbage links: they are logged, never written to audit_log.
        self::assertSame(['auth.login'], $this->auditActions());
        self::assertCount(5, array_filter($logger->records, static fn(array $record): bool => $record['message'] === 'Invalid login link used'));
    }

    public function testDisabledUserCannotUseALink(): void
    {
        $user = $this->createUser('ann@example.com');
        $token = $this->app->container()->service(LoginLinks::class)->create($user);
        $this->app->container()->service(UserRepository::class)->setStatus($user->id, UserStatus::Disabled);

        $response = $this->browser->get('/admin/login/link/' . $token);

        self::assertSame('/admin/login?error=disabled', $response->getHeaderLine('Location'));
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        self::assertSame(['auth.login_failed'], $this->auditActions());
    }
}
