<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Markdown\Frontmatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrontmatterTest extends TestCase
{
    public function testRendersOneDoubleQuotedValuePerKeyBetweenFences(): void
    {
        self::assertSame(
            "---\ntitle: \"Hello\"\nurl: \"https://example.com/news/hello\"\nlang: \"en\"\n---\n",
            Frontmatter::render(['title' => 'Hello', 'url' => 'https://example.com/news/hello', 'lang' => 'en']),
        );
    }

    #[DataProvider('hostileValues')]
    public function testEveryValueIsOneSafeDoubleQuotedScalar(string $value, string $quoted): void
    {
        self::assertSame($quoted, Frontmatter::quote($value));
        // Every escape used is shared by JSON and YAML double-quoted scalars, so a JSON decoder reads it back.
        self::assertSame(1, preg_match('/^"[^\x00-\x1f\x7f]*"$/', $quoted), 'one line, no control characters');
        self::assertIsString(json_decode($quoted, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'a colon' => ['Note: read me', '"Note: read me"'];
        yield 'quotes and backslashes' => ['Say "hi" \ bye', '"Say \"hi\" \\\\ bye"'];
        yield 'a fence' => ['---', '"---"'];
        yield 'line breaks collapse' => ["One\nTwo\r\n\tThree  ", '"One Two Three"'];
        yield 'leading markers' => ['# - * > | & ! % @ `x`', '"# - * > | & ! % @ `x`"'];
        yield 'unicode and RTL stay readable' => ['שלום — Привет', '"שלום — Привет"'];
        yield 'line and paragraph separators' => ["a\u{2028}b\u{2029}c", '"a b c"'];
        yield 'C1 controls, DEL and a BOM are escaped' => ["a\u{85}b\x7Fc\u{FEFF}d", '"a b\u007fc' . '\\' . 'ufeffd"'];
        yield 'the noncharacters U+FFFE and U+FFFF are escaped' => ["a\u{FFFE}b\u{FFFF}c", '"a' . '\\' . 'ufffeb' . '\\' . 'uffffc"'];
        yield 'invalid UTF-8 is replaced' => ["bad\xC3(byte", "\"bad\u{FFFD}(byte\""];
        yield 'yaml keywords stay strings' => ['yes', '"yes"'];
    }
}
