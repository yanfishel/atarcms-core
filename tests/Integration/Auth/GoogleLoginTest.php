<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Auth;

use AtarCms\Auth\Google\GoogleIdentity;
use AtarCms\Auth\Google\GoogleLogin;
use AtarCms\Auth\Google\GoogleLoginFailed;
use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\UserRepository;
use AtarCms\Database\Connection;
use AtarCms\Kernel\Config;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\FrozenClock;
use PDO;
use PDOStatement;

/**
 * Covers GoogleLogin::resolveUser() against a real, concurrently-modified database, since the bug
 * this guards against (see the fix in GoogleLogin) only manifests under genuine interleaving between
 * the read that decides "this user has no Google account yet" and the write that binds one.
 */
final class GoogleLoginTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
    }

    public function testLosingTheBindRaceIsRejectedInsteadOfTrustingTheStaleUser(): void
    {
        $repository = new UserRepository($this->db, new FrozenClock());
        $user = $repository->create('ann@example.com', 'admin');

        // A PDO that, the moment GoogleLogin issues its "bind this sub" UPDATE, opens a second real
        // connection and wins the race first: it binds a DIFFERENT Google account to the same row.
        // GoogleLogin's own UPDATE (still using the row it read a moment ago, with google_sub still
        // NULL in memory) then runs against the already-changed row and must find 0 rows affected.
        $config = self::config();
        $racingPdo = new class ($config, $user->id) extends PDO {
            private bool $raced = false;

            /**
             * @param array{host: string, port: int, database: string, username: string, password: string} $config
             */
            public function __construct(private readonly array $config, private readonly int $userId)
            {
                parent::__construct(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']),
                    $config['username'],
                    $config['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
                );
            }

            /**
             * @param array<mixed> $options
             */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (!$this->raced && str_contains($query, 'UPDATE users SET google_sub')) {
                    $this->raced = true;
                    Connection::fromConfig($this->config)->execute(
                        'UPDATE users SET google_sub = ? WHERE id = ? AND google_sub IS NULL',
                        ['racer-sub', $this->userId],
                    );
                }

                return parent::prepare($query, $options);
            }
        };

        $login = new GoogleLogin(new UserRepository(new Connection($racingPdo), new FrozenClock()), new Config());

        try {
            $login->resolveUser(new GoogleIdentity('sub-mine', 'ann@example.com', null, 'Ann'));
            self::fail('Expected GoogleLoginFailed');
        } catch (GoogleLoginFailed $e) {
            self::assertSame(LoginFailure::UnknownUser, $e->reason);
        }

        // The row was not clobbered back to null, and stays bound to whoever really won the race.
        self::assertSame('racer-sub', $repository->find($user->id)?->googleSub);
    }
}
