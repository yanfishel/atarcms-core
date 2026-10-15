<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Demo;

use AtarCms\Demo\LoremText;
use AtarCms\Demo\PlaceholderImages;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class DemoTextTest extends TestCase
{
    public function testEachLanguageGetsTextInItsScript(): void
    {
        $en = self::lorem('en')->sentence();
        $ru = self::lorem('ru')->paragraph(2);
        $he = self::lorem('he')->title();
        $fr = self::lorem('fr-CA')->title();

        self::assertMatchesRegularExpression('/^[A-Z][a-z ,]+\.$/', $en);
        self::assertMatchesRegularExpression('/^[А-Я][А-Яа-яё ,.]+$/u', $ru);
        self::assertMatchesRegularExpression('/^[\x{05D0}-\x{05EA} ]+$/u', $he);
        self::assertMatchesRegularExpression('/^[A-Z][a-z ]+$/', $fr, 'other languages: Latin');
        self::assertDoesNotMatchRegularExpression('/[,.]$/', $he);
        self::assertSame($en, self::lorem('en')->sentence(), 'the same seed gives the same text');
    }

    public function testPlaceholdersAreSizedJpegsWithTheirLabel(): void
    {
        $bytes = (new PlaceholderImages())->jpeg('Card', 1200, 800, 3);
        $info = getimagesizefromstring($bytes);
        $image = imagecreatefromstring($bytes);

        self::assertNotFalse($info);
        self::assertSame([1200, 800, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
        self::assertNotFalse($image);
        $light = 0;
        for ($x = 300; $x < 900; $x += 4) {
            $rgb = imagecolorat($image, $x, 400);
            if (($rgb >> 16 & 0xFF) > 200 && ($rgb >> 8 & 0xFF) > 200) {
                $light++;
            }
        }
        self::assertGreaterThan(5, $light, 'white letters across the middle');
        $corner = imagecolorat($image, 5, 5);
        self::assertEqualsWithDelta(PlaceholderImages::COLOURS[3][0], $corner >> 16 & 0xFF, 4, 'the background colour');
    }

    private static function lorem(string $locale): LoremText
    {
        return new LoremText(new Randomizer(new Xoshiro256StarStar(hash('sha256', 'test', true))), $locale);
    }
}
