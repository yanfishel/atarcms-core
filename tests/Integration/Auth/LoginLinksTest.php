<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\LoginLinks;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;

final class LoginLinksTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private LoginLinks $links;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->clock = new FrozenClock();
        $this->links = new LoginLinks($this->db, $this->clock);
        $this->user = (new UserRepository($this->db, $this->clock))->create('ann@example.com', 'admin');
    }

    public function testStoresOnlyTheHashAndWorksOnce(): void
    {
        $token = $this->links->create($this->user);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame(hash('sha256', $token), $this->db->scalar('SELECT token_hash FROM login_links'));
        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM login_links WHERE token_hash = ?', [$token]));
        self::assertSame($this->user->id, $this->links->consume($token));
        self::assertNull($this->links->consume($token));
        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM login_links'));
    }

    public function testExpiresAfterTenMinutesAndIsDeletedOnUse(): void
    {
        $token = $this->links->create($this->user);
        $this->clock->advance('+601 seconds');

        self::assertNull($this->links->consume($token));
        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM login_links'));
    }

    public function testStillValidJustBeforeExpiry(): void
    {
        $token = $this->links->create($this->user);
        $this->clock->advance('+599 seconds');

        self::assertSame($this->user->id, $this->links->consume($token));
    }

    public function testRejectsMalformedTokensWithoutQuerying(): void
    {
        $token = $this->links->create($this->user);

        self::assertNull($this->links->consume(strtoupper($token)));
        self::assertNull($this->links->consume(substr($token, 0, 63)));
        self::assertNull($this->links->consume('nope'));
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM login_links'));
    }

    public function testCreatingALinkPurgesExpiredOnes(): void
    {
        $this->links->create($this->user);
        $this->clock->advance('+11 minutes');
        $this->links->create($this->user);

        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM login_links'));
    }
}
