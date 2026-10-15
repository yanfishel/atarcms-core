<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\I18n;

use AtarCms\I18n\Bidi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BidiTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function directions(): iterable
    {
        yield 'Hebrew' => ['שלום עולם', true];
        yield 'English' => ['Hello world', false];
        yield 'Russian' => ['Рыбалка', false];
        yield 'leading punctuation and digits are skipped' => ['?2026 מה', true];
        yield 'a Latin word first' => ['GoPro בים', false];
        yield 'no strong character' => ['2026 — 10:30', false];
        yield 'empty' => ['', false];
        yield 'an isolate is skipped' => ["\u{2068}GoPro\u{2069} בים", true];
        yield 'a right-to-left mark' => ["\u{200F}2026", true];
        yield 'Arabic letters' => ['سلام', true];
    }

    #[DataProvider('directions')]
    public function testTheParagraphDirectionIsThatOfTheFirstStrongCharacter(string $text, bool $rtl): void
    {
        self::assertSame($rtl, Bidi::isRtl($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function visualOrders(): iterable
    {
        yield 'pure Hebrew' => ['שלום עולם', 'םלוע םולש'];
        yield 'Hebrew with a Latin word' => ['דיג עם GoPro היום', 'םויה GoPro םע גיד'];
        yield 'Hebrew with a year' => ['דיג באוקה 2026', '2026 הקואב גיד'];
        yield 'Hebrew with a decimal and a time' => ['מבצע 3.5 עד 10:30', '10:30 דע 3.5 עצבמ'];
        yield 'Hebrew with a date' => ['תאריך 04.10.2026', '04.10.2026 ךיראת'];
        // The Unicode algorithm puts a bare plus sign after the number in right-to-left text (browsers do the same).
        yield 'Hebrew with a phone number' => ['טלפון +972 50-123-4567', '50-123-4567 972+ ןופלט'];
        yield 'a phone number after a left-to-right mark' => ["טלפון \u{200E}+972 50-123-4567", '+972 50-123-4567 ןופלט'];
        yield 'Hebrew with parentheses' => ['דיג (חלק 2)', '(2 קלח) גיד'];
        yield 'brackets around a Latin word' => ['צילום [GoPro] חדש', 'שדח [GoPro] םוליצ'];
        yield 'guillemets' => ['מועדון «שקט»', '«טקש» ןודעומ'];
        yield 'the whole title' => ['דיג באוקה 2026 עם GoPro (חלק 2)', '(2 קלח) GoPro םע 2026 הקואב גיד'];
        yield 'an exclamation mark at the end' => ['שלום!', '!םולש'];
        yield 'a question mark at the start' => ['?מה', 'המ?'];
        yield 'an ellipsis at the end' => ['דיג באוקה…', '…הקואב גיד'];
        yield 'an LTR paragraph with a Hebrew word' => ['Fishing in שלום today', 'Fishing in םולש today'];
        yield 'an LTR paragraph with a Hebrew word and a number' => ['Club אבג 2026', 'Club 2026 גבא'];
        yield 'an LTR paragraph keeps its brackets' => ['Trip (שלום) now', 'Trip (םולש) now'];
        yield 'English is unchanged' => ['Fishing on the Oka (part 2): 3.5 kg!', 'Fishing on the Oka (part 2): 3.5 kg!'];
        yield 'Russian is unchanged' => ['Рыбалка на Оке «Тихая заводь»', 'Рыбалка на Оке «Тихая заводь»'];
        yield 'isolates are resolved and not drawn' => ["\u{2068}GoPro 7\u{2069} בים", 'םיב GoPro 7'];
        yield 'an isolate of Hebrew in English' => ["Club \u{2067}שלום 2\u{2069} today", 'Club 2 םולש today'];
        yield 'marks are not drawn' => ["\u{200F}שלום\u{200E}", 'םולש'];
        // shin + qamats + shin dot, lamed, vav + holam, final mem
        yield 'Hebrew points stay after their letter' => [
            "\u{05E9}\u{05B8}\u{05C1}\u{05DC}\u{05D5}\u{05B9}\u{05DD}",
            "\u{05DD}\u{05D5}\u{05B9}\u{05DC}\u{05E9}\u{05B8}\u{05C1}",
        ];
    }

    #[DataProvider('visualOrders')]
    public function testALineIsReorderedForDrawingLeftToRight(string $logical, string $visual): void
    {
        self::assertSame($visual, Bidi::visual($logical));
    }

    public function testTheParagraphDirectionCanBeGiven(): void
    {
        // A wrapped line of a Hebrew title that starts with a Latin word keeps the title's direction.
        self::assertSame('םויה GoPro', Bidi::visual('GoPro היום', true));
        self::assertSame('GoPro םויה', Bidi::visual('GoPro היום', false));
        self::assertSame('2026 – 2025', Bidi::visual('2025 – 2026', true));
    }

    public function testControlsAreStripped(): void
    {
        self::assertSame('GoPro בים', Bidi::strip("\u{2068}GoPro\u{2069} \u{200F}בים\u{202B}\u{202C}"));
        self::assertTrue(Bidi::isControl(0x2068));
        self::assertTrue(Bidi::isControl(0x200E));
        self::assertFalse(Bidi::isControl(0x05D0));
        self::assertFalse(Bidi::isControl(0x20));
    }
}
