<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Security;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\Security\CspNonce;
use AtarCms\Http\Security\CspSources;
use AtarCms\Http\Security\SecurityHeadersMiddleware;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsHeadersAndExposesTheNonceToTheRequest(): void
    {
        $seen = null;
        $response = $this->process(new Config(), static function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
            $seen = CspNonce::of($request)->value;

            return (new Psr17Factory())->createResponse();
        });

        self::assertIsString($seen);
        self::assertMatchesRegularExpression('~^[A-Za-z0-9+/]{22}==$~', $seen);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' 'nonce-" . $seen . "'", $csp);
        self::assertStringContainsString(
            "; style-src 'self' 'sha256-I/BcSf79PEn5SD9ob8aupUkHyFwnKqY/qf8Co29iLMs=' 'sha256-nLG3fWB3Qoa5O3UY9xL8RZOTgVvIneH1jRZKc3+2EC4='; style-src-attr 'unsafe-inline'; ",
            $csp,
            'no <style> elements but the two of the colour picker; style attributes apply',
        );
        self::assertSame(1, substr_count($csp, "'unsafe-inline'"), 'only for style attributes');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString('camera=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testNonceDiffersPerRequest(): void
    {
        $middleware = new SecurityHeadersMiddleware(new Config());
        $handler = new CallableHandler(static fn(): ResponseInterface => (new Psr17Factory())->createResponse());
        $request = (new Psr17Factory())->createServerRequest('GET', '/');

        self::assertNotSame(
            $middleware->process($request, $handler)->getHeaderLine('Content-Security-Policy'),
            $middleware->process($request, $handler)->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testKeepsHeadersSetByTheApplication(): void
    {
        $response = $this->process(new Config(), static fn(): ResponseInterface => (new Psr17Factory())->createResponse()
            ->withHeader('Content-Security-Policy', "default-src 'none'"));

        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsOnlyInProduction(): void
    {
        $ok = static fn(): ResponseInterface => (new Psr17Factory())->createResponse();

        self::assertSame('max-age=31536000', $this->process(new Config(), $ok)->getHeaderLine('Strict-Transport-Security'));
        self::assertFalse($this->process(new Config(['app' => ['env' => 'local']]), $ok)->hasHeader('Strict-Transport-Security'));
        self::assertTrue($this->process(new Config(['app' => ['env' => ' Production ']]), $ok)->hasHeader('Strict-Transport-Security'));
    }

    public function testAddsConfiguredFrameImageAndConnectSources(): void
    {
        $config = new Config(['security' => ['csp' => [
            'frame_src' => ['https://maps.example.com', 'https://www.youtube-nocookie.com'],
            'img_src' => ['https://*.tile.openstreetmap.org'],
            'connect_src' => ['https://www.google.com.ua'],
        ]]]);

        $csp = $this->process($config, static fn(): ResponseInterface => (new Psr17Factory())->createResponse())
            ->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com https://maps.example.com;", $csp, 'a host listed again appears once');
        self::assertStringContainsString("img-src 'self' data: blob: https://*.tile.openstreetmap.org", $csp);
        self::assertStringContainsString("; connect-src 'self' https://www.google.com.ua;", $csp);
    }

    public function testConfiguredConnectSourcesMergeWithTheResponsesOwn(): void
    {
        $config = new Config(['security' => ['csp' => ['connect_src' => ['https://www.google.com.ua', 'https://mc.yandex.ru']]]]);

        $csp = $this->process($config, static function (ServerRequestInterface $request): ResponseInterface {
            CspSources::of($request)?->allow('connect-src', 'https://mc.yandex.ru');

            return (new Psr17Factory())->createResponse();
        })->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("; connect-src 'self' https://www.google.com.ua https://mc.yandex.ru;", $csp, 'each source once');
    }

    public function testTheVideoPlayersAndTheMapAreAlwaysAllowedInFrames(): void
    {
        $csp = $this->process(new Config(), static fn(): ResponseInterface => (new Psr17Factory())->createResponse())
            ->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com;", $csp);
    }

    public function testAddsTheSourcesTheResponseAskedFor(): void
    {
        $csp = $this->process(new Config(), static function (ServerRequestInterface $request): ResponseInterface {
            $sources = CspSources::of($request);
            self::assertInstanceOf(CspSources::class, $sources);
            $sources->allow('script-src', 'https://mc.yandex.ru', 'https://yastatic.net');
            $sources->allow('connect-src', 'https://mc.yandex.ru', 'wss://mc.yandex.ru');
            $sources->allow('img-src', 'https://mc.yandex.ru');
            $sources->allow('frame-src', 'https://mc.yandex.ru', 'https://www.google.com');
            $sources->allow('frame-ancestors', 'https://metrika.yandex.ru');
            $sources->allow('script-src', 'https://yastatic.net');

            return (new Psr17Factory())->createResponse();
        })->getHeaderLine('Content-Security-Policy');

        self::assertMatchesRegularExpression("~; script-src 'self' 'nonce-[^']+' https://mc\.yandex\.ru https://yastatic\.net; ~", $csp, 'each source once');
        self::assertStringContainsString("; connect-src 'self' https://mc.yandex.ru wss://mc.yandex.ru; ", $csp);
        self::assertStringContainsString("; img-src 'self' data: blob: https://mc.yandex.ru; ", $csp);
        self::assertStringContainsString("; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com https://mc.yandex.ru; ", $csp);
        self::assertStringEndsWith("frame-ancestors 'self' https://metrika.yandex.ru", $csp);
    }

    public function testWithoutAdditionsThePolicyIsTheSame(): void
    {
        $plain = static fn(): ResponseInterface => (new Psr17Factory())->createResponse();
        $touched = static function (ServerRequestInterface $request): ResponseInterface {
            self::assertFalse(CspSources::of($request)?->any());

            return (new Psr17Factory())->createResponse();
        };
        $strip = static fn(string $csp): string => (string) preg_replace("~'nonce-[^']+'~", '', $csp);

        self::assertSame(
            $strip($this->process(new Config(), $plain)->getHeaderLine('Content-Security-Policy')),
            $strip($this->process(new Config(), $touched)->getHeaderLine('Content-Security-Policy')),
        );
    }

    public function testResetForgetsTheAddedSources(): void
    {
        $sources = new CspSources();
        $sources->allow('img-src', 'https://mc.yandex.ru');

        $sources->reset();

        self::assertFalse($sources->any());
        self::assertSame([], $sources->for('img-src'));
    }

    public function testRefusesUnknownDirectivesAndInjectedSources(): void
    {
        $sources = new CspSources();
        foreach ([['default-src', 'https://x.test'], ['script-src', "'unsafe-inline'"], ['script-src', 'https://x.test; script-src *'], ['img-src', '*']] as [$directive, $source]) {
            try {
                $sources->allow($directive, $source);
                self::fail($directive . ' ' . $source);
            } catch (InvalidArgumentException) {
                self::assertFalse($sources->any());
            }
        }
    }

    public function testRejectsSourcesThatWouldInjectDirectives(): void
    {
        $this->expectException(ConfigException::class);

        new SecurityHeadersMiddleware(new Config(['security' => ['csp' => ['frame_src' => ["https://x.test; script-src *"]]]]));
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    private function process(Config $config, callable $handler): ResponseInterface
    {
        return (new SecurityHeadersMiddleware($config))->process(
            (new Psr17Factory())->createServerRequest('GET', '/'),
            new CallableHandler($handler(...)),
        );
    }
}
