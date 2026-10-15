<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\LoginLinks;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Settings\AdminAccent;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;
use PDOException;

final class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $users;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->clock = new FrozenClock('2026-09-28 10:00:00');
        $this->users = new UserRepository($this->db, $this->clock);
    }

    public function testCreatesAndFindsUsersWithNormalizedEmail(): void
    {
        $user = $this->users->create('  Ann@Example.COM ', 'admin', ' Ann ');

        self::assertSame('ann@example.com', $user->email);
        self::assertSame('Ann', $user->name);
        self::assertSame('admin', $user->role);
        self::assertSame(UserStatus::Active, $user->status);
        self::assertTrue($user->isActive());
        self::assertNull($user->googleSub);
        self::assertNull($user->lastLoginAt);
        self::assertSame('2026-09-28 10:00:00', $user->createdAt->format('Y-m-d H:i:s'));
        self::assertEquals($user, $this->users->find($user->id));
        self::assertEquals($user, $this->users->findByEmail('ANN@example.com'));
        self::assertNull($this->users->find(999));
    }

    public function testDeleteRemovesTheUserTheirLoginLinksAndKeepsTheirAuditRowsWithoutThem(): void
    {
        $ann = $this->users->create('ann@example.com', 'admin');
        $bob = $this->users->create('bob@example.com', 'editor');
        $links = new LoginLinks($this->db, $this->clock);
        $links->create($ann);
        $links->create($bob);
        $audit = new AuditLog($this->db, $this->clock);
        $audit->record('auth.login', $ann->id, 'user', $ann->id, [], null);

        $this->users->delete($ann->id);

        self::assertNull($this->users->find($ann->id));
        self::assertNotNull($this->users->find($bob->id));
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM login_links'));
        self::assertSame($bob->id, $this->db->scalar('SELECT user_id FROM login_links'));
        $row = $audit->latest(1)[0];
        self::assertNull($row->userId, 'the audit row stays without its actor');
        self::assertSame((string) $ann->id, $row->subjectId);
    }

    public function testEmailIsUnique(): void
    {
        $this->users->create('ann@example.com', 'admin');

        $this->expectException(PDOException::class);

        $this->users->create('ANN@example.com', 'editor');
    }

    public function testBindsGoogleSubOnlyOnce(): void
    {
        $user = $this->users->create('ann@example.com', 'admin');

        self::assertTrue($this->users->bindGoogleSub($user->id, 'sub-1'));
        self::assertFalse($this->users->bindGoogleSub($user->id, 'sub-2'));
        self::assertSame('sub-1', $this->users->findByGoogleSub('sub-1')?->googleSub);
        self::assertNull($this->users->findByGoogleSub('sub-2'));
    }

    public function testUpdatesStatusRoleNameAndLogin(): void
    {
        $user = $this->users->create('ann@example.com', 'admin');
        $this->users->update($user->id, 'Anna', 'editor', UserStatus::Disabled);
        $this->clock->advance('+1 hour');
        $this->users->recordLogin($user->id);

        $updated = $this->users->find($user->id);

        self::assertNotNull($updated);
        self::assertSame('Anna', $updated->name);
        self::assertSame('editor', $updated->role);
        self::assertFalse($updated->isActive());
        self::assertSame('2026-09-28 11:00:00', $updated->lastLoginAt?->format('Y-m-d H:i:s'));

        $this->users->setStatus($user->id, UserStatus::Active);
        self::assertTrue($this->users->find($user->id)?->isActive());
    }

    public function testListsUsersByEmailAndUsesNameForDisplay(): void
    {
        $this->users->create('zed@example.com', 'author', 'Zed');
        $this->users->create('ann@example.com', 'admin');

        $all = $this->users->all();

        self::assertSame(['ann@example.com', 'zed@example.com'], array_map(static fn($u): string => $u->email, $all));
        self::assertSame('ann@example.com', $all[0]->displayName());
        self::assertSame('Zed', $all[1]->displayName());
    }

    public function testDisplayNames(): void
    {
        $ann = $this->users->create('ann@example.com', 'admin', 'Ann');
        $bob = $this->users->create('bob@example.com', 'editor');

        self::assertSame([$ann->id => 'Ann', $bob->id => 'bob@example.com'], $this->users->displayNames([$bob->id, $ann->id, 999]));
        self::assertSame([], $this->users->displayNames([]));
    }

    public function testStoresTheInterfaceLanguage(): void
    {
        $ann = $this->users->create('ann@example.com', 'admin');
        self::assertNull($ann->locale, 'no choice means the site default');

        $this->users->setLocale($ann->id, 'ru');
        self::assertSame('ru', $this->users->find($ann->id)?->locale);
        self::assertSame('ru', $this->users->findByEmail('ann@example.com')?->locale);

        $this->users->setLocale($ann->id, null);
        self::assertNull($this->users->find($ann->id)?->locale);
    }

    public function testStoresTheAdminColour(): void
    {
        $ann = $this->users->create('ann@example.com', 'admin');
        self::assertNull($ann->accent, 'no choice follows the site colour');

        $this->users->setAccent($ann->id, AdminAccent::Violet);
        self::assertSame(AdminAccent::Violet, $this->users->find($ann->id)?->accent);

        $this->users->setAccent($ann->id, null);
        self::assertNull($this->users->find($ann->id)?->accent);

        $this->db->execute("UPDATE users SET accent = 'pink' WHERE id = ?", [$ann->id]);
        self::assertNull($this->users->find($ann->id)?->accent, 'an unknown stored value follows the site colour');
    }
}
