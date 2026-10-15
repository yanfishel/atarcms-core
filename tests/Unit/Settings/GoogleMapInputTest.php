<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Settings;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\GoogleMapInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleMapInputTest extends TestCase
{
    private const string PB = '!1m18!1m12!1m3!1d3381.0!2d34.7818!3d32.0853!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMzLCsDA1JzA3LjEiTiAzNMKwNDYnNTQuNSJF!5e0!3m2!1sen!2sil!4v1700000000000!5m2!1sen!2sil';

    public function testAWholeEmbedCodeIsReducedToItsPb(): void
    {
        $code = '<iframe src="https://www.google.com/maps/embed?pb=' . self::PB . '" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

        self::assertEquals(GoogleMap::embed(self::PB), GoogleMapInput::parse('embed', ['embed' => $code]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedEmbeds(): iterable
    {
        yield 'the src alone' => ['https://www.google.com/maps/embed?pb=' . self::PB];
        yield 'http' => ['http://www.google.com/maps/embed?pb=' . self::PB];
        yield 'entities, line breaks and single quotes' => ["  <iframe\n  width='600'\n  src='https://www.google.com/maps/embed?pb=" . self::PB . "&amp;hl=en'\n></iframe>\n"];
        yield 'an entity in the src alone' => ['https://www.google.com/maps/embed?hl=en&amp;pb=' . self::PB];
        yield 'the pb alone' => [self::PB];
    }

    #[DataProvider('acceptedEmbeds')]
    public function testEmbedsAreNormalised(string $value): void
    {
        $map = GoogleMapInput::parse('embed', ['embed' => $value]);

        self::assertSame('https://www.google.com/maps/embed?pb=' . self::PB, $map->embedUrl('en'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedEmbeds(): iterable
    {
        yield 'empty' => ['  '];
        yield 'another host' => ['https://evil.com/?x=google.com/maps/embed?pb=' . self::PB];
        yield 'a user part' => ['https://www.google.com@evil.com/maps/embed?pb=' . self::PB];
        yield 'a port' => ['https://www.google.com:8443/maps/embed?pb=' . self::PB];
        yield 'another path' => ['https://www.google.com/maps/place?pb=' . self::PB];
        yield 'no pb' => ['https://www.google.com/maps/embed?q=Tel+Aviv'];
        yield 'a quote in the pb' => ['https://www.google.com/maps/embed?pb=!1m2"onload="x'];
        yield 'an iframe of another site' => ['<iframe src="https://www.youtube.com/embed/abc"></iframe>'];
        yield 'a script' => ['<script>alert(1)</script>'];
        yield 'too long' => ['https://www.google.com/maps/embed?pb=!' . str_repeat('a', GoogleMap::MAX_PB)];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('refusedEmbeds')]
    public function testOtherEmbedsAreRefused(string $value): void
    {
        self::assertSame(['embed' => 'validation.map.embed_invalid'], self::refusal('embed', ['embed' => $value]));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function links(): iterable
    {
        yield 'the marker wins over the view' => [
            'https://www.google.com/maps/place/Dizengoff+Center/@32.0753,34.7748,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d32.0755!4d34.7751',
            '32.075500,34.775100',
            17,
        ];
        yield 'the view alone' => ['https://www.google.co.il/maps/@32.0853,34.7818,15z', '32.085300,34.781800', 15];
        yield 'a view in metres has no zoom' => ['https://www.google.com/maps/@32.0853,34.7818,1250m/data=!3m1!1e3', '32.085300,34.781800', GoogleMap::DEFAULT_ZOOM];
        yield 'a fractional zoom' => ['https://www.google.ru/maps/@55.7558,37.6173,14.5z', '55.755800,37.617300', 14];
        yield 'a zoom past the limits' => ['https://www.google.com/maps/@55.7558,37.6173,21z', '55.755800,37.617300', GoogleMap::MAX_ZOOM];
        yield 'a search' => ['https://www.google.com/maps?q=Dizengoff+50,+Tel+Aviv', 'Dizengoff 50, Tel Aviv', GoogleMap::DEFAULT_ZOOM];
        yield 'an api search' => ['https://www.google.com/maps/search/?api=1&query=Red%20Square%2C%20Moscow', 'Red Square, Moscow', GoogleMap::DEFAULT_ZOOM];
        yield 'maps.google' => ['https://maps.google.com/?q=Haifa', 'Haifa', GoogleMap::DEFAULT_ZOOM];
        yield 'a Hebrew place name' => ['https://www.google.com/maps/place/%D7%93%D7%99%D7%96%D7%A0%D7%92%D7%95%D7%A3+%D7%A1%D7%A0%D7%98%D7%A8/', 'דיזנגוף סנטר', GoogleMap::DEFAULT_ZOOM];
        yield 'a Cyrillic place name' => ['https://www.google.ru/maps/place/%D0%9A%D1%80%D0%B0%D1%81%D0%BD%D0%B0%D1%8F+%D0%BF%D0%BB%D0%BE%D1%89%D0%B0%D0%B4%D1%8C/data=!4m2!3m1!1s0x0:0x0', 'Красная площадь', GoogleMap::DEFAULT_ZOOM];
        yield 'a search path' => ['https://www.google.com/maps/search/Dizengoff+Center,+Tel+Aviv', 'Dizengoff Center, Tel Aviv', GoogleMap::DEFAULT_ZOOM];
        yield 'a Hebrew search path' => ['https://www.google.co.il/maps/search/%D7%93%D7%99%D7%96%D7%A0%D7%92%D7%95%D7%A3+50/', 'דיזנגוף 50', GoogleMap::DEFAULT_ZOOM];
        yield 'a search path with a view' => ['https://www.google.com/maps/search/Haifa/@32.79,34.99,13z', '32.790000,34.990000', 13];
        yield 'http and spaces' => ['  http://www.google.com/maps/@32.0853,34.7818,15z  ', '32.085300,34.781800', 15];
    }

    #[DataProvider('links')]
    public function testALinkGivesItsPoint(string $link, string $query, int $zoom): void
    {
        self::assertEquals(GoogleMap::query($query, $zoom), GoogleMapInput::parse('link', ['link' => $link]));
    }

    public function testShortLinksAreRefusedWithTheirOwnMessage(): void
    {
        foreach (['https://maps.app.goo.gl/AbCdEf123', 'https://goo.gl/maps/AbCdEf123', 'maps.app.goo.gl/x'] as $link) {
            self::assertSame(['link' => 'validation.map.link_short'], self::refusal('link', ['link' => $link]), $link);
        }
    }

    public function testOtherLinksAreRefused(): void
    {
        foreach (['', 'Tel Aviv', 'https://evil.com/maps/@32.08,34.78,15z', 'https://www.google.com/search?q=x', 'https://www.google.com/maps', 'https://www.google.com/maps/@95.1,34.78,15z', 'https://www.google.com.evil.com/maps/@32.08,34.78,15z', 'ftp://www.google.com/maps/@32.08,34.78,15z'] as $link) {
            self::assertSame(['link' => 'validation.map.link_invalid'], self::refusal('link', ['link' => $link]), $link);
        }
    }

    public function testAnAddressIsTrimmedText(): void
    {
        self::assertEquals(GoogleMap::query('Dizengoff 50, Tel Aviv'), GoogleMapInput::parse('address', ['address' => "  Dizengoff 50,\n Tel Aviv "]));
        self::assertSame(['address' => 'validation.map.address_required'], self::refusal('address', ['address' => ' ']));
        self::assertSame(['address' => 'validation.map.address_required'], self::refusal('address', []));
        self::assertSame(['address' => 'validation.map.address_long'], self::refusal('address', ['address' => str_repeat('a', 256)]));
        self::assertEquals(GoogleMap::query(str_repeat('ж', 255)), GoogleMapInput::parse('address', ['address' => str_repeat('ж', 255)]));
    }

    public function testCoordinatesAreAPointWithAZoom(): void
    {
        self::assertEquals(GoogleMap::query('32.085300,34.781800', 15), GoogleMapInput::parse('coordinates', ['latitude' => ' 32.0853 ', 'longitude' => '34.7818', 'zoom' => '15']));
        self::assertEquals(GoogleMap::query('-33.868800,151.209300'), GoogleMapInput::parse('coordinates', ['latitude' => '-33.8688', 'longitude' => '151.2093', 'zoom' => '']));
        self::assertEquals(GoogleMap::query('55.755800,37.617300'), GoogleMapInput::parse('coordinates', ['latitude' => '55.7558, 37.6173', 'longitude' => '']), 'a pasted pair is split');
        self::assertEquals(GoogleMap::query('55.755800,37.617300'), GoogleMapInput::parse('coordinates', ['latitude' => '55.7558 37.6173']), 'a pair with a space');
        self::assertEquals(GoogleMap::query('90.000000,-180.000000'), GoogleMapInput::parse('coordinates', ['latitude' => '90', 'longitude' => '-180']));
    }

    public function testWrongCoordinatesAreRefused(): void
    {
        self::assertSame(['latitude' => 'validation.map.coordinates'], self::refusal('coordinates', ['latitude' => '32,0853', 'longitude' => '34.7818']), 'a decimal comma');
        self::assertSame(['latitude' => 'validation.map.coordinates'], self::refusal('coordinates', ['latitude' => '95', 'longitude' => '34.7818']));
        self::assertSame(['longitude' => 'validation.map.coordinates'], self::refusal('coordinates', ['latitude' => '32.0853', 'longitude' => '181']));
        self::assertSame(['longitude' => 'validation.map.coordinates'], self::refusal('coordinates', ['latitude' => '32.0853', 'longitude' => '']), 'the pair is missing one');
        self::assertSame(['latitude' => 'validation.map.coordinates'], self::refusal('coordinates', ['latitude' => '', 'longitude' => '34.7818']));
        self::assertSame(
            ['latitude' => 'validation.map.coordinates', 'longitude' => 'validation.map.coordinates', 'zoom' => 'validation.map.zoom'],
            self::refusal('coordinates', ['latitude' => 'north', 'longitude' => 'east', 'zoom' => '20']),
        );
        foreach (['2', '20', '1.5', 'x', '-5'] as $zoom) {
            self::assertSame(['zoom' => 'validation.map.zoom'], self::refusal('coordinates', ['latitude' => '1', 'longitude' => '2', 'zoom' => $zoom]), $zoom);
        }
    }

    public function testAQueryIsAnAddressWithAZoom(): void
    {
        self::assertEquals(GoogleMap::query('32.085300,34.781800', 15), GoogleMapInput::parse('query', ['query' => '32.085300,34.781800', 'zoom' => '15']));
        self::assertSame(['query' => 'validation.map.address_required'], self::refusal('query', ['query' => '']));
    }

    public function testNonStringValuesCountAsEmpty(): void
    {
        self::assertSame(['address' => 'validation.map.address_required'], self::refusal('address', ['address' => ['x']]));
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, string>
     */
    private static function refusal(string $method, array $input): array
    {
        try {
            GoogleMapInput::parse($method, $input);
        } catch (ValidationFailed $failed) {
            return $failed->errors;
        }
        self::fail('A refusal expected');
    }
}
