<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\RequestMemo;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class RequestMemoTest extends TestCase
{
    public function testComputesOncePerKey(): void
    {
        $memo = new RequestMemo();
        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };

        $first = $memo->remember('a', $compute);
        $again = $memo->remember('a', $compute);
        $other = $memo->remember('b', $compute);

        self::assertSame([1, 1, 2], [$first, $again, $other]);
        self::assertSame(2, $calls);
    }

    public function testNullIsMemoised(): void
    {
        $memo = new RequestMemo();
        $calls = 0;
        $compute = static function () use (&$calls): null {
            $calls++;

            return null;
        };

        $memo->remember('x', $compute);
        $memo->remember('x', $compute);

        self::assertSame(1, $calls);
    }

    public function testThroughUsesTheRequestsMemoOrComputesEveryTime(): void
    {
        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };
        $plain = (new Psr17Factory())->createServerRequest('GET', '/');
        $withMemo = $plain->withAttribute(RequestMemo::class, new RequestMemo());

        self::assertNull(RequestMemo::of($plain));
        $memoised = [RequestMemo::through($withMemo, 'k', $compute), RequestMemo::through($withMemo, 'k', $compute)];
        $computed = [RequestMemo::through($plain, 'k', $compute), RequestMemo::through($plain, 'k', $compute)];
        self::assertSame([1, 1], $memoised);
        self::assertSame([2, 3], $computed);
    }
}
