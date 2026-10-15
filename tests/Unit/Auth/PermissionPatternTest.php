<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth;

use AtarCms\Auth\PermissionPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionPatternTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'star matches all' => ['*', 'users.manage', true];
        yield 'exact' => ['users.manage', 'users.manage', true];
        yield 'different' => ['users.manage', 'audit.view', false];
        yield 'one-segment wildcard' => ['media.*', 'media.upload', true];
        yield 'wildcard needs same depth' => ['media.*', 'media.upload.big', false];
        yield 'two wildcards' => ['entries.*.*', 'entries.news.publish', true];
        yield 'middle wildcard' => ['entries.*.view', 'entries.pages.view', true];
        yield 'middle wildcard, other action' => ['entries.*.view', 'entries.pages.delete', false];
        yield 'prefix is not enough' => ['entries', 'entries.news.view', false];
    }

    #[DataProvider('cases')]
    public function testMatches(string $pattern, string $permission, bool $expected): void
    {
        self::assertSame($expected, PermissionPattern::matches($pattern, $permission));
    }

    public function testValidatesPatterns(): void
    {
        self::assertTrue(PermissionPattern::isValid('*'));
        self::assertTrue(PermissionPattern::isValid('entries.*.view'));
        self::assertTrue(PermissionPattern::isValid('entries.news_items.edit'));
        self::assertFalse(PermissionPattern::isValid(''));
        self::assertFalse(PermissionPattern::isValid('entries..view'));
        self::assertFalse(PermissionPattern::isValid('Users.Manage'));
        self::assertFalse(PermissionPattern::isValid('entries.news*'));
    }
}
