<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Admin\AdminErrorLocale;
use AtarCms\Admin\InterfaceLanguage;
use AtarCms\Admin\LanguageMode;
use AtarCms\Admin\LinkedLanguage;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Database\Connection;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\SystemClock;
use AtarCms\Settings\AdminAccent;
use DateTimeImmutable;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final class AdminErrorLocaleTest extends TestCase
{
    public function testTheUserOnTheRequestChoosesTheLanguage(): void
    {
        $request = new ServerRequest('GET', '/admin/x')->withAttribute(User::class, self::user('en'));

        self::assertSame('en', self::rendered($request, static fn(): UserRepository => throw new LogicException('not needed')));
    }

    public function testAGuestWithoutABrowserLanguageKeepsTheRequestLanguage(): void
    {
        $never = static fn(): UserRepository => throw new LogicException('no session, no lookup');

        self::assertNull(self::rendered(new ServerRequest('GET', '/admin/x'), $never));
        self::assertNull(self::rendered(new ServerRequest('GET', '/admin/x')->withAttribute(Session::class, new Session()), $never), 'a guest session');
    }

    /**
     * A signed-in user's error page speaks what the user's admin pages speak (InterfaceLanguage::of()): without a choice
     * or a browser language the site default when the admin has it.
     */
    public function testASignedInUserGetsTheLanguageOfTheAdminPages(): void
    {
        $never = static fn(): UserRepository => throw new LogicException('no session, no lookup');

        self::assertSame('ru', self::rendered(new ServerRequest('GET', '/admin/x')->withAttribute(User::class, self::user(null)), $never), 'the site default');
        self::assertSame('ru', self::rendered(new ServerRequest('GET', '/admin/x')->withAttribute(User::class, self::user('it')), $never), 'not an interface language');
    }

    public function testWithoutAChoiceTheBrowserLanguageIsUsed(): void
    {
        $never = static fn(): UserRepository => throw new LogicException('no session, no lookup');
        $browser = new ServerRequest('GET', '/admin/x', ['Accept-Language' => 'en-GB, ru;q=0.5']);

        self::assertSame('en', self::rendered($browser->withAttribute(User::class, self::user(null)), $never));
        self::assertSame('en', self::rendered($browser, $never), 'a guest too');
        self::assertSame('ru', self::rendered($browser->withAttribute(User::class, self::user('ru')), $never), 'a saved choice wins');
    }

    public function testADatabaseFailureKeepsTheRequestLanguage(): void
    {
        $session = new Session();
        $session->set(LoginService::SESSION_KEY, 5);
        $request = new ServerRequest('GET', '/admin/x')->withAttribute(Session::class, $session);

        self::assertNull(self::rendered($request, static fn(): UserRepository => throw new RuntimeException('database down')));
    }

    public function testTheSignedInUserOfTheSessionChoosesTheLanguage(): void
    {
        $users = self::users([[5, 'active', 'en'], [6, 'disabled', 'en']]);
        $seen = null;

        self::assertSame('en', self::rendered(self::signedIn(5), static fn(): UserRepository => $users, $seen));
        self::assertInstanceOf(RequestLocale::class, $seen);
        self::assertFalse($seen->prefixed, 'the language comes from the user, not from a URL prefix');
        self::assertNull(self::rendered(self::signedIn(6), static fn(): UserRepository => $users), 'a disabled user chooses nothing');
        self::assertNull(self::rendered(self::signedIn(7), static fn(): UserRepository => $users), 'a user who no longer exists');
    }

    public function testTheSignedInUsersAdminColourIsPassedOn(): void
    {
        $users = self::users([[5, 'active', 'en', 'teal'], [6, 'active', 'en']]);
        $inner = new class implements ErrorRenderer {
            public mixed $accent = 'unset';

            public function render(Throwable $error, int $status, ServerRequestInterface $request): string
            {
                $this->accent = $request->getAttribute(AdminAccent::class);

                return 'page';
            }
        };
        $renderer = new AdminErrorLocale($inner, static fn(): UserRepository => $users, new InterfaceLanguage(new Locales('ru', ['ru', 'en'])), new LinkedLanguage(LanguageMode::Separate, new Locales('ru', ['ru', 'en'])));

        $renderer->render(new RuntimeException('x'), 404, self::signedIn(5));
        self::assertSame(AdminAccent::Teal, $inner->accent);
        $renderer->render(new RuntimeException('x'), 404, self::signedIn(6));
        self::assertNull($inner->accent, 'no choice: the site colour');
        $renderer->render(new RuntimeException('x'), 404, new ServerRequest('GET', '/admin/x'));
        self::assertNull($inner->accent, 'a guest');
    }

    /**
     * @param \Closure(): UserRepository $users
     * @param-out RequestLocale|null $seen
     *
     * @return string|null the locale the inner renderer saw on the request, null for none
     */
    private static function rendered(ServerRequestInterface $request, \Closure $users, ?RequestLocale &$seen = null): ?string
    {
        $inner = new class implements ErrorRenderer {
            public ?RequestLocale $locale = null;

            public function render(Throwable $error, int $status, ServerRequestInterface $request): string
            {
                $locale = $request->getAttribute(RequestLocale::class);
                $this->locale = $locale instanceof RequestLocale ? $locale : null;

                return 'page';
            }
        };

        self::assertSame('page', new AdminErrorLocale($inner, $users, new InterfaceLanguage(new Locales('ru', ['ru', 'en'])), new LinkedLanguage(LanguageMode::Separate, new Locales('ru', ['ru', 'en'])))->render(new RuntimeException('x'), 404, $request));
        $seen = $inner->locale;

        return $seen?->code;
    }

    private static function signedIn(int $id): ServerRequestInterface
    {
        $session = new Session();
        $session->set(LoginService::SESSION_KEY, $id);

        return new ServerRequest('GET', '/admin/x')->withAttribute(Session::class, $session);
    }

    /**
     * A real repository over an in-memory SQLite table with the columns it reads.
     *
     * @param list<array{0: int, 1: string, 2: string, 3?: string}> $rows id, status, locale, accent
     */
    private static function users(array $rows): UserRepository
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, role TEXT, google_sub TEXT, status TEXT, locale TEXT, content_locale TEXT, last_login_at TEXT, created_at TEXT, avatar_version TEXT, accent TEXT)');
        $insert = $pdo->prepare("INSERT INTO users VALUES (?, ?, 'Ann', 'admin', NULL, ?, ?, NULL, NULL, '2026-09-28 12:00:00', NULL, ?)");
        foreach ($rows as $row) {
            [$id, $status, $locale] = $row;
            $insert->execute([$id, 'user' . $id . '@example.com', $status, $locale, $row[3] ?? null]);
        }

        return new UserRepository(new Connection($pdo), new SystemClock());
    }

    private static function user(?string $locale): User
    {
        return new User(5, 'ann@example.com', 'Ann', 'admin', null, UserStatus::Active, $locale, null, null, new DateTimeImmutable('2026-09-28'));
    }
}
