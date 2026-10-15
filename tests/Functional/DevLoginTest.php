<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\SessionMiddleware;

final class DevLoginTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV');
        parent::tearDown();
    }

    public function testLocalLoginPageOffersEveryActiveUser(): void
    {
        $this->bootLocal();
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $bob = $this->createUser('bob@example.com', 'editor');
        $gone = $this->createUser('gone@example.com', 'editor');
        $this->app->container()->service(UserRepository::class)->setStatus($gone->id, UserStatus::Disabled);

        $html = (string) $this->browser->get('/admin/login')->getBody();

        self::assertStringContainsString('action="/admin/login/dev"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="user" value="' . $ann->id . '"', $html);
        self::assertStringContainsString('name="user" value="' . $bob->id . '"', $html);
        self::assertStringNotContainsString('name="user" value="' . $gone->id . '"', $html);
        self::assertStringContainsString('Ann', $html);
        self::assertStringContainsString('bob@example.com', $html);
    }

    public function testLocalDevLoginLogsInAndIsAudited(): void
    {
        $this->bootLocal();
        $user = $this->createUser('ann@example.com');
        $this->browser->get('/admin/login');

        $response = $this->browser->post('/admin/login/dev', ['_csrf' => $this->csrfToken(), 'user' => (string) $user->id]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->browser->get('/admin')->getStatusCode());
        self::assertSame(['auth.login'], $this->auditActions());
        self::assertSame('{"method":"dev"}', $this->db->scalar('SELECT meta FROM audit_log'));
    }

    public function testLocalDevLoginRefusesDisabledAndUnknownUsers(): void
    {
        $this->bootLocal();
        $this->createUser('bob@example.com');
        $user = $this->createUser('ann@example.com');
        $this->app->container()->service(UserRepository::class)->setStatus($user->id, UserStatus::Disabled);
        $this->browser->get('/admin/login');

        foreach ([(string) $user->id, '999', 'x', ''] as $id) {
            $response = $this->browser->post('/admin/login/dev', ['_csrf' => $this->csrfToken(), 'user' => $id]);

            self::assertSame(303, $response->getStatusCode(), $id);
            self::assertStringStartsWith('/admin/login?error=', $response->getHeaderLine('Location'), $id);
        }
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        self::assertSame([], $this->auditActions());
    }

    public function testLocalDevLoginNeedsTheCsrfToken(): void
    {
        $this->bootLocal();
        $user = $this->createUser('ann@example.com');
        $this->browser->get('/admin/login');

        $response = $this->browser->post('/admin/login/dev', ['user' => (string) $user->id]);

        self::assertSame(419, $response->getStatusCode());
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
    }

    public function testProductionHasNoDevLogin(): void
    {
        $user = $this->createUser('ann@example.com');

        $html = (string) $this->browser->get('/admin/login')->getBody();
        self::assertStringNotContainsString('/admin/login/dev', $html);

        $id = bin2hex(random_bytes(32));
        $this->sessions->write($id, ['data' => [CsrfToken::SESSION_KEY => 'token'], 'created_at' => time(), 'last_seen' => time()]);
        $this->browser->setCookie(SessionMiddleware::SECURE_COOKIE, $id);
        $response = $this->browser->post('/admin/login/dev', ['_csrf' => 'token', 'user' => (string) $user->id]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(302, $this->browser->get('/admin')->getStatusCode());
        self::assertSame([], $this->auditActions());
    }

    private function bootLocal(): void
    {
        putenv('APP_ENV=local');
        $this->bootApp();
    }
}
