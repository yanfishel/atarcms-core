<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

final class AuthenticationTest extends FunctionalTestCase
{
    public function testAuthenticatedUserReachesProtectedRoute(): void
    {
        $this->actingAs($this->createUser('ann@example.com'));

        $response = $this->browser->get('/whoami');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ann@example.com', (string) $response->getBody());
    }

    public function testPermissionGuardUsesTheUsersRole(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));
        self::assertSame(403, $this->browser->get('/secret')->getStatusCode());

        $this->actingAs($this->createUser('ann@example.com', 'admin'));
        self::assertSame(200, $this->browser->get('/secret')->getStatusCode());
    }
}
