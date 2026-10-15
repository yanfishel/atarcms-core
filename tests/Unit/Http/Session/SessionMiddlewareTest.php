<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Session;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Error\ErrorMiddleware;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Route;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Session\Session;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\Http\Session\SessionStorage;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\MemorySessionStorage;
use AtarCms\Tests\Support\Regex;
use Closure;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;
use Throwable;

final class SessionMiddlewareTest extends TestCase
{
    private MemorySessionStorage $storage;

    private FrozenClock $clock;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->storage = new MemorySessionStorage();
        $this->clock = new FrozenClock();
        $this->logger = new MemoryLogger();
    }

    public function testUntouchedSessionCreatesNothing(): void
    {
        $response = $this->send(static fn(Session $session): null => null);

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->storage->sessions);
    }

    public function testWritingStartsASecureSession(): void
    {
        $response = $this->send(static fn(Session $session) => $session->set('a', 1));

        $id = $this->cookieValue($response, '__Host-atar');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
        self::assertStringContainsString('; Path=/; HttpOnly; SameSite=Lax; Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(['a' => 1], $this->storage->sessions[$id]['data']);
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
    }

    public function testLocalPlainHttpUsesRelaxedCookieButHttpsStaysSecure(): void
    {
        $config = new Config(['app' => ['env' => ' Local ']]);
        $write = static fn(Session $session) => $session->set('a', 1);

        $plain = $this->send($write, [], $config, 'http://localhost/');
        $https = $this->send($write, [], $config, 'https://localhost/');

        self::assertStringStartsWith('atar=', $plain->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString('Secure', $plain->getHeaderLine('Set-Cookie'));
        self::assertStringStartsWith('__Host-atar=', $https->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Secure', $https->getHeaderLine('Set-Cookie'));
    }

    public function testLoadsExistingSessionWithoutReissuingTheCookie(): void
    {
        $id = $this->seed(['name' => 'Ann']);

        $seen = null;
        $response = $this->send(static function (Session $session) use (&$seen): void {
            $seen = $session->get('name');
        }, ['__Host-atar' => $id]);

        self::assertSame('Ann', $seen);
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testGarbageOrUnknownCookieNeverBecomesTheSessionId(): void
    {
        foreach (['garbage<script>', str_repeat('a', 64), str_repeat('A', 64), str_repeat('f', 63)] as $cookie) {
            $response = $this->send(static fn(Session $session) => $session->set('a', 1), ['__Host-atar' => $cookie]);

            $cookies = $response->getHeader('Set-Cookie');
            $issued = $this->cookieValue($response, '__Host-atar');
            self::assertNotSame($cookie, $issued, 'attacker-chosen id must not be adopted');
            self::assertCount(1, $cookies);
            self::assertArrayNotHasKey($cookie, $this->storage->sessions);
        }
    }

    public function testUnknownCookieOnUntouchedSessionIsExpired(): void
    {
        $response = $this->send(static fn(Session $session): null => null, ['__Host-atar' => str_repeat('b', 64)]);

        self::assertStringContainsString('__Host-atar=; Path=/; Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
    }

    public function testRegenerateMovesDataToANewId(): void
    {
        $old = $this->seed(['keep' => 'me']);

        $response = $this->send(static fn(Session $session) => $session->regenerate(), ['__Host-atar' => $old]);

        $new = $this->cookieValue($response, '__Host-atar');
        self::assertNotSame($old, $new);
        self::assertArrayNotHasKey($old, $this->storage->sessions);
        self::assertSame(['keep' => 'me'], $this->storage->sessions[$new]['data']);
    }

    public function testInvalidateDestroysSessionAndExpiresCookie(): void
    {
        $id = $this->seed(['user' => 1]);

        $response = $this->send(static fn(Session $session) => $session->invalidate(), ['__Host-atar' => $id]);

        self::assertSame([], $this->storage->sessions);
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    public function testALogoutFollowedByAnErrorStillEndsTheSession(): void
    {
        $id = $this->seed(['user' => 1]);

        $response = $this->send(static function (Session $session): never {
            $session->invalidate();

            throw new RuntimeException('failed after the logout');
        }, ['__Host-atar' => $id]);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('error 500', (string) $response->getBody());
        self::assertSame([], $this->storage->sessions);
        self::assertStringContainsString('__Host-atar=; Path=/; Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
    }

    public function testAnIdChangeFollowedByAnErrorStillMovesTheSession(): void
    {
        $old = $this->seed(['user' => 1]);

        $response = $this->send(static function (Session $session): never {
            $session->regenerate();

            throw new RuntimeException('failed after the login');
        }, ['__Host-atar' => $old]);

        $new = $this->cookieValue($response, '__Host-atar');
        self::assertNotSame($old, $new);
        self::assertArrayNotHasKey($old, $this->storage->sessions);
        self::assertSame(['user' => 1], $this->storage->sessions[$new]['data']);
    }

    public function testErrorPagesOfASessionAreNotStored(): void
    {
        $id = $this->seed(['user' => 1]);

        $response = $this->send(static fn(Session $session): never => throw new NotFound(), ['__Host-atar' => $id]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testAServerErrorInsideTheStackIsLoggedOnce(): void
    {
        $id = $this->seed(['user' => 1]);

        $this->send(static fn(Session $session): never => throw new RuntimeException('boom'), ['__Host-atar' => $id]);

        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
    }

    public function testAGuestWhoseHandlerThrowsGetsNoCookieAndNoStoredSession(): void
    {
        $response = $this->send(static fn(Session $session): never => throw new RuntimeException('boom'));

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->storage->sessions);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'idle below 1' => [['idle_timeout' => 0]];
        yield 'absolute below 1' => [['absolute_timeout' => -1]];
        yield 'idle above absolute' => [['idle_timeout' => 7200, 'absolute_timeout' => 3600]];
    }

    /**
     * @param array<string, int> $session
     */
    #[DataProvider('invalidTimeouts')]
    public function testTimeoutsAreValidated(array $session): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('session.idle_timeout');

        new SessionMiddleware($this->storage, $this->clock, new Config(['session' => $session]), $this->errors());
    }

    public function testIdleTimeoutStartsAFreshSession(): void
    {
        $id = $this->seed(['user' => 1]);
        $this->clock->advance('+7201 seconds');

        $seen = 'unset';
        $this->send(static function (Session $session) use (&$seen): void {
            $seen = $session->get('user');
        }, ['__Host-atar' => $id]);

        self::assertNull($seen);
        self::assertArrayNotHasKey($id, $this->storage->sessions);
    }

    public function testAbsoluteTimeoutEndsEvenAnActiveSession(): void
    {
        $id = $this->seed(['user' => 1]);
        for ($hour = 1; $hour <= 12; $hour++) {
            $this->clock->advance('+1 hour');
            $this->send(static fn(Session $session) => $session->set('hour', $hour), ['__Host-atar' => $id]);
        }
        $this->clock->advance('+1 minute');

        $seen = 'unset';
        $this->send(static function (Session $session) use (&$seen): void {
            $seen = $session->get('user');
        }, ['__Host-atar' => $id]);

        self::assertNull($seen);
    }

    public function testUnchangedSessionIsOnlyTouchedAfterFiveMinutes(): void
    {
        $id = $this->seed(['user' => 1]);
        $lastSeen = $this->storage->sessions[$id]['last_seen'];

        $this->clock->advance('+60 seconds');
        $this->send(static fn(Session $session): null => null, ['__Host-atar' => $id]);
        self::assertSame($lastSeen, $this->storage->sessions[$id]['last_seen']);

        $this->clock->advance('+300 seconds');
        $this->send(static fn(Session $session): null => null, ['__Host-atar' => $id]);
        self::assertSame($this->clock->now()->getTimestamp(), $this->storage->sessions[$id]['last_seen']);
    }

    public function testStatelessRouteNeverTouchesStorageOrCookies(): void
    {
        $storage = new class implements SessionStorage {
            public function read(string $id): ?array
            {
                throw new LogicException('read');
            }

            public function write(string $id, array $payload): void
            {
                throw new LogicException('write');
            }

            public function destroy(string $id): void
            {
                throw new LogicException('destroy');
            }
        };
        $seen = null;

        $response = $this->sendStateless(static function (Session $session) use (&$seen): void {
            $seen = [$session->id(), $session->all()];
        }, ['__Host-atar' => str_repeat('a', 64)], $storage);

        self::assertSame([null, []], $seen);
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testStatelessRouteSendsNoExpiringCookieForAStaleSession(): void
    {
        $response = $this->sendStateless(static fn(Session $session): null => null, ['__Host-atar' => 'stale']);

        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testWritingOnAStatelessRouteIsAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The stateless route "/file" must not write to the session');

        $this->sendStateless(static fn(Session $session) => $session->set('a', 1));
    }

    /**
     * @param array<string, string> $cookies
     */
    private function sendStateless(Closure $action, array $cookies = [], ?SessionStorage $storage = null): ResponseInterface
    {
        $middleware = new SessionMiddleware($storage ?? $this->storage, $this->clock, new Config(), $this->errors());
        $route = (new Route(['GET'], '/file', [stdClass::class, 'file']))->stateless();
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://localhost/file')
            ->withCookieParams($cookies)
            ->withAttribute(RouteMatch::class, new RouteMatch($route, []));

        return $middleware->process($request, new CallableHandler(static function (ServerRequestInterface $request) use ($action): ResponseInterface {
            $action(Session::of($request));

            return (new Psr17Factory())->createResponse();
        }));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function seed(array $data): string
    {
        $id = bin2hex(random_bytes(32));
        $now = $this->clock->now()->getTimestamp();
        $this->storage->sessions[$id] = ['data' => $data, 'created_at' => $now, 'last_seen' => $now];

        return $id;
    }

    /**
     * @param Closure(Session): mixed $action
     * @param array<string, string> $cookies
     */
    private function send(Closure $action, array $cookies = [], ?Config $config = null, string $uri = 'https://localhost/'): ResponseInterface
    {
        $middleware = new SessionMiddleware($this->storage, $this->clock, $config ?? new Config(), $this->errors());
        $request = (new Psr17Factory())->createServerRequest('GET', $uri)->withCookieParams($cookies);

        return $middleware->process($request, new CallableHandler(static function (ServerRequestInterface $request) use ($action): ResponseInterface {
            $action(Session::of($request));

            return (new Psr17Factory())->createResponse();
        }));
    }

    /**
     * Renders every error as "error {status}".
     */
    private function errors(): ErrorMiddleware
    {
        $renderer = new class implements ErrorRenderer {
            public function render(Throwable $error, int $status, ServerRequestInterface $request): string
            {
                return 'error ' . $status;
            }
        };

        return new ErrorMiddleware($renderer, new Responses(new Psr17Factory()), $this->logger);
    }

    private function cookieValue(ResponseInterface $response, string $name): string
    {
        $matches = Regex::match('/^' . preg_quote($name, '/') . '=([^;]*)/', $response->getHeaderLine('Set-Cookie'));

        return $matches[1];
    }
}
