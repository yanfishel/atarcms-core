<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Kernel\Application;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Requests to the fixture site, each through a newly booted application, against freshly migrated tables.
 */
final class LocaleRoutingTest extends FunctionalTestCase
{
    public function testLocalizedPathRoutesToTheSameController(): void
    {
        $response = $this->request('/ru/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<p class="slug">привет</p>', (string) $response->getBody());
    }

    public function testTrailingSlashRedirectKeepsTheLocalePrefix(): void
    {
        $response = $this->request('/ru/news/hello/?x=1');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/ru/news/hello?x=1', $response->getHeaderLine('Location'));
    }

    public function testDefaultLocalePrefixIsCanonicalized(): void
    {
        $response = $this->request('/en/news/hello');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/news/hello', $response->getHeaderLine('Location'));
    }

    public function testUnknownLocalizedPathIsThemed404(): void
    {
        $response = $this->request('/ru/missing');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('class="error-404"', (string) $response->getBody());
    }

    public function testLookAlikePrefixIsNotALocale(): void
    {
        self::assertSame(404, $this->request('/RU/news/hello')->getStatusCode());
        self::assertSame(404, $this->request('/%72u/news/hello')->getStatusCode());
    }

    public function testAdminIsNotLocalizable(): void
    {
        $response = $this->request('/ru/admin');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('class="error-404"', (string) $response->getBody());
        self::assertStringNotContainsString('admin-error', (string) $response->getBody());
        self::assertSame(404, $this->request('/ru/admin/login')->getStatusCode());
        self::assertSame(302, $this->request('/admin')->getStatusCode());
    }

    private function request(string $uri): ResponseInterface
    {
        putenv('APP_DEBUG=0');

        return Application::fromSite(__DIR__ . '/../Fixtures/site')
            ->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }
}
