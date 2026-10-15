<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\Http\CallableHandler;
use AtarCms\Http\RequestPath;
use AtarCms\Http\Responses;
use AtarCms\I18n\LocaleMiddleware;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LocaleMiddlewareTest extends TestCase
{
    public function testUnprefixedPathUsesTheDefaultLocale(): void
    {
        $response = $this->process('GET', '/news/x');

        self::assertSame('en|0|/news/x|', $response->getHeaderLine('X-Seen'));
    }

    public function testNonDefaultPrefixIsStrippedForTheRouter(): void
    {
        self::assertSame('ru|1|/news/п|/ru', $this->process('GET', '/ru/news/%D0%BF')->getHeaderLine('X-Seen'));
        self::assertSame('ru|1|/|/ru', $this->process('GET', '/ru')->getHeaderLine('X-Seen'));
        self::assertSame('ru|1|/news|/ru', $this->process('POST', '/ru/news')->getHeaderLine('X-Seen'));
    }

    public function testLocaleRootWithTrailingSlashRedirects(): void
    {
        $response = $this->process('GET', '/ru/?page=2');

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/ru?page=2', $response->getHeaderLine('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defaultPrefixRedirects(): iterable
    {
        yield 'page' => ['/en/news/x?a=1', '/news/x?a=1'];
        yield 'bare prefix' => ['/en', '/'];
        yield 'prefix with slash' => ['/en/', '/'];
        yield 'protocol-relative trick' => ['/en//evil.example', '/'];
        yield 'backslash trick' => ['/en/%5Cevil.example', '/'];
    }

    #[DataProvider('defaultPrefixRedirects')]
    public function testDefaultLocalePrefixRedirectsToTheUnprefixedUrl(string $uri, string $location): void
    {
        $response = $this->process('GET', $uri);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
    }

    public function testDefaultLocalePrefixOnPostIsLeftForTheRouter(): void
    {
        self::assertSame('en|0|/en/news|', $this->process('POST', '/en/news')->getHeaderLine('X-Seen'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lookAlikes(): iterable
    {
        yield 'uppercase' => ['/RU/news'];
        yield 'encoded letter' => ['/%72u/news'];
        yield 'longer segment' => ['/russian'];
        yield 'suffixed segment' => ['/ru-x/news'];
        yield 'unknown locale' => ['/fr/news'];
    }

    #[DataProvider('lookAlikes')]
    public function testLookAlikePrefixesAreNotLocales(string $uri): void
    {
        $seen = $this->process('GET', $uri)->getHeaderLine('X-Seen');

        self::assertStringStartsWith('en|0|', $seen);
        self::assertStringEndsWith('|', $seen);
    }

    public function testInvalidUtf8PathIsPassedThrough(): void
    {
        self::assertStringStartsWith('en|0|', $this->process('GET', '/ru%C3%28/x')->getHeaderLine('X-Seen'));
    }

    private function process(string $method, string $uri): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $uri);
        $request = $request->withAttribute(RequestPath::class, RequestPath::of($request));
        $middleware = new LocaleMiddleware(new Locales('en', ['en', 'ru']), new Responses($factory));

        return $middleware->process($request, new CallableHandler(static function (ServerRequestInterface $request) use ($factory): ResponseInterface {
            $locale = RequestLocale::of($request);
            $path = RequestPath::of($request);

            return $factory->createResponse()->withHeader(
                'X-Seen',
                sprintf('%s|%d|%s|%s', $locale->code, (int) $locale->prefixed, $path->decoded, $path->prefix),
            );
        }));
    }
}
