<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content\Field;

use AtarCms\Content\Field\StyleAttributeSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class StyleAttributeSanitizerTest extends TestCase
{
    public function testSupportsTheStyleAttributeOfTheElementsTheEditorStyles(): void
    {
        $sanitizer = new StyleAttributeSanitizer(null);

        self::assertSame(['span', 'figure', 'img', 'table', 'td', 'th', 'col'], $sanitizer->getSupportedElements());
        self::assertSame(['style'], $sanitizer->getSupportedAttributes());
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function editorOutput(): iterable
    {
        yield 'font colour' => ['span', 'color:#e64d4d;', 'color: #e64d4d'];
        yield 'background' => ['span', 'background-color:hsl(0, 0%, 90%);', 'background-color: hsl(0,0%,90%)'];
        yield 'font family' => ['span', "font-family:'Courier New', Courier, monospace;", "font-family: 'Courier New', Courier, monospace"];
        yield 'merged' => ['span', 'color:#e64d4d;font-family:Georgia, serif;', 'color: #e64d4d; font-family: Georgia, serif'];
        yield 'resized figure' => ['figure', 'width:48.53%;', 'width: 48.53%'];
        yield 'table size' => ['figure', 'height:200px;width:60%;', 'height: 200px; width: 60%'];
        yield 'resized inline image' => ['img', 'width:25%;', 'width: 25%'];
        yield 'aspect ratio' => ['img', 'aspect-ratio:1600/900;', 'aspect-ratio: 1600/900'];
        yield 'image height' => ['img', 'height:120px;width:50%;', 'height: 120px; width: 50%'];
        yield 'table border' => ['table', 'background-color:#ffffff;border:2px dashed #999999;', 'background-color: #ffffff; border: 2px dashed #999999'];
        yield 'table longhands' => ['table', 'border-bottom:1px solid red;border-left-color:#000;border-top-style:dotted;border-right-width:3px;', 'border-bottom: 1px solid red; border-left-color: #000; border-top-style: dotted; border-right-width: 3px'];
        yield 'table border triple' => ['table', 'border-color:red;border-style:solid;border-width:1px;', 'border-color: red; border-style: solid; border-width: 1px'];
        yield 'cell' => ['td', 'background-color:hsl(120, 75%, 60%);height:40px;padding:4px 8px;text-align:center;vertical-align:bottom;width:120px;', 'background-color: hsl(120,75%,60%); height: 40px; padding: 4px 8px; text-align: center; vertical-align: bottom; width: 120px'];
        yield 'header cell' => ['th', 'border:1px solid #000;text-align:right;', 'border: 1px solid #000; text-align: right'];
        yield 'column' => ['col', 'width:33.33%;', 'width: 33.33%'];
    }

    #[DataProvider('editorOutput')]
    public function testKeepsWhatTheEditorWritesInNormalizedForm(string $element, string $style, ?string $expected): void
    {
        $sanitizer = new StyleAttributeSanitizer(null);

        self::assertSame($expected, self::sanitize($sanitizer, $element, $style));
        self::assertSame($expected, self::sanitize($sanitizer, $element, (string) $expected), 'idempotent');
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function propertiesPerElement(): iterable
    {
        yield 'span width' => ['span', 'width:10px', null];
        yield 'span font-size' => ['span', 'font-size:20px', null];
        yield 'span keeps colour, drops position' => ['span', 'position:fixed;color:red', 'color: red'];
        yield 'figure colour' => ['figure', 'color:red', null];
        yield 'figure float' => ['figure', 'float:left;margin-left:0;width:50%', 'width: 50%'];
        yield 'img border' => ['img', 'border:1px solid red', null];
        yield 'table width' => ['table', 'width:100%', null];
        yield 'table text-align' => ['table', 'text-align:center', null];
        yield 'td font-family' => ['td', 'font-family:serif', null];
        yield 'col height' => ['col', 'height:10px', null];
        yield 'paragraph' => ['p', 'color:red', null];
    }

    #[DataProvider('propertiesPerElement')]
    public function testAllowsOnlyTheElementsProperties(string $element, string $style, ?string $expected): void
    {
        self::assertSame($expected, self::sanitize(new StyleAttributeSanitizer(null), $element, $style));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injections(): iterable
    {
        yield 'url' => ['color:red;background-color:url(https://evil.example/x)'];
        yield 'URL upper case' => ['color:red;background-color:URL(x)'];
        yield 'expression' => ['color:expression(alert(1))'];
        yield 'Expression mixed case' => ['color:red;width:Expression(1)'];
        yield 'image-set' => ['background-color:image-set("x" 1x)'];
        yield 'var' => ['color:var(--x)'];
        yield 'attr' => ['color:attr(data-x)'];
        yield 'env' => ['width:env(safe-area-inset-left)'];
        yield 'calc' => ['width:calc(100% - 1px)'];
        yield 'src' => ['color:src(x)'];
        yield 'escape' => ['col\6f r:red'];
        yield 'escaped url' => ['color:red;background-color:u\72l(x)'];
        yield 'comment' => ['color:/**/red'];
        yield 'comment end' => ['color:red*/'];
        yield 'important' => ['color:red !important'];
        yield 'at-rule' => ['@import "x";color:red'];
        yield 'braces' => ['color:red}body{color:blue'];
        yield 'angle bracket' => ['color:red</style><script>'];
        yield 'control character' => ["color:r\x00ed"];
        yield 'escape character' => ["color:red\x1b"];
        yield 'too long' => ['color:red;' . str_repeat(' ', 1020)];
    }

    #[DataProvider('injections')]
    public function testRefusesAStyleThatCouldLoadOrEvaluateAnything(string $style): void
    {
        self::assertNull(self::sanitize(new StyleAttributeSanitizer(null), 'span', $style));
    }

    public function testDropsSingleDeclarationsThatDoNotParse(): void
    {
        $sanitizer = new StyleAttributeSanitizer(null);

        self::assertSame('color: red', self::sanitize($sanitizer, 'span', 'color:red;background-color:"blue";width:1px;b'), 'each declaration on its own');
        self::assertSame('color: red; background-color: red', self::sanitize($sanitizer, 'span', 'color:red;background-color:red;x:1;background-color:red blue'), 'an invalid repeat keeps the valid value');
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', ';;color : red;; COLOR'));
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', 'COLOR:red'), 'properties match in any case');
        self::assertSame('color: RED', self::sanitize($sanitizer, 'span', 'color:RED'), 'values keep their case');
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', "color:\n\tred"), 'whitespace collapses');
        self::assertNull(self::sanitize($sanitizer, 'span', 'colour:red'));
        self::assertNull(self::sanitize($sanitizer, 'span', 'c_olor:red'));
        self::assertNull(self::sanitize($sanitizer, 'span', 'color red'));
        self::assertNull(self::sanitize($sanitizer, 'span', ''), 'nothing left drops the attribute');
        self::assertNull(self::sanitize($sanitizer, 'span', "color:\u{0441}\u{0435}red"), 'not a colour');
    }

    public function testEdgeCasesOfTheDeclarationSyntax(): void
    {
        $sanitizer = new StyleAttributeSanitizer(null);

        self::assertSame('color: red', self::sanitize($sanitizer, 'span', "color:red;font-family:'Open;Sans', serif"), 'a ";" in a quoted family splits it: neither half is a family');
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', "color\n:red"), 'whitespace before the colon is allowed');
        self::assertNull(self::sanitize($sanitizer, 'span', "color:red;font-family:Ari\xC3al"), 'invalid UTF-8 refuses the style');
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', "color:red;font-family:Arial\u{202E}lairA"), 'a bidi override is no font name character');
        self::assertNull(self::sanitize($sanitizer, 'span', "font-family:\u{202E}Arial"));
    }

    public function testARepeatedPropertyKeepsItsFirstPlaceAndItsLastValidValue(): void
    {
        self::assertSame(
            'color: blue; background-color: red',
            self::sanitize(new StyleAttributeSanitizer(null), 'span', 'color:red;background-color:red;color:blue;color:nope'),
        );
    }

    public function testKeepsAtMostSixteenDeclarations(): void
    {
        $properties = ['border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
            'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style', 'border-top-width', 'border-right-width',
            'border-bottom-width', 'border-left-width', 'background-color'];
        $style = '';
        foreach ($properties as $property) {
            $style .= $property . ':' . (str_ends_with($property, 'width') ? '1px' : (str_ends_with($property, 'color') ? 'red' : 'solid')) . ';';
        }

        $kept = (string) self::sanitize(new StyleAttributeSanitizer(null), 'table', $style . 'border:2px solid red');

        self::assertSame(16, substr_count($kept, ':'));
        self::assertStringNotContainsString('background-color', $kept);
        self::assertStringStartsWith('border: 2px solid red; border-top: solid;', $kept, 'a repeated property still updates');
    }

    public function testAListOfFontFamiliesKeepsOnlyThoseFamilies(): void
    {
        $sanitizer = new StyleAttributeSanitizer(["'Courier New', Courier, monospace", 'Georgia, serif']);

        self::assertSame("font-family: 'Courier New', Courier, monospace", self::sanitize($sanitizer, 'span', 'font-family:"Courier New",Courier,monospace'));
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', 'color:red;font-family:Georgia'));
        self::assertNull(self::sanitize($sanitizer, 'span', 'font-family:Comic Sans MS'));
        self::assertSame('color: red', self::sanitize(new StyleAttributeSanitizer([]), 'span', 'font-family:Georgia, serif;color:red'));
    }

    public function testAStricterProfileNamesItsOwnProperties(): void
    {
        $sanitizer = new StyleAttributeSanitizer(null, ['span' => ['color']]);

        self::assertSame(['span'], $sanitizer->getSupportedElements());
        self::assertSame('color: red', self::sanitize($sanitizer, 'span', 'color:red;background-color:blue;font-family:serif'));
        self::assertNull(self::sanitize($sanitizer, 'td', 'width:10px'));
    }

    private static function sanitize(StyleAttributeSanitizer $sanitizer, string $element, string $style): ?string
    {
        return $sanitizer->sanitizeAttribute($element, 'style', $style, new HtmlSanitizerConfig());
    }
}
