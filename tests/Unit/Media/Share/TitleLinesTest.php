<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media\Share;

use AtarCms\I18n\Bidi;
use AtarCms\Media\Share\ShareFont;
use AtarCms\Media\Share\TitleLines;
use PHPUnit\Framework\TestCase;

final class TitleLinesTest extends TestCase
{
    public function testAShortTitleIsOneLine(): void
    {
        self::assertSame(['Hello world'], TitleLines::wrap('  Hello   world ', ShareFont::path(), 58, 1060));
        self::assertSame([], TitleLines::wrap(" \n ", ShareFont::path(), 58, 1060));
    }

    public function testARussianTitleWrapsAtWordsWithinTheWidth(): void
    {
        $title = 'Рыбалка на Оке в октябре: где клюёт щука и что взять с собой';
        $lines = TitleLines::wrap($title, ShareFont::path(), 58, 1060);

        self::assertCount(2, $lines);
        self::assertSame($title, implode(' ', $lines), 'only whole words, nothing lost');
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(1060, TitleLines::width($line, ShareFont::path(), 58));
        }
        self::assertGreaterThan(1060, TitleLines::width($lines[0] . ' ' . explode(' ', $lines[1])[0], ShareFont::path(), 58), 'greedy: the next word did not fit');
    }

    public function testAtMostFourLinesTheLastEndingInAnEllipsis(): void
    {
        $title = str_repeat('Очень длинный заголовок статьи про рыбалку ', 6);
        $lines = TitleLines::wrap($title, ShareFont::path(), 58, 1060);

        self::assertCount(4, $lines);
        self::assertStringEndsWith('…', $lines[3]);
        self::assertStringEndsNotWith(' …', $lines[3]);
        self::assertLessThanOrEqual(1060, TitleLines::width($lines[3], ShareFont::path(), 58));
        self::assertCount(3, TitleLines::wrap($title, ShareFont::path(), 58, 1060, 3));
    }

    public function testAWordWiderThanTheBoxIsCut(): void
    {
        $lines = TitleLines::wrap('Сверхдлинноесловобезпробеловкотороеневлезаетвстроку и конец', ShareFont::path(), 58, 440);

        self::assertStringEndsWith('…', $lines[0]);
        self::assertLessThanOrEqual(440, TitleLines::width($lines[0], ShareFont::path(), 58));
        self::assertSame('и конец', $lines[1] ?? null);
    }

    public function testCharactersWithoutAGlyphAreNotDrawn(): void
    {
        self::assertSame(['Рыбалка на Оке'], TitleLines::wrap('🎣 Рыбалка 🐟 на Оке', ShareFont::path(), 58, 1060));
        self::assertSame([], TitleLines::wrap('🎣🐟', ShareFont::path(), 58, 1060));
        self::assertSame('Клуб', TitleLines::fit('Клуб ☃', ShareFont::path(), 34, 600));
    }

    public function testLeftToRightLinesAreDrawnAsWrappedFromTheLeft(): void
    {
        $title = 'Рыбалка на Оке в октябре: где клюёт щука и что взять с собой';
        $placed = TitleLines::place($title, ShareFont::path(), 58, 1060);

        self::assertSame(array_map(static fn(string $line): array => [$line, 0], TitleLines::wrap($title, ShareFont::path(), 58, 1060)), $placed);
        self::assertSame([['Hello (world) 2026', 0]], TitleLines::place('Hello (world) 2026', ShareFont::path(), 58, 1060));
    }

    public function testAHebrewTitleIsDrawnInVisualOrderFromTheRight(): void
    {
        $placed = TitleLines::place('דיג באוקה 2026 עם GoPro (חלק 2)', ShareFont::path(), 58, 1060);

        $visual = '(2 קלח) GoPro םע 2026 הקואב גיד';
        self::assertSame([[$visual, 1060 - TitleLines::width($visual, ShareFont::path(), 58)]], $placed);
    }

    public function testEveryWrappedLineKeepsTheDirectionOfTheTitle(): void
    {
        $font = ShareFont::path();
        $width = max(TitleLines::width('שלום עולם', $font, 58), TitleLines::width('GoPro היום', $font, 58)) + 2;

        $placed = TitleLines::place("\u{200F}שלום עולם GoPro היום", $font, 58, $width);

        self::assertSame(['םלוע םולש', 'םויה GoPro'], array_column($placed, 0), 'the second line is not a left-to-right paragraph');
        foreach ($placed as [$line, $offset]) {
            self::assertSame($width - TitleLines::width($line, $font, 58), $offset);
        }
    }

    public function testTheDirectionOfTheLanguageWinsOverTheFirstStrongCharacter(): void
    {
        $font = ShareFont::path();
        $visual = 'האלמ הריקס – GoPro 12';

        self::assertSame([[$visual, 1060 - TitleLines::width($visual, $font, 58)]], TitleLines::place('GoPro 12 – סקירה מלאה', $font, 58, 1060, rtl: true));
        self::assertSame([['םולש world', 0]], TitleLines::place('שלום world', $font, 58, 1060, rtl: false));
    }

    public function testAMaqafDoesNotEndACutLine(): void
    {
        $font = ShareFont::path();
        $width = TitleLines::width('אבג־ דהו', $font, 58) + 2;

        self::assertSame(['אבג…'], TitleLines::wrap('אבג־ דהו זחטיכלמנ', $font, 58, $width, 1));
    }

    public function testBoundaryNeutralsAreNeitherMeasuredNorDrawn(): void
    {
        $font = ShareFont::path();

        self::assertSame(TitleLines::width('Рыбалка', $font, 58), TitleLines::width("Ры\u{00AD}бал\u{200D}ка", $font, 58));
        self::assertSame('Рыбалка', Bidi::strip("Ры\u{00AD}бал\u{200D}ка"));
    }

    public function testACutHebrewTitleEndsWithAnEllipsisOnTheLeft(): void
    {
        $placed = TitleLines::place(str_repeat('כותרת ארוכה מאוד של מאמר על דיג ', 8), ShareFont::path(), 58, 1060);

        self::assertCount(4, $placed);
        self::assertStringStartsWith('…', $placed[3][0]);
        self::assertGreaterThanOrEqual(0, $placed[3][1]);
    }

    public function testDirectionalFormattingCharactersAreNotMeasuredNorDrawn(): void
    {
        $font = ShareFont::path();

        self::assertSame(TitleLines::width('GoPro בים', $font, 58), TitleLines::width("\u{2068}GoPro\u{2069} \u{200F}בים", $font, 58));
        self::assertSame('םיב GoPro', TitleLines::place("\u{2068}GoPro\u{2069} בים", $font, 58, 1060)[0][0]);
        self::assertSame('םולש', Bidi::visual(TitleLines::fit("\u{200F}שלום", $font, 34, 600)));
    }

    public function testOneLineIsCutToTheWidth(): void
    {
        self::assertSame('Клуб', TitleLines::fit('Клуб', ShareFont::path(), 34, 600));
        $cut = TitleLines::fit('Клуб рыбаков «Тихая заводь» на Оке и Волге с 1998 года', ShareFont::path(), 34, 400);
        self::assertStringEndsWith('…', $cut);
        self::assertLessThanOrEqual(400, TitleLines::width($cut, ShareFont::path(), 34));
    }
}
