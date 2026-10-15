<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Menu\MenuUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MenuUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function safeUrls(): iterable
    {
        yield 'https' => ['https://example.com', 'https://example.com'];
        yield 'http with path, query and fragment' => ['HTTP://Example.com/a?b=1#c', 'HTTP://Example.com/a?b=1#c'];
        yield 'root path' => ['/', '/'];
        yield 'site path' => ['/about/team?tab=2#people', '/about/team?tab=2#people'];
        yield 'trimmed' => ["  /about \t", '/about'];
        yield 'anchor' => ['#top', '#top'];
        yield 'bare anchor' => ['#', '#'];
        yield 'mailto' => ['mailto:ann@example.com', 'mailto:ann@example.com'];
        yield 'mailto with a subject' => ['mailto:ann@example.com?subject=Hi', 'mailto:ann@example.com?subject=Hi'];
        yield 'tel' => ['tel:+1-555-0100', 'tel:+1-555-0100'];
        yield 'tel with parentheses' => ['TEL:(555)0100', 'TEL:(555)0100'];
        yield 'short tel' => ['tel:112', 'tel:112'];
        yield 'another short tel' => ['tel:911', 'tel:911'];
        yield 'non-ASCII path' => ['https://example.com/о-нас', 'https://example.com/о-нас'];
        yield 'IDN host' => ['https://пример.рф/', 'https://пример.рф/'];
        yield 'IDN host with path, query and fragment' => ['https://пример.рф/о-нас?q=тест#раздел', 'https://пример.рф/о-нас?q=тест#раздел'];
        yield 'IDN host with a port' => ['https://пример.рф:8080/', 'https://пример.рф:8080/'];
        yield 'non-ASCII site path' => ['/о-нас', '/о-нас'];
    }

    #[DataProvider('safeUrls')]
    public function testAcceptsSafeUrls(string $input, string $expected): void
    {
        self::assertSame($expected, MenuUrl::normalize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case javascript' => ['JaVaScRiPt:alert(1)'];
        yield 'leading space' => [' javascript:alert(1)'];
        yield 'tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'newline inside the scheme' => ["java\nscript:alert(1)"];
        yield 'null byte' => ["/ab\0out"];
        yield 'entity encoded' => ['&#106;avascript:alert(1)'];
        yield 'named entity colon' => ['javascript&colon;alert(1)'];
        yield 'javascript with slashes' => ['javascript://example.com/%0aalert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'protocol relative' => ['//evil.example'];
        yield 'backslash path' => ['/\\evil.example'];
        yield 'backslashes' => ['\\\\evil.example'];
        yield 'https without slashes' => ['https:evil.example'];
        yield 'http without a host' => ['http://'];
        yield 'space in the host' => ['https://exa mple.com'];
        yield 'ftp' => ['ftp://example.com'];
        yield 'relative path' => ['about'];
        yield 'query only' => ['?page=2'];
        yield 'empty mailto' => ['mailto:'];
        yield 'mailto without an address' => ['mailto:someone'];
        yield 'empty tel' => ['tel:'];
        yield 'tel with letters' => ['tel:call-me'];
        yield 'too long' => ['/' . str_repeat('a', MenuUrl::MAX_LENGTH)];
        yield 'invalid utf-8' => ["/caf\xE9"];
        // The IDN and percent-encoding step must never let an unsafe URL through.
        yield 'javascript with an IDN host' => ['javascript://пример.рф/%0aalert(1)'];
        yield 'fullwidth javascript scheme' => ['ｊａｖａｓｃｒｉｐｔ:alert(1)'];
        yield 'javascript with a non-ASCII payload' => ['javascript:alert("о")'];
        yield 'invalid IDN host' => ['https://пример..рф/'];
        yield 'IDN host without slashes' => ['https:пример.рф'];
        yield 'protocol relative IDN host' => ['//пример.рф'];
        yield 'space in an IDN host' => ['https://при мер.рф'];
        yield 'no-break space in the host' => ["https://при\u{00A0}мер.рф"];
        yield 'ideographic space' => ["https://example.com/\u{3000}"];
        yield 'fullwidth solidus mapped into the host' => ['https://ｅｖｉｌ．ｃｏｍ／x'];
        yield 'fullwidth at sign mapped into the host' => ['https://good.example＠evil.example'];
        yield 'right-to-left override' => ["/\u{202E}gpj.exe"];
        yield 'C1 control character' => ["/a\u{0085}b"];
        yield 'byte order mark' => ["\u{FEFF}javascript:alert(1)"];
        yield 'zero width space in the path' => ["/ab\u{200B}out"];
        yield 'zero width space in the host' => ["https://exa\u{200B}mple.com"];
        yield 'soft hyphen' => ["/ab\u{00AD}out"];
        yield 'tel with only a dash' => ['tel:-'];
        yield 'tel with only parentheses' => ['tel:()'];
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsEverythingElse(string $input): void
    {
        self::assertNull(MenuUrl::normalize($input));
    }
}
