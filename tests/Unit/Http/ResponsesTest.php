<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\Responses;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ResponsesTest extends TestCase
{
    public function testHtml(): void
    {
        $response = (new Responses(new Psr17Factory()))->html('<p>Привет</p>', 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<p>Привет</p>', (string) $response->getBody());
    }

    public function testText(): void
    {
        $response = (new Responses(new Psr17Factory()))->text('plain');

        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('plain', (string) $response->getBody());
    }

    public function testMarkdown(): void
    {
        $response = (new Responses(new Psr17Factory()))->markdown("# Title\n");

        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame("# Title\n", (string) $response->getBody());
    }

    public function testJson(): void
    {
        $response = (new Responses(new Psr17Factory()))->json(['a' => '<b>&"\'', 'b' => 'я/'], 422);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"a":"\u003Cb\u003E\u0026\u0022\u0027","b":"я/"}', (string) $response->getBody());
    }

    public function testRedirect(): void
    {
        $response = (new Responses(new Psr17Factory()))->redirect('/news', 301);

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/news', $response->getHeaderLine('Location'));
    }

    public function testXml(): void
    {
        $response = (new Responses(new Psr17Factory()))->xml('<rss/>', 'application/rss+xml');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/rss+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<rss/>', (string) $response->getBody());
    }
}
