<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Settings;

use AtarCms\Settings\GoogleMap;
use PHPUnit\Framework\TestCase;

final class GoogleMapTest extends TestCase
{
    private const string PB = '!1m18!1m12!1m3!1d3381.0!2d34.7818!3d32.0853!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMzLCsDA1JzA3LjEiTiAzNMKwNDYnNTQuNSJF!5e0!3m2!1sen!2sil!4v1700000000000!5m2!1sen!2sil';

    public function testAnEmbedKeepsGooglesAddressAndLinksToItsCentre(): void
    {
        $map = GoogleMap::embed(self::PB);

        self::assertSame('https://www.google.com/maps/embed?pb=' . self::PB, $map->embedUrl('he'), 'the language is inside pb');
        self::assertSame('https://www.google.com/maps/search/?api=1&query=32.0853%2C34.7818', $map->linkUrl());
        self::assertSame(['embed' => self::PB], $map->toArray());
        self::assertNull(GoogleMap::embed('!1m2!1sabc')->linkUrl(), 'no centre, no link');
    }

    public function testAQueryMapIsAnEmbedOfASearchInThePagesLanguage(): void
    {
        $map = GoogleMap::query('Dizengoff St 50, Tel Aviv', 15);

        self::assertSame('https://www.google.com/maps?q=Dizengoff+St+50%2C+Tel+Aviv&z=15&hl=ru&output=embed', $map->embedUrl('ru'));
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Dizengoff+St+50%2C+Tel+Aviv', $map->linkUrl());
        self::assertSame(['query' => 'Dizengoff St 50, Tel Aviv', 'zoom' => 15], $map->toArray());
    }

    public function testWazeNavigatesToThePointOrSearchesTheAddress(): void
    {
        self::assertSame('https://waze.com/ul?ll=32.0853%2C34.7818&navigate=yes', GoogleMap::embed(self::PB)->wazeUrl(), 'an embed: its centre');
        self::assertNull(GoogleMap::embed('!1m2!1sabc')->wazeUrl(), 'an embed without a centre has no route');
        self::assertSame('https://waze.com/ul?ll=32.085300%2C34.781800&navigate=yes', GoogleMap::query('32.085300,34.781800')->wazeUrl(), 'coordinates: the point');
        self::assertSame('https://waze.com/ul?q=Dizengoff+St+50%2C+Tel+Aviv&navigate=yes', GoogleMap::query('Dizengoff St 50, Tel Aviv')->wazeUrl());
        self::assertSame('https://waze.com/ul?q=%D7%93%D7%99%D7%96%D7%A0%D7%92%D7%95%D7%A3+50&navigate=yes', GoogleMap::query('דיזנגוף 50')->wazeUrl(), 'Hebrew, percent-encoded');
        self::assertSame('https://waze.com/ul?q=%D0%A2%D0%B2%D0%B5%D1%80%D1%81%D0%BA%D0%B0%D1%8F+1&navigate=yes', GoogleMap::query('Тверская 1')->wazeUrl(), 'Cyrillic, percent-encoded');
    }

    public function testTheStoredShapeReadsBackAndAnythingElseIsNoMap(): void
    {
        self::assertEquals(GoogleMap::embed(self::PB), GoogleMap::fromArray(['embed' => self::PB]));
        self::assertEquals(GoogleMap::query('32.085300,34.781800', 15), GoogleMap::fromArray(['query' => '32.085300,34.781800', 'zoom' => 15]));
        foreach ([null, 'x', [], ['embed' => 'a b'], ['embed' => str_repeat('a', GoogleMap::MAX_PB + 1)], ['query' => '', 'zoom' => 15], ['query' => 'x', 'zoom' => 2], ['query' => 'x', 'zoom' => 20], ['query' => 'x'], ['query' => str_repeat('a', 256), 'zoom' => 16], ['query' => "a\nb", 'zoom' => 16]] as $stored) {
            self::assertNull(GoogleMap::fromArray($stored), (string) json_encode($stored));
        }
    }

    public function testTheFrameSourceIsGoogle(): void
    {
        self::assertSame('https://www.google.com', GoogleMap::FRAME_SOURCE);
        self::assertStringStartsWith(GoogleMap::FRAME_SOURCE . '/', GoogleMap::query('x', 16)->embedUrl('en'));
        self::assertStringStartsWith(GoogleMap::FRAME_SOURCE . '/', GoogleMap::embed(self::PB)->embedUrl('en'));
    }
}
