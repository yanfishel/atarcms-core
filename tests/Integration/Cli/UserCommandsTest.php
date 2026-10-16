<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Cli;

use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Cli\Output;
use AtarCms\Database\Migrator;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use RuntimeException;

final class UserCommandsTest extends DatabaseTestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = Application::fromSite(__DIR__ . '/../../Fixtures/site');
        $this->app->container()->service(Migrator::class)->migrate();
    }

    public function testCreateDisableAndLoginLink(): void
    {
        [$code, $out] = $this->console(['user:create', 'Ann@Example.com', '--role=admin', '--name=Ann']);
        self::assertSame(0, $code);
        self::assertSame("Created user #1 ann@example.com (admin).\n", $out);

        [$code, $out] = $this->console(['user:login-link', 'ann@example.com']);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('~^https://localhost/admin/login/link/[a-f0-9]{64}\n~', $out);
        self::assertStringContainsString('expires in 10 minutes', $out);

        [$code, $out] = $this->console(['user:disable', 'ann@example.com']);
        self::assertSame(0, $code);
        self::assertSame("Disabled user ann@example.com.\n", $out);
        self::assertSame(UserStatus::Disabled, $this->app->container()->service(UserRepository::class)->findByEmail('ann@example.com')?->status);

        self::assertSame(
            ['user.created', 'auth.login_link_created', 'user.disabled'],
            array_map(static fn(array $r): string => Cast::string($r['action']), $this->db->select('SELECT action FROM audit_log ORDER BY id')),
        );
    }

    public function testCreateSetsTheSiteDefaultAsTheInterfaceLanguage(): void
    {
        // A CLI-created user would otherwise follow the browser's Accept-Language (users.locale NULL), which on a
        // single-language site reads as a half-translated admin (the 2026-10-16 cold run). The site's default has
        // an admin catalog here (en), so it is saved as the explicit choice; the Profile can change it.
        [$code] = $this->console(['user:create', 'ann@example.com', '--role=admin']);
        self::assertSame(0, $code);

        $user = $this->app->container()->service(UserRepository::class)->findByEmail('ann@example.com');
        self::assertSame('en', $user?->locale);
    }

    public function testCreateLeavesTheLanguageUnchosenWithoutAnAdminCatalog(): void
    {
        // A site default without an admin catalog (uk) cannot be saved: the language stays unchosen and the
        // admin falls back to the browser, as before.
        $app = Application::boot(new Paths(__DIR__ . '/../../Fixtures/site'), ['locales' => ['default' => 'uk', 'locales' => ['uk'], 'admin' => 'separate']]);
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = $app->console()->run(['user:create', 'uk@example.com', '--role=admin'], new Output($stdout, $stdout));
        self::assertSame(0, $code);

        $user = $app->container()->service(UserRepository::class)->findByEmail('uk@example.com');
        self::assertNotNull($user);
        self::assertNull($user->locale);
    }

    public function testCreateReportsValidationErrors(): void
    {
        [$code, , $err] = $this->console(['user:create', 'ann@example.com', '--role=ghost']);

        self::assertSame(1, $code);
        self::assertSame("role: Choose one of the configured roles.\n", $err);
    }

    public function testCreateRequiresRoleOption(): void
    {
        [$code, , $err] = $this->console(['user:create', 'ann@example.com']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Missing option --role=<role>', $err);
    }

    public function testLoginLinkRefusesUnknownAndDisabledUsers(): void
    {
        [$code, , $err] = $this->console(['user:login-link', 'ghost@example.com']);
        self::assertSame(1, $code);
        self::assertStringContainsString('No user with email ghost@example.com', $err);

        $this->console(['user:create', 'ann@example.com', '--role=admin']);
        $this->console(['user:disable', 'ann@example.com']);
        [$code, , $err] = $this->console(['user:login-link', 'ann@example.com']);
        self::assertSame(1, $code);
        self::assertStringContainsString('is disabled', $err);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private function console(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $code = $this->app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
