<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Admin;

use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\AtarLogo;
use GdImage;
use PHPUnit\Framework\TestCase;

/**
 * The AtarCMS logo (the aleph of Cardo Bold, white on a rounded square of the admin accent) is pre-rendered per preset
 * and committed byte-exact: an SVG (the favicon and the admin brand) and PNGs of AtarLogo::SIZES. The 180 PNG (the
 * apple-touch-icon) is full-bleed, since iOS rounds such icons itself and fills transparent corners with black.
 */
final class AtarLogoAssetsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    private const string DIR = self::ROOT . '/resources/admin/assets/logo';

    /** The master's colour (green) and its tile, which every preset's SVG repeats with its own colour. */
    private const string MASTER_COLOR = '#2a7d5c';

    private const array HASHES = [
        'Cardo-OFL.txt' => '8e28b778b6e1a7ff9ca72f4dee2d53120aa2856dc1bfd0be44307e365b0e45bb',
        'atar-blue-180.png' => '8d7ee3d577c6fe65d029c886bce60334ac24dcc376f1429208f3f9bba895ff69',
        'atar-blue-192.png' => 'b198265f7820f346adf9bb4fcd19cd1284d95fe635aefe3155bb2efdfd81d9fd',
        'atar-blue-32.png' => 'eecc8d52577a476add2552f909ab894a477477c0dd256737521ba1f9df8cb41e',
        'atar-blue-512.png' => '983e83ee145862fe000546cf5c7230c8dc36e5e08b13b81a5bf1d4bc0e3eafbb',
        'atar-blue.svg' => 'c90a77f1502b272a1e9c2b91a91a3a84bc7cf06839a0096c6991623167a57c80',
        'atar-cherry-180.png' => 'e748f9a6597e15801dc824b7eb17b66cb2ada7ba94085cfa16087d0b674b138f',
        'atar-cherry-192.png' => '39f07e7be1fae19012899349583b1ff475ccff9f5c2586bcd04088a3cc7a96e5',
        'atar-cherry-32.png' => 'd6e174e5669d6e8a8a55ad3d1fe6376743a2be44dc4008f36be8b3aa70c37772',
        'atar-cherry-512.png' => 'c56eed60c75c85c107d6126318b65fb4820137a5b687d7718272531946381193',
        'atar-cherry.svg' => 'fe67daef06b2cbd1cc1e7ab5f5ec7f4398ad73b9d328cf395695877ac41189b2',
        'atar-graphite-180.png' => 'ce5a8a0a7208b20fa1a4679bd6a026f558d0915bed92b53a05a7106b8cd221ee',
        'atar-graphite-192.png' => '3a56cac185a090231c60b71b0cf10a1117cfd69c5e1644ed59aa4fdd8e232c2c',
        'atar-graphite-32.png' => '8c49dc4aa441456bba177af2273fb12aebac988712982176dc4c5d629dfaacfd',
        'atar-graphite-512.png' => '80d2a1f803f7d84091ddffbfe3f382f7db775890fbe77266be7c6d107584c6a7',
        'atar-graphite.svg' => '400a3580ef9af1c293ff7c07c9a0d8eb1d5c01019575538662a86e0e483a9446',
        'atar-green-180.png' => 'c07ed1bce0fda40537f34527c1018a02407e0f0d746aafa7dfbab6038fa0f9c7',
        'atar-green-192.png' => '5f4baa42d24cc1cd74c8eb3c340816e719c3c974b9657cc38caa891f8e730a0c',
        'atar-green-32.png' => '4117739e2e16a3e8cded15d585e7575e409a203727508b7b5c6617399c187402',
        'atar-green-512.png' => '1b350c9d9d0d2d54e1add2d36478e33fbe66531d7e008f13f3329de46a473cf9',
        'atar-green.svg' => 'caa1e9d6d4304ea26f690a92110192799ec47a932e326d9a19c1e232d6994664',
        'atar-indigo-180.png' => '4f52fe69cd483f7fd27b8ef9e2f7265247432c3253d5db5abb4643d377b82410',
        'atar-indigo-192.png' => 'c08967ccef9beb94ac978de0b712b1abe1c9bf02975b7960383d7af50a764296',
        'atar-indigo-32.png' => '6f20860623a0e113a09c5aef2fb8e446c43e7f329656dd75d01dbdc3c693400f',
        'atar-indigo-512.png' => 'f13863f6acebf318c750d42d8bb41a4fa8f3b699ebe1196f1902a835a39f1d68',
        'atar-indigo.svg' => '5fd42d9a08e6e02b08dfa1da4a7bc053e9f82f4d4bfa989b55884a63cf84e08f',
        'atar-teal-180.png' => '2e2184f669ae261bb0b6be7fc055e4ec62cf3433a93f24069f2ebdf0d9690060',
        'atar-teal-192.png' => 'f8bbd214f2f8477bd61b821502505708250227990d0f53b82d9983119b43ac44',
        'atar-teal-32.png' => '10eb86e8d6807667ae76f97a087aaaec0957ce05d08bafae792fd13bf35ab512',
        'atar-teal-512.png' => '8737f5f5b8211994aa1aa6190d18ef6408df33cb133b2d813f40f29fbbf7d6d3',
        'atar-teal.svg' => '4e94ee338d4087c9478650b056dea3c873b681f01d3347383f1f554abe0ea58d',
        'atar-terracotta-180.png' => '659c72fdfbc17f98a7dc503421fb4d95be36d8209d473d107e018f9a591b324a',
        'atar-terracotta-192.png' => 'c04e20845d4f370afc33cac1f6414cca6615ae8db274a74447d8126162161a22',
        'atar-terracotta-32.png' => 'c80003b12d40be88e2ad385a7293700b62098a0ca5d4f96c102f7618181372ab',
        'atar-terracotta-512.png' => 'ddf1460a6f9a3764b24bfb509a4f644cf6a321fa819d4685bd711e475bc6d01f',
        'atar-terracotta.svg' => 'cbc59224f603faeb213bd2f491947cb3e0be9da2cd52601210a62ba642e0d0df',
        'atar-violet-180.png' => 'a42ef300905fa7d4e99498aaf1366553de0e28f81a8cdc48d63c7fe1b9d86389',
        'atar-violet-192.png' => 'be65373c30f4c09f2b6aaa8d7842951f18da19ed5de4e24121f1c1fac23da2b8',
        'atar-violet-32.png' => 'ec724c43992594e78f3773b2df96d3197cb8946fcc8eb343ce084f250fd73411',
        'atar-violet-512.png' => 'dfcb9ecb83d55ec7ac8df89e069f2459f8b7f078e110c3343afe53f1177ede2a',
        'atar-violet.svg' => '6c9bdc723b8e541eb7268f6c4910ae96b20e929698953e504b7fbde7a1a2eea3',
    ];

    public function testTheLogoFilesKeepTheirExactBytes(): void
    {
        self::assertStringContainsString('/resources/admin/assets/logo/** -text', (string) file_get_contents(self::ROOT . '/.gitattributes'));
        $files = array_map(basename(...), glob(self::DIR . '/*') ?: []);
        $expected = [...array_keys(self::HASHES), 'NOTICE.txt'];
        sort($files);
        sort($expected);
        self::assertSame($expected, $files, 'only the logo, its licence and its notice');
        foreach (self::HASHES as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', self::DIR . '/' . $file), $file);
        }
        $notice = (string) file_get_contents(self::DIR . '/NOTICE.txt');
        self::assertStringContainsString('Cardo Bold', $notice);
        self::assertStringContainsString('Cardo-OFL.txt', $notice);
    }

    public function testEveryPresetSvgIsTheMasterInItsColour(): void
    {
        $master = (string) file_get_contents(self::DIR . '/atar-green.svg');

        self::assertSame(self::MASTER_COLOR, AdminAccent::Green->logoColor());
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect x="1" y="1" width="62" height="62" rx="17" fill="#2a7d5c"/><path transform="translate(13.00 13.00) scale(0.3800)" fill="#fff" d="', $master);
        self::assertSame(1, substr_count($master, '#2a7d5c'));
        foreach (AdminAccent::cases() as $accent) {
            self::assertSame(
                str_replace(self::MASTER_COLOR, $accent->logoColor(), $master),
                file_get_contents(self::DIR . '/atar-' . $accent->value . '.svg'),
                $accent->value,
            );
        }
    }

    public function testThePngsAreTheTileInThePresetColour(): void
    {
        self::assertSame([32, 180, 192, 512], AtarLogo::SIZES);
        foreach (AdminAccent::cases() as $accent) {
            $color = array_map(hexdec(...), str_split(substr($accent->logoColor(), 1), 2));
            foreach (AtarLogo::SIZES as $size) {
                $file = self::DIR . '/atar-' . $accent->value . '-' . $size . '.png';
                self::assertSame([$size, $size, IMAGETYPE_PNG], array_slice(getimagesize($file) ?: [], 0, 3), $file);
                $image = imagecreatefrompng($file);
                self::assertInstanceOf(GdImage::class, $image);
                // Left of the glyph, halfway down: the tile.
                $tile = self::rgba($image, intdiv($size, 8), intdiv($size, 2));
                foreach ($color as $channel => $value) {
                    self::assertEqualsWithDelta($value, $tile[$channel], 2, $file);
                }
                self::assertSame(0, $tile[3], $file . ': the tile is opaque');
                $corner = self::rgba($image, 0, 0);
                if ($size === 180) {
                    self::assertSame([...$tile], $corner, $file . ': full-bleed');
                } else {
                    self::assertSame(127, $corner[3], $file . ': transparent corners');
                }
            }
        }
    }

    /**
     * @return array{int, int, int, int} red, green, blue and GD's alpha (0 opaque … 127 transparent)
     */
    private static function rgba(GdImage $image, int $x, int $y): array
    {
        $index = imagecolorat($image, $x, $y);
        self::assertIsInt($index);
        $rgba = imagecolorsforindex($image, $index);

        return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
    }
}
