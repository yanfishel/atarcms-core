<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\StructuredData\StructuredData;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class StructuredDataTest extends TestCase
{
    public function testTheBlockCannotBeClosedOrCommentedOutByTheTextsItCarries(): void
    {
        $headline = "</script><!-- & \"x\" <b>\u{2028}Тест";

        $script = StructuredData::encode([['@type' => 'Article', 'headline' => $headline, 'url' => 'https://localhost/a/b']]);

        self::assertStringStartsWith('<script type="application/ld+json">', $script);
        self::assertStringEndsWith('</script>', $script);
        $json = substr($script, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString("\u{2028}", $json, 'line terminators stay escaped');
        self::assertStringContainsString('Тест', $json, 'other characters stay readable');
        self::assertStringContainsString('https://localhost/a/b', $json, 'slashes stay unescaped');
        self::assertSame(
            ['@context' => 'https://schema.org', '@graph' => [['@type' => 'Article', 'headline' => $headline, 'url' => 'https://localhost/a/b']]],
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAnEmptyGraphPrintsNothing(): void
    {
        self::assertSame('', StructuredData::encode([]));
    }

    public function testInvalidUtf8IsReplacedInsteadOfFailingThePage(): void
    {
        $script = StructuredData::encode([['@type' => 'Article', 'headline' => "bad \xB1 byte"]]);

        self::assertStringContainsString('bad � byte', $script);
    }

    public function testTimesArePrintedInUtcAsIso8601(): void
    {
        $time = new DateTimeImmutable('2026-01-02 05:04:05', new DateTimeZone('Asia/Jerusalem'));

        self::assertSame('2026-01-02T03:04:05+00:00', StructuredData::time($time));
    }
}
