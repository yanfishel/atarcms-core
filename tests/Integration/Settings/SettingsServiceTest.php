<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Settings;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use AtarCms\Settings\SocialLink;
use AtarCms\Tests\Integration\Content\ContentTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\Tests\Support\TempDirectory;

final class SettingsServiceTest extends ContentTestCase
{
    use TempDirectory;

    public function testTheSeoDefaultsArePerLocaleAndTheContactDetailsSiteWide(): void
    {
        $image = $this->insertMedia();
        $service = $this->settingsService();

        $service->save('en', new SettingsInput('Fishing club', 'We fish.', $image, '+1 555 0100', 'club@example.com'), null, null);
        $service->save('ru', new SettingsInput('Клуб рыбаков', '', $image, '+1 555 0100', 'club@example.com'), null, null);

        self::assertEquals(new Settings('Fishing club', 'We fish.', $image, '+1 555 0100', 'club@example.com'), $this->siteSettings()->of('en'));
        self::assertEquals(new Settings('Клуб рыбаков', null, $image, '+1 555 0100', 'club@example.com'), $this->siteSettings()->of('ru'));
        self::assertSame(['settings.updated', 'settings.updated'], $this->auditActions());
    }

    public function testEmptyValuesRemoveTheSettingsAndTextIsCollapsed(): void
    {
        $service = $this->settingsService();
        $service->save('en', new SettingsInput('Club', 'Old', null, '1', 'a@example.com'), null, null);

        $settings = $service->save('en', new SettingsInput("  Fishing \n club  ", '', null, '', ''), null, null);

        self::assertEquals(new Settings('Fishing club'), $settings);
        self::assertSame(1, Cast::int($this->db->scalar('SELECT COUNT(*) FROM settings')));
    }

    public function testValuesAreValidated(): void
    {
        $document = $this->insertMedia(['mime' => 'application/pdf', 'original_name' => 'menu.pdf', 'width' => null, 'height' => null]);
        $service = $this->settingsService();

        self::assertValidation(
            ['meta_title' => 'validation.field.too_long', 'email' => 'validation.email.invalid', 'og_image_id' => 'validation.field.media_type'],
            static fn() => $service->save('en', new SettingsInput(str_repeat('a', 256), '', $document, '', 'not an address'), null, null),
            ['meta_title' => ['max' => 255]],
        );
        self::assertValidation(
            ['locale' => 'validation.locale.unsupported', 'og_image_id' => 'validation.field.media'],
            static fn() => $service->save('de', new SettingsInput('', '', 999), null, null),
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM settings')));
    }

    public function testPerLanguageAndSiteWideValuesAreStoredApart(): void
    {
        $logo = $this->insertMedia();
        $icon = $this->insertMedia(['mime' => 'image/png', 'width' => 512, 'height' => 512]);
        $service = $this->settingsService();
        $full = new SettingsInput(
            siteName: 'Fishing club',
            siteShortName: 'Club',
            logoId: $logo,
            iconId: $icon,
            themeColor: '#1A2B3C',
            titlePattern: '{title} | {site}',
            hideFromSearch: true,
            whatsapp: '+7 (900) 123-45-67',
            viber: '+7 900 123 45 68',
            telegram: '@fishing_club',
            address: "River street 1\r\nBoathouse",
            openingHours: 'Daily 6–22',
            social: ['youtube' => 'https://youtube.com/@club', 'vk' => 'https://vk.com/club', 'x' => ''],
            yandexVerification: '<meta name="yandex-verification" content="0123abcd" />',
            googleVerification: 'g00gle_-code',
        );

        $service->save('en', $full, null, null);
        $service->save('ru', new SettingsInput(siteName: 'Клуб рыбаков', logoId: $logo, iconId: $icon, themeColor: '#1a2b3c', titlePattern: '{title} | {site}', hideFromSearch: true, whatsapp: '+79001234567', viber: '+79001234568', telegram: 'fishing_club', social: ['youtube' => 'https://youtube.com/@club', 'vk' => 'https://vk.com/club'], yandexVerification: '0123abcd', googleVerification: 'g00gle_-code'), null, null);

        $en = $this->siteSettings()->of('en');
        self::assertSame(['Fishing club', 'Club', "River street 1\nBoathouse", 'Daily 6–22'], [$en->siteName, $en->siteShortName, $en->address, $en->openingHours]);
        self::assertSame([$logo, $icon, '#1a2b3c', '{title} | {site}', true], [$en->logoId, $en->iconId, $en->themeColor, $en->titlePattern, $en->hideFromSearch]);
        self::assertSame(['+79001234567', '+79001234568', 'fishing_club', '0123abcd', 'g00gle_-code'], [$en->whatsapp, $en->viber, $en->telegram, $en->yandexVerification, $en->googleVerification]);
        self::assertEquals([new SocialLink('youtube', 'https://youtube.com/@club'), new SocialLink('vk', 'https://vk.com/club')], $en->socialLinks, 'in the list order, filled only');
        self::assertEquals([
            new SocialLink('whatsapp', 'https://wa.me/79001234567'),
            new SocialLink('viber', 'viber://chat?number=%2B79001234568'),
            new SocialLink('telegram', 'https://t.me/fishing_club'),
        ], $en->messengers);
        $ru = $this->siteSettings()->of('ru');
        self::assertSame(['Клуб рыбаков', null, null, null], [$ru->siteName, $ru->siteShortName, $ru->address, $ru->openingHours]);
        self::assertSame([$logo, $icon, true], [$ru->logoId, $ru->iconId, $ru->hideFromSearch]);
        self::assertSame(['settings.updated', 'settings.updated'], $this->auditActions());
    }

    public function testTheAccessibilityToolbarIsOnUntilSwitchedOff(): void
    {
        $service = $this->settingsService();
        self::assertTrue($this->siteSettings()->of('en')->accessibilityToolbar, 'on without a row');

        $service->save('ru', new SettingsInput(accessibilityToolbar: false), null, null);
        self::assertFalse($this->siteSettings()->of('en')->accessibilityToolbar, 'site-wide');
        self::assertSame('0', $this->db->scalar("SELECT value FROM settings WHERE name = 'site.accessibility_toolbar' AND locale = ''"));

        $service->save('en', new SettingsInput(), null, null);
        self::assertTrue($this->siteSettings()->of('ru')->accessibilityToolbar);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM settings')), 'on is no row');
    }

    public function testTheChatButtonIsOffUntilSwitchedOnWithTheContactDetails(): void
    {
        $service = $this->settingsService();
        self::assertFalse($this->siteSettings()->of('en')->chatButton, 'off without a row');

        $service->saveContacts('ru', new SettingsInput(whatsapp: '+79001234567', chatButton: true), null, null);
        self::assertTrue($this->siteSettings()->of('en')->chatButton, 'site-wide');
        self::assertSame('1', $this->db->scalar("SELECT value FROM settings WHERE name = 'contact.chat_button' AND locale = ''"));

        $service->saveContacts('en', new SettingsInput(whatsapp: '+79001234567'), null, null);
        self::assertFalse($this->siteSettings()->of('ru')->chatButton);
        self::assertSame(0, Cast::int($this->db->scalar("SELECT COUNT(*) FROM settings WHERE name = 'contact.chat_button'")), 'off is no row');
        self::assertTrue(SettingsService::isContact('contact.chat_button'), 'the contact page saves it, the Site settings screen keeps it');
    }

    public function testTheToolbarPositionIsALogicalCornerBottomEndByDefault(): void
    {
        $service = $this->settingsService();
        self::assertSame('bottom-end', $this->siteSettings()->of('en')->accessibilityToolbarPosition);

        $service->save('ru', new SettingsInput(accessibilityToolbarPosition: 'top-start'), null, null);
        self::assertSame('top-start', $this->siteSettings()->of('en')->accessibilityToolbarPosition, 'site-wide');

        $service->save('en', new SettingsInput(accessibilityToolbarPosition: 'bottom-end'), null, null);
        self::assertSame('bottom-end', $this->siteSettings()->of('en')->accessibilityToolbarPosition);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM settings')), 'the default is no row');

        self::assertValidation(
            ['accessibility_toolbar_position' => 'validation.settings.toolbar_position'],
            static fn() => $service->save('en', new SettingsInput(accessibilityToolbarPosition: 'top-left'), null, null),
        );
    }

    public function testTheCountersAreSiteWideAndTheBannerTextPerLanguage(): void
    {
        $service = $this->settingsService();
        self::assertSame([null, false, null, null], self::counters($this->siteSettings()->of('en')));

        $service->save('ru', new SettingsInput(yandexMetrica: ' 12345678 ', yandexWebvisor: true, googleAnalytics: 'g-test123456', consentText: "  Мы используем
 cookie. "), null, null);
        self::assertSame(['12345678', true, 'G-TEST123456', 'Мы используем cookie.'], self::counters($this->siteSettings()->of('ru')));
        self::assertSame(['12345678', true, 'G-TEST123456', null], self::counters($this->siteSettings()->of('en')), 'the counters are site-wide, the text per language');
        self::assertSame(['settings.updated'], $this->auditActions());

        $service->save('en', new SettingsInput(yandexWebvisor: true), null, null);
        self::assertSame([null, false, null, null], self::counters($this->siteSettings()->of('en')), 'Webvisor needs the counter');
        self::assertSame(0, Cast::int($this->db->scalar("SELECT COUNT(*) FROM settings WHERE name = 'analytics.yandex_webvisor'")));
        self::assertSame('Мы используем cookie.', $this->siteSettings()->of('ru')->consentText);

        self::assertValidation(
            ['yandex_metrica' => 'validation.settings.metrica', 'google_analytics' => 'validation.settings.google_analytics', 'consent_text' => 'validation.field.too_long'],
            static fn() => $service->save('en', new SettingsInput(yandexMetrica: 'ym-1', googleAnalytics: 'UA-1-1', consentText: str_repeat('a', 501)), null, null),
            ['consent_text' => ['max' => 500]],
        );
    }

    public function testTheAdvertisingPixelsAreSiteWide(): void
    {
        $service = $this->settingsService();
        $pixels = static fn(Settings $s): array => [$s->metaPixel, $s->googleAds, $s->vkPixel, $s->tiktokPixel];
        self::assertSame([null, null, null, null], $pixels($this->siteSettings()->of('en')));

        $service->save('ru', new SettingsInput(metaPixel: ' 123456789012345 ', googleAds: 'aw-123456789', vkPixel: '3456789', tiktokPixel: 'c4abcdefgh1234567890'), null, null);
        self::assertSame(['123456789012345', 'AW-123456789', '3456789', 'C4ABCDEFGH1234567890'], $pixels($this->siteSettings()->of('en')), 'site-wide');
        self::assertSame(['settings.updated'], $this->auditActions());

        self::assertValidation(
            ['meta_pixel' => 'validation.settings.meta_pixel', 'google_ads' => 'validation.settings.google_ads', 'vk_pixel' => 'validation.settings.vk_pixel', 'tiktok_pixel' => 'validation.settings.tiktok_pixel'],
            static fn() => $service->save('en', new SettingsInput(metaPixel: 'fb-1', googleAds: 'G-TEST123456', vkPixel: 'vk', tiktokPixel: 'C4-1'), null, null),
        );
        $service->save('en', new SettingsInput(), null, null);
        self::assertSame([null, null, null, null], $pixels($this->siteSettings()->of('ru')), 'emptied');
    }

    /**
     * @return array{?string, bool, ?string, ?string}
     */
    private static function counters(Settings $settings): array
    {
        return [$settings->yandexMetrica, $settings->yandexWebvisor, $settings->googleAnalytics, $settings->consentText];
    }

    public function testEveryNewFieldIsValidated(): void
    {
        $service = $this->settingsService();

        self::assertValidation(
            [
                'site_name' => 'validation.field.too_long',
                'site_short_name' => 'validation.field.too_long',
                'theme_color' => 'validation.settings.theme_color',
                'title_pattern' => 'validation.settings.title_pattern',
                'whatsapp' => 'validation.settings.phone',
                'viber' => 'validation.settings.phone',
                'telegram' => 'validation.settings.telegram',
                'address' => 'validation.field.too_long',
                'opening_hours' => 'validation.field.too_long',
                'social_vk' => 'validation.settings.url',
                'social_linkedin' => 'validation.field.too_long',
                'yandex_verification' => 'validation.settings.verification',
                'google_verification' => 'validation.settings.verification',
                'logo_id' => 'validation.field.media',
            ],
            static fn() => $service->save('en', new SettingsInput(
                siteName: str_repeat('a', 101),
                siteShortName: str_repeat('a', 31),
                logoId: 999,
                themeColor: 'red',
                titlePattern: '{site}',
                whatsapp: 'call me',
                viber: '123',
                telegram: 'abc',
                address: str_repeat('a', 501),
                openingHours: str_repeat('a', 501),
                social: ['vk' => 'http://vk.com/club', 'linkedin' => 'https://linkedin.com/' . str_repeat('a', 500)],
                yandexVerification: 'a b',
                googleVerification: '<meta name="x">',
            ), null, null),
            [
                'site_name' => ['max' => 100],
                'site_short_name' => ['max' => 30],
                'address' => ['max' => 500],
                'opening_hours' => ['max' => 500],
                'social_linkedin' => ['max' => 500],
            ],
        );
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM settings')));
    }

    public function testTheMapIsSiteWideAuditedAndLeftAloneByTheSettingsForm(): void
    {
        $service = $this->settingsService();

        $service->saveMap(GoogleMap::query('32.085300,34.781800', 15), null, null);
        self::assertEquals(GoogleMap::query('32.085300,34.781800', 15), $this->siteSettings()->of('ru')->map);
        self::assertSame('{"query":"32.085300,34.781800","zoom":15}', $this->db->scalar("SELECT value FROM settings WHERE name = 'contact.map' AND locale = ''"));

        $service->save('en', new SettingsInput(phone: '+1 555 0100'), null, null);
        self::assertNotNull($this->siteSettings()->of('en')->map, 'the Settings screen keeps the map');

        $service->saveMap(GoogleMap::embed('!1m2!2d34.78!3d32.08'), null, null);
        self::assertEquals(GoogleMap::embed('!1m2!2d34.78!3d32.08'), $this->siteSettings()->of('en')->map);
        $service->removeMap(null, null);
        self::assertNull($this->siteSettings()->of('en')->map);
        self::assertSame(['settings.updated', 'settings.updated', 'settings.updated', 'settings.updated'], $this->auditActions());
        self::assertSame('{"fields":["contact.map"]}', $this->db->scalar("SELECT meta FROM audit_log WHERE id = (SELECT MAX(id) FROM audit_log)"));

        $this->db->execute("INSERT INTO settings (name, locale, value, updated_at) VALUES ('contact.map', '', '{\"embed\":\"x y\"}', NOW())");
        self::assertNull($this->siteSettings()->of('en')->map, 'a broken value is no map');
    }

    public function testTheContactDetailsAreSavedAloneAndAuditedWithTheChangedNames(): void
    {
        $service = $this->settingsService();
        $service->save('en', new SettingsInput(metaTitle: 'Club', phone: '+1 000'), null, null);

        $service->saveContacts('ru', new SettingsInput(metaTitle: 'ignored', phone: '+1 000', email: 'a@example.com', address: "Улица 1\r\nМосква", social: ['vk' => 'https://vk.com/club']), null, null);

        $ru = $this->siteSettings()->of('ru');
        self::assertSame(['+1 000', 'a@example.com', "Улица 1\nМосква"], [$ru->phone, $ru->email, $ru->address]);
        self::assertEquals([new SocialLink('vk', 'https://vk.com/club')], $ru->socialLinks);
        self::assertNull($ru->metaTitle, 'only the contact details are written');
        self::assertSame('Club', $this->siteSettings()->of('en')->metaTitle);
        self::assertNull($this->siteSettings()->of('en')->address, 'the address is per language');
        self::assertSame(
            ['locale' => 'ru', 'fields' => ['contact.email', 'contact.address', 'social.vk']],
            json_decode(Cast::string($this->db->scalar('SELECT meta FROM audit_log WHERE id = (SELECT MAX(id) FROM audit_log)')), true),
        );

        $service->saveContacts('ru', new SettingsInput(phone: '+1 000', email: 'a@example.com', address: "Улица 1\nМосква", social: ['vk' => 'https://vk.com/club']), null, null);
        self::assertSame(['settings.updated', 'settings.updated'], $this->auditActions(), 'nothing changed: nothing audited');

        self::assertValidation(
            ['phone' => 'validation.field.too_long', 'social_vk' => 'validation.settings.url'],
            static fn() => $service->checkContacts('en', new SettingsInput(phone: str_repeat('1', 101), social: ['vk' => 'http://vk.com/x'])),
            ['phone' => ['max' => 100]],
        );
        self::assertValidation(
            ['whatsapp' => 'validation.settings.phone'],
            static fn() => $service->saveContacts('en', new SettingsInput(whatsapp: 'call me'), null, null),
        );
        self::assertSame('+1 000', $this->siteSettings()->of('en')->phone, 'a refused save writes nothing');
    }

    public function testTheIconMustBeASquarePngJpegOrWebpOfAtLeast512Pixels(): void
    {
        $service = $this->settingsService();
        $cases = [
            'validation.settings.icon_type' => $this->insertMedia(['mime' => 'image/gif', 'width' => 512, 'height' => 512]),
            'validation.settings.icon_square' => $this->insertMedia(['mime' => 'image/png', 'width' => 600, 'height' => 512]),
            'validation.settings.icon_small' => $this->insertMedia(['mime' => 'image/webp', 'width' => 256, 'height' => 256]),
            'validation.field.media_type' => $this->insertMedia(['mime' => 'application/pdf', 'width' => null, 'height' => null]),
        ];
        foreach ($cases as $key => $id) {
            self::assertValidation(
                ['icon_id' => $key],
                static fn() => $service->save('en', new SettingsInput(iconId: $id), null, null),
                $key === 'validation.settings.icon_small' ? ['icon_id' => ['min' => 512]] : [],
            );
        }

        $service->save('en', new SettingsInput(iconId: $jpeg = $this->insertMedia(['width' => 1024, 'height' => 1024])), null, null);
        self::assertSame($jpeg, $this->siteSettings()->of('en')->iconId);
    }

    public function testTheSiteNameFallsBackToTheThemeCatalogThenTheAppName(): void
    {
        $this->writeFile('lang/en.php', "<?php return ['site' => ['name' => 'Theme site']];");
        $translator = new Translator(new Locales('en', ['en', 'ru']), [$this->tempDir() . '/lang'], new MemoryLogger());
        $site = new SiteSettings(new SettingsRepository($this->db), $translator, 'App');

        self::assertSame('Theme site', $site->siteName('ru'), 'the catalog of the default language');
        self::assertSame('App', new SiteSettings(new SettingsRepository($this->db), null, 'App')->siteName('en'));
        $this->settingsService()->save('en', new SettingsInput(siteName: 'Fishing club'), null, null);
        self::assertSame('Fishing club', $site->siteName('en'));
        self::assertSame('Fishing club', $site->shortName('en'), 'the short name falls back to the name');
        self::assertSame('Theme site', $site->siteName('ru'), 'the name is per language');
    }

    public function testPageTitlesFollowThePattern(): void
    {
        $site = $this->siteSettings();
        $this->settingsService()->save('en', new SettingsInput(siteName: 'Club'), null, null);

        self::assertSame('Boats — Club', $site->pageTitle('Boats', 'en'));
        self::assertSame('Club', $site->pageTitle(null, 'en'));
        self::assertSame('Club', $site->pageTitle('  ', 'en'));
        $this->settingsService()->save('en', new SettingsInput(siteName: 'Club', titlePattern: '{site}: {title}'), null, null);
        self::assertSame('Club: About {site}', $site->pageTitle('About {site}', 'en'), 'a title is not expanded');
    }

    public function testTheMainMenuOrderIsSiteWideAndAuditedWhenItChanges(): void
    {
        $service = $this->settingsService();

        $service->saveMainMenuOrder(['home' => 0, 'news' => 3], null, null);
        $service->saveMainMenuOrder(['home' => 0, 'news' => 3], null, null);

        self::assertSame(['home' => 0, 'news' => 3], $this->siteSettings()->mainMenuOrder());
        self::assertSame(['main_menu.reordered'], $this->auditActions(), 'an unchanged order is not saved again');
        $this->db->execute("UPDATE settings SET value = 'oops' WHERE name = 'main_menu.order'");
        self::assertSame([], $this->siteSettings()->mainMenuOrder(), 'a broken value is no order');
    }
}
