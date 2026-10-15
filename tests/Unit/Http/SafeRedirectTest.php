<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
    public function testKeepsSameOriginPaths(): void
    {
        self::assertSame('/admin/users?page=2#top', SafeRedirect::sanitize('/admin/users?page=2#top', '/admin'));
        self::assertSame('/', SafeRedirect::sanitize('/', '/admin'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsafeTargets(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'backslash trick' => ['/\\evil.example'];
        yield 'absolute url' => ['https://evil.example/'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'encoded slashes' => ['/%2F%2Fevil.example'];
        yield 'header injection' => ["/admin\r\nLocation: https://evil.example"];
        yield 'relative path' => ['admin'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['/admin']];
    }

    #[DataProvider('unsafeTargets')]
    public function testFallsBackForUnsafeTargets(mixed $target): void
    {
        self::assertSame('/admin', SafeRedirect::sanitize($target, '/admin'));
    }
}
