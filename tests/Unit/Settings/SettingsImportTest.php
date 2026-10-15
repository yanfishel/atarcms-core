<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Settings;

use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsImport;
use AtarCms\Settings\SettingsImportException;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SocialLink;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * settings:import's JSON on top of the current settings: one SettingsInput per language to save.
 */
final class SettingsImportTest extends TestCase
{
    public function testAMissingKeyKeepsTheCurrentValueAndEmptyRemovesIt(): void
    {
        $inputs = SettingsImport::inputs([
            'site' => ['phone' => '+1 555 0100', 'email' => '', 'social' => ['vk' => null, 'youtube' => 'https://youtube.com/@x'], 'map' => ['latitude' => 32.08, 'longitude' => '34.78'], 'hide_from_search' => true, 'logo_id' => 7],
            'locales' => ['ru' => ['site_name' => 'Клиника', 'address' => "Улица 1\nТель-Авив"]],
        ], ['en', 'ru', 'he'], 'en', self::current(...));

        self::assertSame(['en', 'ru'], array_keys($inputs), 'the listed language, and the default one for the site-wide values');
        $ru = $inputs['ru'];
        self::assertSame('Клиника', $ru->siteName);
        self::assertSame("Улица 1\nТель-Авив", $ru->address);
        self::assertSame('Old title ru', $ru->metaTitle, 'kept');
        self::assertSame('+1 555 0100', $ru->phone);
        self::assertSame('', $ru->email, 'removed');
        self::assertEquals(['youtube' => 'https://youtube.com/@x', 'instagram' => 'https://instagram.com/old'], $ru->social, 'vk removed, youtube added, instagram kept');
        self::assertSame(['method' => 'coordinates', 'latitude' => '32.08', 'longitude' => '34.78'], $ru->map);
        self::assertTrue($ru->hideFromSearch);
        self::assertSame(7, $ru->logoId);
        self::assertSame(3, $ru->iconId, 'kept');
        self::assertSame('Old title en', $inputs['en']->metaTitle);
        self::assertSame('+1 555 0100', $inputs['en']->phone);
    }

    public function testWithoutSiteValuesOnlyTheListedLanguagesAreSaved(): void
    {
        $inputs = SettingsImport::inputs(['locales' => ['he' => ['site_name' => 'מרפאה']]], ['en', 'ru', 'he'], 'en', self::current(...));

        self::assertSame(['he'], array_keys($inputs));
        self::assertEquals(new SettingsInput(
            metaTitle: 'Old title he',
            phone: '+1 000',
            siteName: 'מרפאה',
            iconId: 3,
            social: ['instagram' => 'https://instagram.com/old'],
        ), $inputs['he']);
    }

    public function testAnEmptyStringRemovesImagesAndTheSearchSwitchToo(): void
    {
        $inputs = SettingsImport::inputs(['site' => ['icon_id' => '', 'logo_id' => null, 'og_image_id' => '', 'hide_from_search' => '']], ['en'], 'en', self::current(...));

        self::assertSame([null, null, null, false], [$inputs['en']->iconId, $inputs['en']->logoId, $inputs['en']->ogImageId, $inputs['en']->hideFromSearch]);
    }

    public function testTheAccessibilityToolbarIsOnUnlessFalse(): void
    {
        $toolbar = static fn(array $site, ?Closure $current = null): bool => SettingsImport::inputs(['site' => $site], ['en'], 'en', $current ?? self::current(...))['en']->accessibilityToolbar;
        $off = static fn(string $locale): Settings => new Settings(accessibilityToolbar: false);

        self::assertFalse($toolbar(['accessibility_toolbar' => false]));
        self::assertTrue($toolbar(['accessibility_toolbar' => true], $off));
        self::assertTrue($toolbar(['accessibility_toolbar' => ''], $off), 'removed: the default, on');
        self::assertTrue($toolbar(['accessibility_toolbar' => null], $off));
        self::assertFalse($toolbar([], $off), 'kept');
        self::assertFalse(SettingsImport::example(['en'], $off)['site']['accessibility_toolbar']);
        self::assertTrue(SettingsImport::example(['en'], self::current(...))['site']['accessibility_toolbar']);
        $position = static fn(array $site): string => SettingsImport::inputs(['site' => $site], ['en'], 'en', static fn(string $locale): Settings => new Settings(accessibilityToolbarPosition: 'top-end'))['en']->accessibilityToolbarPosition;
        self::assertSame('top-start', $position(['accessibility_toolbar_position' => 'top-start']));
        self::assertSame('', $position(['accessibility_toolbar_position' => null]), 'removed: the default');
        self::assertSame('top-end', $position([]), 'kept');
        self::assertSame('bottom-end', SettingsImport::example(['en'], self::current(...))['site']['accessibility_toolbar_position']);
        try {
            $toolbar(['accessibility_toolbar' => 'no']);
            self::fail('No exception');
        } catch (SettingsImportException $e) {
            self::assertSame(['site.accessibility_toolbar must be true, false, "" or null'], $e->problems);
        }
    }

    public function testTheChatButtonIsOffUnlessTrue(): void
    {
        $chat = static fn(array $site, ?Closure $current = null): bool => SettingsImport::inputs(['site' => $site], ['en'], 'en', $current ?? self::current(...))['en']->chatButton;
        $on = static fn(string $locale): Settings => new Settings(chatButton: true);

        self::assertTrue($chat(['chat_button' => true]));
        self::assertFalse($chat(['chat_button' => false], $on));
        self::assertFalse($chat(['chat_button' => null], $on), 'removed: the default, off');
        self::assertTrue($chat([], $on), 'kept');
        self::assertTrue(SettingsImport::example(['en'], $on)['site']['chat_button']);
        self::assertFalse(SettingsImport::example(['en'], self::current(...))['site']['chat_button']);
        try {
            $chat(['chat_button' => 1]);
            self::fail('No exception');
        } catch (SettingsImportException $e) {
            self::assertSame(['site.chat_button must be true, false, "" or null'], $e->problems);
        }
    }

    public function testTheCountersAreSiteWideAndTheBannerTextPerLanguage(): void
    {
        $inputs = SettingsImport::inputs([
            'site' => ['yandex_metrica' => '12345678', 'yandex_webvisor' => true, 'google_analytics' => 'G-TEST123456'],
            'locales' => ['ru' => ['consent_text' => 'Мы используем cookie.']],
        ], ['en', 'ru'], 'en', self::current(...));

        self::assertSame(['12345678', true, 'G-TEST123456', ''], [$inputs['en']->yandexMetrica, $inputs['en']->yandexWebvisor, $inputs['en']->googleAnalytics, $inputs['en']->consentText]);
        self::assertSame('Мы используем cookie.', $inputs['ru']->consentText);
        self::assertSame('locales.ru.consent_text', SettingsImport::path('ru', 'consent_text'));
        self::assertSame('site.yandex_metrica', SettingsImport::path('ru', 'yandex_metrica'));

        $off = SettingsImport::inputs(['site' => ['yandex_webvisor' => null]], ['en'], 'en', static fn(): Settings => new Settings(yandexMetrica: '1', yandexWebvisor: true));
        self::assertFalse($off['en']->yandexWebvisor, '"" or null switches Webvisor off');
        self::assertSame('1', $off['en']->yandexMetrica, 'kept');
        try {
            SettingsImport::inputs(['site' => ['yandex_webvisor' => 'yes']], ['en'], 'en', self::current(...));
            self::fail('a string is no switch');
        } catch (SettingsImportException $e) {
            self::assertSame(['site.yandex_webvisor must be true, false, "" or null'], $e->problems);
        }
    }

    public function testTheAdvertisingPixelsAreSiteWide(): void
    {
        $inputs = SettingsImport::inputs([
            'site' => ['meta_pixel' => '123456789012345', 'google_ads' => 'AW-123456789', 'vk_pixel' => '3456789', 'tiktok_pixel' => 'C4ABCDEFGH1234567890'],
        ], ['en', 'ru'], 'en', self::current(...));

        self::assertSame(['en'], array_keys($inputs), 'site-wide: saved once, through the default language');
        self::assertSame(['123456789012345', 'AW-123456789', '3456789', 'C4ABCDEFGH1234567890'], [$inputs['en']->metaPixel, $inputs['en']->googleAds, $inputs['en']->vkPixel, $inputs['en']->tiktokPixel]);
        self::assertSame('site.tiktok_pixel', SettingsImport::path('ru', 'tiktok_pixel'));
        $kept = SettingsImport::inputs(['site' => ['vk_pixel' => null]], ['en'], 'en', static fn(): Settings => new Settings(metaPixel: '1', vkPixel: '2'));
        self::assertSame(['1', ''], [$kept['en']->metaPixel, $kept['en']->vkPixel], 'a key left out is kept, null removes');
    }

    public function testAMistakeInTheShapeIsRefusedBeforeAnythingIsValidated(): void
    {
        try {
            SettingsImport::inputs([
                'site' => ['phone' => 5, 'fax' => '1', 'admin_accent' => 'blue', 'social' => ['myspace' => 'https://x'], 'map' => ['lat' => 1], 'logo_id' => 'seven', 'hide_from_search' => 'yes'],
                'locales' => ['de' => ['site_name' => 'x'], 'en' => ['title' => 'x', 'address' => ['x']]],
                'extra' => true,
            ], ['en', 'ru'], 'en', self::current(...));
            self::fail('No exception');
        } catch (SettingsImportException $e) {
            self::assertSame([
                'Unknown key "extra"; known keys: site, locales',
                'site.phone must be a string or null',
                'Unknown key "site.fax"',
                'Unknown key "site.admin_accent"',
                'Unknown network "site.social.myspace"; known: ' . implode(', ', Settings::SOCIAL_NETWORKS),
                'Unknown key "site.map.lat"; known: embed, link, query, address, latitude, longitude, zoom',
                'site.logo_id must be a media id (a number), "" or null',
                'site.hide_from_search must be true, false, "" or null',
                'The site has no language "de" (config/locales.php: en, ru)',
                'Unknown key "locales.en.title"',
                'locales.en.address must be a string or null',
            ], $e->problems);
        }
    }

    public function testTheExampleIsTheCurrentSettingsInTheImportShape(): void
    {
        $example = SettingsImport::example(['en', 'ru'], self::current(...));

        self::assertSame('+1 000', $example['site']['phone']);
        self::assertSame('', $example['site']['email']);
        self::assertIsArray($example['site']['social']);
        self::assertSame('https://instagram.com/old', $example['site']['social']['instagram']);
        self::assertSame('', $example['site']['social']['vk']);
        self::assertNull($example['site']['map'], 'no map');
        self::assertSame(3, $example['site']['icon_id']);
        self::assertNull($example['site']['logo_id']);
        self::assertArrayNotHasKey('admin_accent', $example['site'], 'the admin colour is each user\'s own');
        self::assertSame(['site_name' => '', 'site_short_name' => '', 'meta_title' => 'Old title ru', 'meta_description' => '', 'address' => '', 'opening_hours' => '', 'consent_text' => ''], $example['locales']['ru']);
        self::assertSame(['', false, ''], [$example['site']['yandex_metrica'], $example['site']['yandex_webvisor'], $example['site']['google_analytics']]);
        self::assertSame(['', '', '', ''], [$example['site']['meta_pixel'], $example['site']['google_ads'], $example['site']['vk_pixel'], $example['site']['tiktok_pixel']]);
        self::assertEquals(
            SettingsImport::inputs(['site' => [], 'locales' => ['en' => [], 'ru' => []]], ['en', 'ru'], 'en', self::current(...)),
            SettingsImport::inputs($example, ['en', 'ru'], 'en', self::current(...)),
            'importing the example changes nothing',
        );
    }

    public function testTheMapIsGivenOneWayAndTheStoredShapeIsUnchanged(): void
    {
        $withMap = static fn(string $locale): Settings => new Settings(map: GoogleMap::query('32.085300,34.781800', 15));
        $example = SettingsImport::example(['en'], $withMap);
        self::assertSame(['query' => '32.085300,34.781800', 'zoom' => 15], $example['site']['map']);
        self::assertNull(SettingsImport::inputs($example, ['en'], 'en', $withMap)['en']->map, 'the same map is kept');

        $map = static fn(mixed $map, ?Closure $current = null): ?array => SettingsImport::inputs(['site' => ['map' => $map]], ['en'], 'en', $current ?? self::current(...))['en']->map;
        self::assertSame([], $map(null, $withMap), 'null removes the map');
        self::assertSame([], $map('', $withMap));
        self::assertNull($map(null), 'nothing to remove');
        self::assertSame(['method' => 'embed', 'embed' => '!1m2'], $map(['embed' => '!1m2']));
        self::assertSame(['method' => 'link', 'link' => 'https://www.google.com/maps/@1,2,3z'], $map(['link' => 'https://www.google.com/maps/@1,2,3z']));
        self::assertSame(['method' => 'query', 'query' => 'Haifa', 'zoom' => '12'], $map(['query' => 'Haifa', 'zoom' => 12]));
        self::assertSame(['method' => 'address', 'address' => 'Haifa'], $map(['address' => 'Haifa']));
        self::assertSame(['method' => 'coordinates', 'latitude' => '32.08', 'longitude' => '34.78', 'zoom' => '15'], $map(['latitude' => 32.08, 'longitude' => 34.78, 'zoom' => 15]));

        foreach ([
            [['embed' => '!1m2', 'address' => 'Haifa'], 'site.map must be given one way: {"embed"}, {"link"}, {"query", "zoom"}, {"address"} or {"latitude", "longitude", "zoom"}'],
            [['zoom' => 12], 'site.map must be given one way: {"embed"}, {"link"}, {"query", "zoom"}, {"address"} or {"latitude", "longitude", "zoom"}'],
            [['address' => ['x']], 'site.map.address must be a string or a number'],
            ['Haifa', 'site.map must be an object'],
        ] as [$value, $problem]) {
            try {
                $map($value);
                self::fail('No exception: ' . $problem);
            } catch (SettingsImportException $e) {
                self::assertSame([$problem], $e->problems);
            }
        }
        self::assertSame('site.map.latitude', SettingsImport::path('en', 'map_latitude'));
        self::assertSame('site.map', SettingsImport::path('en', 'map_method'));
    }

    private static function current(string $locale): Settings
    {
        return new Settings(
            metaTitle: 'Old title ' . $locale,
            phone: '+1 000',
            iconId: 3,
            socialLinks: [new SocialLink('instagram', 'https://instagram.com/old')],
        );
    }
}
