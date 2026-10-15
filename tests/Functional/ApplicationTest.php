<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Tests\Support\Regex;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ApplicationTest extends TestCase
{
    private const string SITE = __DIR__ . '/../Fixtures/site';

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    public function testRendersHomeThroughThemeLayout(): void
    {
        $response = $this->request('GET', '/');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Home &lt;b&gt;</title>', $body);
        self::assertStringContainsString('<h1>Home &lt;b&gt;</h1>', $body);
        self::assertMatchesRegularExpression('~/theme-assets/app\.css\?v=[0-9a-f]{12}~', $body);
        self::assertStringContainsString('href="/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82"', $body);
    }

    public function testRoutesPercentEncodedSlug(): void
    {
        $response = $this->request('GET', '/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p class="slug">привет</p>', (string) $response->getBody());
    }

    public function testRedirectsTrailingSlash(): void
    {
        $response = $this->request('GET', '/news/hello/');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/news/hello', $response->getHeaderLine('Location'));
    }

    public function testRendersThemed404(): void
    {
        $response = $this->request('GET', '/missing');
        $body = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('class="error-404"', $body);
        self::assertStringContainsString('<title>Not found</title>', $body);
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->request('OPTIONS', '/');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('class="error-generic"', (string) $response->getBody());
    }

    public function testPostWithoutCsrfTokenIsRejectedWith419(): void
    {
        $response = $this->request('POST', '/');

        self::assertSame(419, $response->getStatusCode());
        self::assertStringContainsString('419 Page Expired', (string) $response->getBody());
    }

    public function testServerErrorHidesDetailsAndLogsThem(): void
    {
        $response = $this->request('GET', '/boom');
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('class="error-generic"', $body);
        self::assertStringNotContainsString('Kaboom', $body);
        $log = (string) file_get_contents(self::SITE . '/storage/logs/app-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString('Kaboom <script>', $log);
    }

    public function testDebugModeShowsEscapedDetails(): void
    {
        $response = $this->request('GET', '/boom', debug: true);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Kaboom &lt;script&gt;', (string) $response->getBody());
    }

    public function testConsoleListsCoreCommands(): void
    {
        $stdout = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');
        $stderr = fopen('php://memory', 'w+') ?: throw new RuntimeException('stream');

        $code = $this->app(false)->console()->run([], new Output($stdout, $stderr));

        rewind($stdout);
        $listing = (string) stream_get_contents($stdout);
        self::assertSame(0, $code);
        self::assertStringContainsString('migrate:status', $listing);
        self::assertStringContainsString('Apply pending database migrations', $listing);
    }

    public function testInvalidUtf8PathRendersThemed404(): void
    {
        $response = $this->request('GET', '/news/%C3%28');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('class="error-404"', (string) $response->getBody());
    }

    public function testErrorResponsesCarrySecurityHeaders(): void
    {
        $response = $this->request('GET', '/missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testInlineScriptNonceMatchesTheHeader(): void
    {
        $response = $this->request('GET', '/');

        $matches = Regex::match("~'nonce-([^']+)'~", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<script nonce="' . $matches[1] . '">', (string) $response->getBody());
    }

    private function app(bool $debug): Application
    {
        putenv('APP_DEBUG=' . ($debug ? '1' : '0'));

        return Application::fromSite(self::SITE);
    }

    private function request(string $method, string $uri, bool $debug = false): ResponseInterface
    {
        return $this->app($debug)->handle((new Psr17Factory())->createServerRequest($method, $uri));
    }
}
