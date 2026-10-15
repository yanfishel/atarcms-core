<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\View;

use AtarCms\View\CssValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CssValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function colours(): iterable
    {
        foreach (['#fff', '#FFFA', '#e64d4d', '#3A7BD580', 'red', 'RebeccaPurple', 'transparent', 'currentColor'] as $colour) {
            yield $colour => [$colour, $colour];
        }
        yield 'rgb(10, 20, 30)' => ['rgb(10, 20, 30)', 'rgb(10,20,30)'];
        yield 'rgba(10,20,30,0.5)' => ['rgba(10,20,30,0.5)', 'rgba(10,20,30,0.5)'];
        yield 'rgb(10% 20% 30%)' => ['rgb(10% 20% 30%)', 'rgb(10% 20% 30%)'];
        yield 'rgb(10 20 30 / 50%)' => ['rgb( 10 20 30 / 50% )', 'rgb(10 20 30 / 50%)'];
        yield 'RGB(1, 2, 3)' => ['RGB(1, 2, 3)', 'RGB(1,2,3)'];
        yield 'hsl(0, 0%, 90%)' => ['hsl(0, 0%, 90%)', 'hsl(0,0%,90%)'];
        yield 'hsla(120deg, 75%, 60%, 0.25)' => ['hsla(120deg, 75%, 60%, 0.25)', 'hsla(120deg,75%,60%,0.25)'];
        yield 'hsl(210 50% 40%)' => ['hsl(210 50% 40%)', 'hsl(210 50% 40%)'];
        yield 'hsl(210 50% 40% / 0.5)' => ['hsl(210 50% 40% / 0.5)', 'hsl(210 50% 40% / 0.5)'];
        yield 'HSL(0 , 0%,0%)' => ['HSL(0 , 0%,0%)', 'HSL(0,0%,0%)'];
    }

    /**
     * Colour functions are stored without spaces around commas, as CKEditor reads them back, so a second save
     * changes nothing.
     */
    #[DataProvider('colours')]
    public function testAcceptsColours(string $colour, string $stored): void
    {
        self::assertTrue(CssValue::isColor($colour), $colour);
        self::assertSame($stored, CssValue::normalize('color', $colour));
        self::assertSame($stored, CssValue::normalize('color', $stored));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notColours(): iterable
    {
        foreach (['#ff', '#fffff', '#ggg', 'reddish', 'rgb(1, 2)', 'rgb(1, 2, 3, 4, 5)', 'hsl(0, 0, 0)', 'hsl(0deg 0% 0%, 1)', 'color-mix(in srgb, red, blue)', 'var(--x)', '"red"', 'red blue', 'rgb(1,2,3) ', '', 'inherit'] as $value) {
            yield $value === '' ? 'empty' : $value => [$value];
        }
        // The patterns are $-anchored with the D modifier: a trailing newline is not part of a colour.
        yield 'hex and a newline' => ["#fff\n"];
        yield 'rgb() and a newline' => ["rgb(1,2,3)\n"];
        // Names are matched against the list of named colours, not a pattern: a trailing newline is no name either.
        yield 'a name and a newline' => ["red\n"];
    }

    #[DataProvider('notColours')]
    public function testRefusesEverythingElseAsAColour(string $value): void
    {
        self::assertFalse(CssValue::isColor($value), $value);
    }

    public function testKnowsTheNamedColours(): void
    {
        self::assertCount(148, CssValue::NAMED_COLORS);
        self::assertSame(CssValue::NAMED_COLORS, array_values(array_unique(CssValue::NAMED_COLORS)));
    }

    public function testNormalizesFontFamiliesAsTheEditorDoes(): void
    {
        self::assertSame("'Courier New', Courier, monospace", CssValue::fontFamily('Courier New, Courier, monospace'));
        self::assertSame("'Courier New', Courier, monospace", CssValue::fontFamily("'Courier New',Courier,  monospace"));
        self::assertSame("Georgia, 'Times New Roman', serif", CssValue::fontFamily('Georgia, "Times New Roman", serif'));
        self::assertSame('ui-monospace, Consolas, monospace', CssValue::fontFamily(' ui-monospace , Consolas, monospace '));
        self::assertSame("'PT Sans', sans-serif", CssValue::fontFamily("'PT  Sans', sans-serif"));
        self::assertSame("'Open Sans'", CssValue::fontFamily("' Open Sans '"));
        self::assertSame('Шрифт, serif', CssValue::fontFamily('Шрифт, serif'));
        foreach ([
            'Arial, Helvetica, sans-serif', 'Courier New, Courier, monospace', 'Georgia, serif',
            'Lucida Sans Unicode, Lucida Grande, sans-serif', 'Tahoma, Geneva, sans-serif', 'Times New Roman, Times, serif',
            'Trebuchet MS, Helvetica, sans-serif', 'Verdana, Geneva, sans-serif',
        ] as $default) {
            $normalized = CssValue::fontFamily($default);
            self::assertNotNull($normalized, $default);
            self::assertSame($default, str_replace("'", '', $normalized), 'only quotes are added');
            self::assertSame($normalized, CssValue::fontFamily($normalized), 'idempotent');
        }
    }

    public function testRefusesFontFamiliesOutsideTheNameSyntax(): void
    {
        foreach (['', 'Arial,', ', serif', 'a;b', 'Arial (bold)', "'Arial", 'url(x)', str_repeat('a', 65), 'a, b, c, d, e, f, g, h, i', '"x" y'] as $value) {
            self::assertNull(CssValue::fontFamily($value), $value);
        }
        self::assertSame('a, b, c, d, e, f, g, h', CssValue::fontFamily('a, b, c, d, e, f, g, h'), 'eight families');
        self::assertSame(str_repeat('a', 64), CssValue::fontFamily(str_repeat('a', 64)));
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function declarations(): iterable
    {
        yield 'width %' => ['width', '48.53%', '48.53%'];
        yield 'width px' => ['width', '120PX', '120PX'];
        yield 'width auto' => ['width', 'Auto', 'Auto'];
        yield 'width 0' => ['width', '0', '0'];
        yield 'width without unit' => ['width', '120', null];
        yield 'width negative' => ['width', '-5px', null];
        yield 'width too many digits' => ['width', '1234567px', null];
        yield 'width other unit' => ['width', '5ch', null];
        yield 'height rem' => ['height', ' 2.5rem ', '2.5rem'];
        yield 'aspect ratio' => ['aspect-ratio', '1600/900', '1600/900'];
        yield 'aspect ratio spaced' => ['aspect-ratio', '16  /  9', '16 / 9'];
        yield 'aspect ratio number' => ['aspect-ratio', '1.5', '1.5'];
        yield 'aspect ratio auto' => ['aspect-ratio', 'auto 1/1', null];
        yield 'background' => ['background-color', 'hsl(0, 0%, 90%)', 'hsl(0,0%,90%)'];
        yield 'text-align' => ['text-align', 'Center', 'Center'];
        yield 'text-align bad' => ['text-align', 'middle', null];
        yield 'vertical-align' => ['vertical-align', 'bottom', 'bottom'];
        yield 'vertical-align bad' => ['vertical-align', 'sub', null];
        yield 'padding 1' => ['padding', '4px', '4px'];
        yield 'padding 4' => ['padding', '1px 2px 3px 0', '1px 2px 3px 0'];
        yield 'padding 5' => ['padding', '1px 2px 3px 4px 5px', null];
        yield 'padding %' => ['padding', '5%', null];
        yield 'border' => ['border', '2px dashed #999999', '2px dashed #999999'];
        yield 'border with a function colour' => ['border-top', 'hsl(0, 0%, 30%) solid 1px', 'hsl(0,0%,30%) solid 1px'];
        yield 'border style only' => ['border-left', 'none', 'none'];
        yield 'border two widths' => ['border', '1px 2px solid', null];
        yield 'border four parts' => ['border', '1px solid red thick', null];
        yield 'border unknown part' => ['border', '1px wavy red', null];
        yield 'border-color' => ['border-color', 'red hsl(0, 0%, 0%) #fff', 'red hsl(0,0%,0%) #fff'];
        yield 'border-color 5' => ['border-color', 'red red red red red', null];
        yield 'border-style' => ['border-style', 'solid DOTTED', 'solid DOTTED'];
        yield 'border-width' => ['border-width', 'thin 2px', 'thin 2px'];
        yield 'border-width %' => ['border-width', '5%', null];
        yield 'border-top-color' => ['border-top-color', '#123', '#123'];
        yield 'border-left-style' => ['border-left-style', 'groove', 'groove'];
        yield 'border-bottom-width' => ['border-bottom-width', 'medium', 'medium'];
        yield 'per side takes one value' => ['border-right-width', '1px 2px', null];
        yield 'font-family' => ['font-family', 'Georgia,serif', 'Georgia, serif'];
        yield 'unknown property' => ['float', 'left', null];
        yield 'margin' => ['margin-left', '0', null];
        yield 'empty' => ['color', '  ', null];
    }

    #[DataProvider('declarations')]
    public function testNormalizesTheValueOfEachProperty(string $property, string $value, ?string $expected): void
    {
        self::assertSame($expected, CssValue::normalize($property, $value));
    }
}
