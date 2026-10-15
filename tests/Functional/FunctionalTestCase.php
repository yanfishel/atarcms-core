<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Database\Migrator;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\Http\Session\SessionStorage;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Container;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Browser;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\MemorySessionStorage;

/**
 * Full-stack tests against the fixture site with a real database and in-memory sessions
 * (native sessions cannot start once PHPUnit has printed output).
 */
abstract class FunctionalTestCase extends DatabaseTestCase
{
    protected const string SITE = __DIR__ . '/../Fixtures/site';

    protected Application $app;

    protected MemorySessionStorage $sessions;

    protected Browser $browser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new MemorySessionStorage();
        $this->bootApp();
        $this->app->container()->service(Migrator::class)->migrate();
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    /**
     * (Re)builds the application, e.g. after changing environment variables. Sessions survive a reboot.
     */
    protected function bootApp(): void
    {
        putenv('APP_DEBUG=0');
        $this->app = $this->application();
        $this->app->container()->set(SessionStorage::class, $this->sessions);
        $this->configureContainer($this->app->container());
        $this->browser = new Browser($this->app);
    }

    /**
     * The application under test: the fixture site unless a subclass boots another one.
     */
    protected function application(): Application
    {
        return Application::fromSite(static::SITE);
    }

    protected function configureContainer(Container $container): void {}

    /**
     * A regular expression for the sidebar link to $href labelled $label (with its icon), on the current page or not.
     */
    protected static function navLink(string $href, string $label, bool $current = false): string
    {
        return '~<a class="admin-nav-link" href="' . preg_quote($href, '~') . '"' . ($current ? ' aria-current="page"' : '')
            . ' data-rail-title="' . preg_quote($label, '~') . '"><svg class="admin-icon(?: admin-icon-mirror)?" aria-hidden="true"><use href="[^"]+"></use></svg>'
            . '<span class="admin-nav-label">' . preg_quote($label, '~') . '</span></a>~';
    }

    protected function createUser(string $email, string $role = 'admin', string $name = ''): User
    {
        return $this->app->container()->service(UserRepository::class)->create($email, $role, $name);
    }

    /**
     * Gives the browser an authenticated session for $user, as if they had just logged in.
     */
    protected function actingAs(User $user, ?Browser $browser = null): void
    {
        $id = bin2hex(random_bytes(32));
        $now = time();
        $this->sessions->write($id, [
            'data' => [LoginService::SESSION_KEY => $user->id, CsrfToken::SESSION_KEY => bin2hex(random_bytes(32))],
            'created_at' => $now,
            'last_seen' => $now,
        ]);
        ($browser ?? $this->browser)->setCookie(SessionMiddleware::SECURE_COOKIE, $id);
    }

    protected function csrfToken(?Browser $browser = null): string
    {
        $id = ($browser ?? $this->browser)->cookie(SessionMiddleware::SECURE_COOKIE) ?? self::fail('The browser has no session cookie');
        $data = $this->sessions->sessions[$id]['data'] ?? null;
        $token = is_array($data) ? $data[CsrfToken::SESSION_KEY] ?? null : null;

        return is_string($token) ? $token : self::fail('The session has no CSRF token');
    }

    /**
     * @return list<string> audit actions, oldest first
     */
    protected function auditActions(): array
    {
        return array_map(
            static fn(array $row): string => Cast::string($row['action']),
            $this->db->select('SELECT action FROM audit_log ORDER BY id'),
        );
    }
}
