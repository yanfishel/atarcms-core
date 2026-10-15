<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\StructuredData\Organization;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SocialLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function testTheNodeCarriesTheFilledContactDetails(): void
    {
        $site = new Settings(
            phone: '+972 3 555 0100',
            email: 'hello@example.com',
            address: "  Rothschild 1 \n\n Tel Aviv  \n",
            openingHours: 'Sun–Thu 9:00–18:00',
            socialLinks: [new SocialLink('facebook', 'https://facebook.com/atar'), new SocialLink('youtube', 'https://youtube.com/@atar')],
            messengers: [new SocialLink('telegram', 'https://t.me/atar')],
            map: GoogleMap::query('32.063412,34.774180'),
        );

        self::assertSame([
            '@type' => 'Dentist',
            '@id' => 'https://example.com/#organization',
            'name' => 'Atar <Clinic>',
            'url' => 'https://example.com/',
            'logo' => 'https://example.com/uploads/logo.png',
            'telephone' => '+972 3 555 0100',
            'email' => 'hello@example.com',
            'address' => 'Rothschild 1, Tel Aviv',
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 32.063412, 'longitude' => 34.77418],
            'sameAs' => ['https://facebook.com/atar', 'https://youtube.com/@atar'],
        ], (new Organization('Dentist', 'https://example.com'))->node('Atar <Clinic>', 'https://example.com/uploads/logo.png', $site), 'the address on one line; no opening hours (free text) and no messengers');
    }

    public function testAnEmptySiteGivesTheNameAndAddressOnly(): void
    {
        self::assertSame(
            ['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'Atar', 'url' => 'https://example.com/'],
            (new Organization('Organization', 'https://example.com'))->node('Atar', null, new Settings(map: GoogleMap::query('Rothschild 1, Tel Aviv'))),
            'a map searched by address has no coordinates',
        );
    }

    public function testAReferenceNamesTheSameNode(): void
    {
        self::assertSame(
            ['@type' => 'LocalBusiness', '@id' => 'https://example.com/#organization', 'name' => 'Atar', 'url' => 'https://example.com/'],
            (new Organization('LocalBusiness', 'https://example.com'))->reference('Atar'),
        );
    }

    public function testTheTypeComesFromTheConfig(): void
    {
        self::assertSame('Organization', Organization::fromConfig(new Config(['app' => ['url' => 'https://example.com']]))->type, 'the default');
        $organization = Organization::fromConfig(new Config(['app' => ['url' => 'https://example.com'], 'content' => ['organization_type' => 'Restaurant']]));
        self::assertSame('Restaurant', $organization->type);
        self::assertSame('https://example.com/#organization', $organization->id());
    }

    #[DataProvider('badTypes')]
    public function testAnInvalidTypeIsAConfigError(string $type): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('content.organization_type');

        Organization::fromConfig(new Config(['app' => ['url' => 'https://example.com'], 'content' => ['organization_type' => $type]]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'lower case' => ['organization'];
        yield 'markup' => ['Store"><script>'];
        yield 'a URL' => ['https://schema.org/Store'];
    }
}
