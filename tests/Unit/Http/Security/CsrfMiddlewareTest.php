<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Security;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Exception\CsrfTokenMismatch;
use AtarCms\Http\Routing\RouteMatch;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Security\CsrfMiddleware;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\Session;
use AtarCms\Kernel\Config;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class CsrfMiddlewareTest extends TestCase
{
    public function testSafeMethodsPassWithoutToken(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertSame(204, $this->process($this->request($method, new Session()))->getStatusCode());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodsWithoutTokenAreRejected(string $method): void
    {
        $session = new Session();
        CsrfToken::get($session);

        $this->expectException(CsrfTokenMismatch::class);

        $this->process($this->request($method, $session));
    }

    public function testAcceptsTokenFromFormField(): void
    {
        $session = new Session();
        $request = $this->request('POST', $session)->withParsedBody([CsrfToken::FIELD => CsrfToken::get($session)]);

        self::assertSame(204, $this->process($request)->getStatusCode());
    }

    public function testAcceptsTokenFromHeader(): void
    {
        $session = new Session();
        $request = $this->request('DELETE', $session)->withHeader(CsrfToken::HEADER, CsrfToken::get($session));

        self::assertSame(204, $this->process($request)->getStatusCode());
    }

    public function testRejectsTokenOfAnotherSession(): void
    {
        $session = new Session();
        CsrfToken::get($session);
        $request = $this->request('POST', $session)->withParsedBody([CsrfToken::FIELD => CsrfToken::get(new Session())]);

        $this->expectException(CsrfTokenMismatch::class);

        $this->process($request);
    }

    public function testRejectsPostWhenSessionHasNoToken(): void
    {
        $request = $this->request('POST', new Session())->withParsedBody([CsrfToken::FIELD => '']);

        $this->expectException(CsrfTokenMismatch::class);

        $this->process($request);
    }

    /**
     * A public form has no session: a same-site post passes (the controller checks its signed token), a cross-site
     * one is refused by Sec-Fetch-Site or by an Origin other than the site's.
     */
    public function testAPublicFormChecksWhereThePostComesFrom(): void
    {
        $route = (new Router())->post('/contacts', [stdClass::class, 'send'])->publicForm('contact');
        $public = $this->request('POST', new Session())->withAttribute(RouteMatch::class, new RouteMatch($route, []));
        $config = new Config(['app' => ['url' => 'https://example.com']]);

        self::assertSame(204, $this->process($public, $config)->getStatusCode(), 'no browser hints');
        self::assertSame(204, $this->process($public->withHeader('Sec-Fetch-Site', 'same-origin')->withHeader('Origin', 'https://example.com'), $config)->getStatusCode());
        self::assertSame(204, $this->process($public->withHeader('Origin', 'HTTPS://EXAMPLE.COM'), $config)->getStatusCode());
        self::assertSame(204, $this->process($public->withHeader('Origin', 'https://example.com:443'), $config)->getStatusCode(), 'the default port');
        self::assertSame(204, $this->process($public->withHeader('Origin', 'https://example.com'), new Config(['app' => ['url' => 'https://example.com:443']]))->getStatusCode());
        self::assertSame(204, $this->process($public->withHeader('Origin', 'http://localhost:8080'), new Config(['app' => ['url' => 'http://localhost:8080']]))->getStatusCode());
        foreach ([
            ['Sec-Fetch-Site', 'cross-site'],
            ['Origin', 'https://evil.example'],
            ['Origin', 'http://example.com'],
            ['Origin', 'https://example.com:8443'],
            ['Origin', 'null'],
        ] as [$header, $value]) {
            try {
                $this->process($public->withHeader($header, $value), $config);
                self::fail('Expected a refusal for ' . $header . ': ' . $value);
            } catch (CsrfTokenMismatch) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(CsrfTokenMismatch::class);
        $ordinary = (new Router())->post('/admin/x', [stdClass::class, 'x']);
        $this->process($this->request('POST', new Session())->withAttribute(RouteMatch::class, new RouteMatch($ordinary, [])), $config);
    }

    private function request(string $method, Session $session): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, '/')->withAttribute(Session::class, $session);
    }

    private function process(ServerRequestInterface $request, ?Config $config = null): ResponseInterface
    {
        return (new CsrfMiddleware($config ?? new Config()))->process(
            $request,
            new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse(204)),
        );
    }
}
