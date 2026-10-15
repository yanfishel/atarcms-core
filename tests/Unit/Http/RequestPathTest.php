<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\RequestPath;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestPathTest extends TestCase
{
    public function testDecodesPercentEncoding(): void
    {
        $path = RequestPath::fromRaw('/news/%D0%BF%D1%80%D0%B8');

        self::assertSame('/news/%D0%BF%D1%80%D0%B8', $path->raw);
        self::assertSame('/news/при', $path->decoded);
        self::assertTrue($path->valid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'broken utf-8 sequence' => ['/%C3%28'];
        yield 'lone continuation byte' => ['/%FF'];
        yield 'null byte' => ['/a%00b'];
    }

    #[DataProvider('invalidPaths')]
    public function testFlagsInvalidPaths(string $raw): void
    {
        self::assertFalse(RequestPath::fromRaw($raw)->valid);
    }

    public function testIsUnderMatchesWholeSegments(): void
    {
        self::assertTrue(RequestPath::fromRaw('/admin')->isUnder('/admin'));
        self::assertTrue(RequestPath::fromRaw('/admin/users')->isUnder('/admin/'));
        self::assertTrue(RequestPath::fromRaw('/%61dmin/users')->isUnder('/admin'));
        self::assertFalse(RequestPath::fromRaw('/administrator')->isUnder('/admin'));
        self::assertFalse(RequestPath::fromRaw('/')->isUnder('/admin'));
    }

    public function testOfPrefersTheStoredAttribute(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/other');

        self::assertSame('/other', RequestPath::of($request)->decoded);
        self::assertSame(
            '/stored',
            RequestPath::of($request->withAttribute(RequestPath::class, RequestPath::fromRaw('/stored')))->decoded,
        );
    }

    public function testWithoutPrefixKeepsThePrefixForRedirects(): void
    {
        $path = RequestPath::fromRaw('/ru/news/%D0%BF')->withoutPrefix('/ru');

        self::assertSame('/news/%D0%BF', $path->raw);
        self::assertSame('/news/п', $path->decoded);
        self::assertSame('/ru', $path->prefix);
        self::assertTrue($path->valid);
        self::assertSame('/', RequestPath::fromRaw('/ru')->withoutPrefix('/ru')->raw);
        self::assertSame('', RequestPath::fromRaw('/ru')->prefix);
    }

    public function testWithoutPrefixRequiresAWholeSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RequestPath::fromRaw('/russian')->withoutPrefix('/ru');
    }
}
