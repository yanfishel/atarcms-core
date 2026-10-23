<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional;

use AtarCms\Kernel\Application;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Requests to the fixture site, each through a newly booted application, against freshly migrated tables.
 */
final class LocalizedViewTest extends FunctionalTestCase
{
    private const string NEWS = '/news/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82';

    public function testDefaultLocalePage(): void
    {
        $body = (string) $this->request('/')->getBody();

        self::assertStringContainsString('<html lang="en">', $body);
        self::assertStringContainsString('<p class="greeting">Hello</p>', $body);
        self::assertStringContainsString('href="' . self::NEWS . '"', $body);
    }

    public function testPrefixedLocalePageTranslatesAndLinksWithinTheLocale(): void
    {
        $body = (string) $this->request('/ru')->getBody();

        self::assertStringContainsString('<html lang="ru">', $body);
        self::assertStringContainsString('<p class="greeting">Привет</p>', $body);
        self::assertStringContainsString('href="/ru' . self::NEWS . '"', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="en" href="https://localhost/">', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://localhost/ru">', $body);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://localhost/">', $body);
    }

    public function testAlternatesKeepEncodedRouteParams(): void
    {
        $body = (string) $this->request('/ru' . self::NEWS)->getBody();

        self::assertStringContainsString('hreflang="en" href="https://localhost' . self::NEWS . '"', $body);
        self::assertStringContainsString('hreflang="ru" href="https://localhost/ru' . self::NEWS . '"', $body);
    }

    public function testErrorPagesAreLocalizedButHaveNoAlternates(): void
    {
        $response = $this->request('/ru/missing');
        $body = (string) $response->getBody();

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<html lang="ru">', $body);
        self::assertStringNotContainsString('hreflang', $body);
    }

    private function request(string $uri): ResponseInterface
    {
        putenv('APP_DEBUG=0');

        return Application::fromSite(__DIR__ . '/../Fixtures/site')
            ->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }
}
