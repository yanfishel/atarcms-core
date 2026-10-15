<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Media\Share\ShareFont;
use AtarCms\Tests\Support\Cast;
use PHPUnit\Framework\TestCase;

/**
 * The title font of the generated share images: Open Sans Bold (SIL OFL 1.1, Cyrillic), vendored byte-exact with its
 * licence from github.com/googlefonts/opensans (fonts/ttf/OpenSans-Bold.ttf and OFL.txt).
 */
final class ShareFontAssetTest extends TestCase
{
    private const array HASHES = [
        'OpenSans-Bold.ttf' => '27da758f4dcac9a65abe914c13b463b42982b9909bc65713424099f4810bd1e6',
        'OpenSans-OFL.txt' => '01cd5ffb3a528c219a86e49a814c6c53bd8a69b9a3c305dd237074ba1c811af7',
    ];

    public function testTheFontAndItsLicenceAreByteExact(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString("\n/resources/fonts/** -text\n", (string) file_get_contents($root . '/.gitattributes'));
        $files = array_values(array_diff(scandir($root . '/resources/fonts') ?: [], ['.', '..']));
        self::assertSame(array_keys(self::HASHES), $files, 'only the font and its licence');
        foreach (self::HASHES as $name => $hash) {
            self::assertSame($hash, hash_file('sha256', $root . '/resources/fonts/' . $name), $name);
        }
        self::assertSame(realpath($root . '/resources/fonts/OpenSans-Bold.ttf'), realpath(ShareFont::path()));
    }

    public function testGdDrawsTheFontWithCyrillicGlyphs(): void
    {
        self::assertTrue(function_exists('imagettfbbox') && (gd_info()['FreeType Support'] ?? false), 'GD is built with FreeType');
        foreach (['W', 'Ж', '…'] as $text) {
            $box = imagettfbbox(40, 0, ShareFont::path(), $text);
            self::assertNotFalse($box);
            self::assertGreaterThan(10, Cast::int($box[2]) - Cast::int($box[0]), $text);
        }
    }
}
