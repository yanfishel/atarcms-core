<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media\Share;

use AtarCms\Media\Share\FontGlyphs;
use AtarCms\Media\Share\ShareFont;
use PHPUnit\Framework\TestCase;

final class FontGlyphsTest extends TestCase
{
    public function testTheFontsCharacterMap(): void
    {
        foreach (['A', 'z', 'Ж', 'ё', '…', '«', '»', '—', '№', '€', ':'] as $char) {
            self::assertTrue(FontGlyphs::has(ShareFont::path(), mb_ord($char)), $char);
        }
        foreach (["\u{1F3A3}", "\u{1F41F}", '中', '☃', "\u{E000}", "\u{FE0F}"] as $char) {
            self::assertFalse(FontGlyphs::has(ShareFont::path(), mb_ord($char)), bin2hex($char));
        }
    }

    public function testCharactersWithoutAGlyphAreLeftOut(): void
    {
        self::assertSame('Рыбалка на Оке', FontGlyphs::clean("🎣 Рыбалка 🐟\u{FE0F} на  Оке ☃", ShareFont::path()));
        self::assertSame('', FontGlyphs::clean('🎣🐟 中', ShareFont::path()));
        self::assertSame('Plain «text» — №1…', FontGlyphs::clean('Plain «text» — №1…', ShareFont::path()));
    }

    public function testGereshAndGershayimFallBackToQuotes(): void
    {
        self::assertFalse(FontGlyphs::has(ShareFont::path(), 0x05F3), 'Open Sans lacks the geresh');
        self::assertFalse(FontGlyphs::has(ShareFont::path(), 0x05F4), 'and the gershayim');

        self::assertSame('צה"ל מס\' 5', FontGlyphs::clean('צה״ל מס׳ 5', ShareFont::path()));
    }

    public function testBoundaryNeutralsAreDroppedWithoutASpace(): void
    {
        self::assertSame('Рыбалка', FontGlyphs::clean("Ры\u{00AD}бал\u{200D}ка", ShareFont::path()));
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        self::assertSame('Рыба?', FontGlyphs::clean("Рыба\xFF", ShareFont::path()));
    }

    public function testDirectionalFormattingCharactersAreKeptForTheBidiStepButAloneAreNothing(): void
    {
        self::assertTrue(FontGlyphs::has(ShareFont::path(), mb_ord('ש')), 'Open Sans has Hebrew');
        self::assertSame("\u{2068}GoPro\u{2069} בים", FontGlyphs::clean("\u{2068}GoPro\u{2069} 🎣 בים", ShareFont::path()));
        self::assertSame('', FontGlyphs::clean("\u{200F} \u{2068}🎣\u{2069}", ShareFont::path()));
    }
}
