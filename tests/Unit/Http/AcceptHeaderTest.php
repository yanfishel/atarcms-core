<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\AcceptHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptHeaderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: float}>
     */
    public static function qualities(): iterable
    {
        yield 'no header accepts everything' => ['', 'text/markdown', 1.0];
        yield 'an exact range' => ['text/markdown', 'text/markdown', 1.0];
        yield 'a missing type' => ['text/html', 'text/markdown', 0.0];
        yield 'the q parameter' => ['text/markdown;q=0.5', 'text/markdown', 0.5];
        yield 'spaces and case' => [' Text/Markdown ; Q=0.7 ', 'text/markdown', 0.7];
        yield 'a type wildcard' => ['text/*;q=0.3', 'text/markdown', 0.3];
        yield 'the full wildcard' => ['*/*;q=0.8', 'text/markdown', 0.8];
        yield 'the most specific range wins' => ['text/markdown;q=0.2, text/*;q=0.9, */*', 'text/markdown', 0.2];
        yield 'the subtype range beats the full wildcard' => ['*/*, text/*;q=0.4', 'text/markdown', 0.4];
        yield 'other parameters are ignored' => ['text/markdown;charset=utf-8;q=0.6', 'text/markdown', 0.6];
        yield 'q=0 refuses' => ['text/markdown;q=0, */*', 'text/markdown', 0.0];
        yield 'a malformed q is ignored with its range' => ['text/markdown;q=high, */*;q=0.1', 'text/markdown', 0.1];
        yield 'a malformed range is ignored' => ['markdown, text/markdown;q=0.9', 'text/markdown', 0.9];
    }

    #[DataProvider('qualities')]
    public function testQuality(string $accept, string $type, float $expected): void
    {
        self::assertSame($expected, AcceptHeader::quality($accept, $type));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function preferences(): iterable
    {
        yield 'markdown only' => ['text/markdown', true];
        yield 'markdown before HTML' => ['text/markdown, text/html;q=0.9', true];
        yield 'markdown with a wildcard fallback' => ['text/markdown, */*;q=0.1', true];
        yield 'a tie goes to HTML' => ['text/markdown, text/html', false];
        yield 'everything' => ['*/*', false];
        yield 'no header' => ['', false];
        yield 'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8', false];
        yield 'HTML first' => ['text/html, text/markdown;q=0.9', false];
        yield 'markdown refused' => ['text/markdown;q=0', false];
        yield 'plain text' => ['text/plain', false];
    }

    #[DataProvider('preferences')]
    public function testPrefersMarkdownOverHtml(string $accept, bool $expected): void
    {
        self::assertSame($expected, AcceptHeader::prefers($accept, 'text/markdown', 'text/html'));
    }
}
