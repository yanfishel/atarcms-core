<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\UserRepository;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;

final class AuditLogTest extends DatabaseTestCase
{
    public function testRecordsAndListsNewestFirstWithUserEmail(): void
    {
        $this->migrateCore();
        $clock = new FrozenClock('2026-09-28 10:00:00');
        $user = (new UserRepository($this->db, $clock))->create('ann@example.com', 'admin');
        $audit = new AuditLog($this->db, $clock);

        $audit->record('auth.login_failed', meta: ['reason' => 'unknown_user', 'email' => 'x@y.z'], ip: '203.0.113.7');
        $clock->advance('+1 minute');
        $audit->record('user.created', $user->id, 'user', $user->id, ['name' => 'Анна <b>']);

        $entries = $audit->latest(10);

        self::assertSame(2, $audit->count());
        self::assertSame('user.created', $entries[0]->action);
        self::assertSame($user->id, $entries[0]->userId);
        self::assertSame('ann@example.com', $entries[0]->userEmail);
        self::assertSame('user', $entries[0]->subjectType);
        self::assertSame((string) $user->id, $entries[0]->subjectId);
        self::assertSame(['name' => 'Анна <b>'], $entries[0]->meta);
        self::assertSame('2026-09-28 10:01:00', $entries[0]->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($entries[1]->userId);
        self::assertNull($entries[1]->userEmail);
        self::assertSame('203.0.113.7', $entries[1]->ip);
        self::assertSame(['reason' => 'unknown_user', 'email' => 'x@y.z'], $entries[1]->meta);
        self::assertCount(1, $audit->latest(1, 1));
    }
}
