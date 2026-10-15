<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Admin;

use AtarCms\Media\Media;
use AtarCms\Media\MediaService;
use AtarCms\Settings\AdminAccent;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use AtarCms\Settings\SocialLink;
use AtarCms\Tests\Functional\Content\ContentFunctionalTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\Regex;
use AtarCms\Tests\Support\TestImages;

final class SiteSettingsAdminTest extends ContentFunctionalTestCase
{
    public function testTheTopBarSwitchesTheLanguageAndTheMenuItemHasAGear(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();

        self::assertMatchesRegularExpression(self::navLink('/admin/settings', 'Site settings', true), $page);
        self::assertMatchesRegularExpression('~<a class="admin-nav-link" href="/admin/settings"[^>]*><svg class="admin-icon" aria-hidden="true"><use href="[^"]+#settings"></use></svg>~', $page);
        self::assertMatchesRegularExpression('~<p class="admin-nav-heading" id="nav-group-\d+">Settings</p>~', $page);
        self::assertMatchesRegularExpression('~<div class="admin-page-header">\s*<h1>Site settings</h1>\s*</div>~', $page, 'a mixed form: the pills are at the fields kept per language');
        self::assertStringContainsString('<summary class="admin-menu-trigger" title="Content language: Русский" aria-label="Content language: Русский">', $page);
        self::assertStringNotContainsString('admin-switch', $page);
        self::assertStringNotContainsString('admin-list-switch', $page);
    }

    public function testTheFieldsAreInThreeRememberedTabsWithTheImagesBeside(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=en')->getBody();

        self::assertStringContainsString('<form class="admin-entry-form" method="post" action="/admin/settings">', $page);
        self::assertStringContainsString('<div class="admin-form-column" data-tabs="admin.tabs.settings">', $page);
        foreach (['general' => 'General', 'seo' => 'SEO', 'integrations' => 'Integrations'] as $tab => $label) {
            self::assertMatchesRegularExpression('~<button type="button" class="admin-tab" role="tab" id="tab-' . $tab . '" aria-controls="panel-' . $tab . '" aria-selected="(true|false)"[^>]*>\s*' . $label . '\s*</button>~', $page);
            self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-card[^"]*" role="tabpanel" id="panel-' . $tab . '" aria-labelledby="tab-' . $tab . '">\s*<h2 class="admin-tab-heading">' . $label . '</h2>~', $page);
        }
        self::assertStringContainsString('id="tab-general" aria-controls="panel-general" aria-selected="true"', $page);
        self::assertMatchesRegularExpression('~<section class="admin-card admin-form">\s*<h2 class="admin-card-heading">Images</h2>~', $page);
        $images = substr($page, (int) strpos($page, '<h2 class="admin-card-heading">Images</h2>'));
        foreach (['logo_id', 'icon_id', 'og_image_id'] as $name) {
            self::assertStringContainsString('name="' . $name . '"', $images);
        }
        self::assertMatchesRegularExpression('~<input type="checkbox" role="switch" id="hide_from_search" name="hide_from_search" value="1">~', $page);
        $general = Regex::match('~id="panel-general".*?</section>~s', $page);
        self::assertMatchesRegularExpression('~<div class="admin-field" role="group" aria-labelledby="accessibility_toolbar-label">\s*<span class="admin-field-label" id="accessibility_toolbar-label"><svg[^>]*><use href="[^"]+#accessibility"></use></svg>Accessibility toolbar</span>~s', $general[0], 'a heading with its icon');
        self::assertMatchesRegularExpression('~<div class="admin-toolbar-row">\s*<label class="admin-toggle" for="accessibility_toolbar"><input type="checkbox" role="switch" id="accessibility_toolbar" name="accessibility_toolbar" value="1" checked aria-labelledby="accessibility_toolbar-label"><span class="admin-toggle-on" aria-hidden="true">On</span><span class="admin-toggle-off" aria-hidden="true">Off</span></label>\s*<span class="admin-toolbar-position"><span class="admin-toolbar-position-label" id="accessibility_toolbar_position-label">Position:</span>~', $general[0], 'the switch (on by default) and the position in one row');
        self::assertMatchesRegularExpression('~</span>\s*</div>\s*<span class="admin-help">A round button in a corner of every page opens text size, contrast and other display options for visitors\.</span>\s*</div>~', $general[0], 'one help line under both');
        self::assertSame(4, preg_match_all('~<input type="radio" class="admin-visually-hidden" name="accessibility_toolbar_position" value="(top-start|bottom-start|top-end|bottom-end)"~', $general[0]));
        self::assertMatchesRegularExpression('~value="bottom-end" checked><span class="admin-media-option-text" data-option-text>Bottom right</span>~', $general[0], 'bottom right by default');
        self::assertStringContainsString('<input type="color" id="theme_color" name="theme_color" value="#1a73e8">', $page);
        self::assertStringContainsString('<label class="admin-check"><input type="checkbox" name="theme_color_on" value="1"> Use theme</label>', $page);
        self::assertMatchesRegularExpression('~<script type="module" src="/admin-assets/tabs\.js\?v=~', $page);
        self::assertMatchesRegularExpression('~<label for="theme_color"><svg[^>]*><use href="[^"]+#palette"></use></svg>Theme color</label>~', $page);
        self::assertMatchesRegularExpression('~<input id="site_name" name="site_name" maxlength="100"[^>]*>\s*<span class="admin-help">Up to 100 characters</span>~', $page);
        self::assertMatchesRegularExpression('~<input id="site_short_name" name="site_short_name" maxlength="30"[^>]*>\s*<span class="admin-help">Up to 30 characters</span>~', $page);
        self::assertMatchesRegularExpression('~<label for="meta_title"><svg[^>]*>.*?</svg>Site title<span class="admin-locale-badge"~', $page);
        self::assertStringContainsString('OgImage', $images);
        foreach (['logo_id' => 'gem', 'icon_id' => 'app-window', 'og_image_id' => 'share-2'] as $name => $icon) {
            self::assertMatchesRegularExpression('~<label for="' . $name . '"><svg[^>]*><use href="[^"]+#' . $icon . '"~', $images);
        }
        self::assertStringContainsString('<span class="admin-help">Image for social sharing</span>', $images);
        foreach (['The colour of the browser', 'The home page’s title in search results', 'Pages without their own description use it.', 'Shown in the site’s header.', ': the icon in the browser tab'] as $gone) {
            self::assertStringNotContainsString($gone, $page);
        }
    }

    public function testPerLanguageFieldsCarryTheLanguageBadge(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();

        $badge = '<span class="admin-locale-badge" title="Kept separately for each language, now: Русский" aria-hidden="true">RU</span>';
        self::assertSame(5, substr_count($page, $badge), 'site name, short name, home title, description, cookie banner text');
        self::assertMatchesRegularExpression('~<label for="site_name"><svg[^>]*>.*?</svg>Site name' . preg_quote($badge, '~') . '~', $page);
        self::assertDoesNotMatchRegularExpression('~<label for="title_pattern">[^\n]*admin-locale-badge~', $page);
        self::assertStringNotContainsString('admin-help">Fields marked', $page, 'no line explaining the badges');
    }

    public function testAnAdministratorSavesEverythingAndReloadsPerLanguage(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        // The contact details are the contact page's: the screen keeps the stored ones and ignores posted ones.
        $this->app->container()->service(SettingsService::class)->saveContacts('ru', new SettingsInput(
            phone: '+7 900 000-00-00',
            whatsapp: '+7 900 000-00-01',
            address: "Речная, 1\nЛодочная станция",
            social: ['vk' => 'https://vk.com/club'],
        ), null, null);
        $icon = $this->upload(TestImages::png(512, 512), 'icon.png', 'image/png');
        $logo = $this->upload(TestImages::jpeg(), 'logo.jpg', 'image/jpeg');
        $this->browser->get('/admin/settings?locale=ru');

        $response = $this->browser->post('/admin/settings', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'ru',
            'site_name' => 'Клуб <рыбаков>',
            'site_short_name' => 'Клуб',
            'theme_color' => '#1A2B3C',
            'theme_color_on' => '1',
            'meta_title' => 'Клуб рыбаков на реке',
            'meta_description' => 'Мы ловим рыбу.',
            'title_pattern' => '{title} | {site}',
            'hide_from_search' => '1',
            'accessibility_toolbar' => '1',
            'accessibility_toolbar_position' => 'top-start',
            'phone' => '',
            'email' => 'club@example.com',
            'address' => 'Ignored',
            'social_vk' => '',
            'google_verification' => 'g00gle',
            'logo_id' => (string) $logo->id,
            'icon_id' => (string) $icon->id,
            'og_image_id' => '',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/settings?locale=ru', $response->getHeaderLine('Location'));
        $page = (string) $this->browser->get('/admin/settings?locale=ru')->getBody();
        self::assertStringContainsString('Settings saved.', $page);
        self::assertStringContainsString('<input id="site_name" name="site_name" maxlength="100" value="Клуб &lt;рыбаков&gt;" lang="ru" dir="ltr">', $page);
        self::assertStringContainsString('<input type="color" id="theme_color" name="theme_color" value="#1a2b3c">', $page);
        self::assertStringContainsString('<input type="checkbox" name="theme_color_on" value="1" checked>', $page);
        self::assertStringNotContainsString('name="whatsapp"', $page);
        self::assertStringNotContainsString('name="address"', $page);
        self::assertStringNotContainsString('name="social_vk"', $page);
        self::assertStringContainsString('id="hide_from_search" name="hide_from_search" value="1" checked>', $page);
        $site = $this->app->container()->service(SiteSettings::class);
        $ru = $site->of('ru');
        self::assertSame(['Клуб <рыбаков>', 'Клуб', 'Клуб рыбаков на реке', "Речная, 1\nЛодочная станция"], [$ru->siteName, $ru->siteShortName, $ru->metaTitle, $ru->address]);
        self::assertSame([$logo->id, $icon->id, null, '#1a2b3c', true, true], [$ru->logoId, $ru->iconId, $ru->ogImageId, $ru->themeColor, $ru->hideFromSearch, $ru->accessibilityToolbar]);
        self::assertSame('top-start', $ru->accessibilityToolbarPosition);
        self::assertEquals([new SocialLink('vk', 'https://vk.com/club')], $ru->socialLinks);
        self::assertSame(['+7 900 000-00-00', '+79000000001', null], [$ru->phone, $ru->whatsapp, $ru->email], 'the stored contact details are kept');
        $en = $site->of('en');
        self::assertSame([null, null, '+7 900 000-00-00', $logo->id], [$en->siteName, $en->address, $en->phone, $en->logoId], 'the language values stay in their language');
        $enPage = (string) $this->browser->get('/admin/settings?locale=en')->getBody();
        self::assertStringContainsString('<input id="site_name" name="site_name" maxlength="100" value="" lang="en" dir="ltr">', $enPage);
        self::assertSame(['settings.updated', 'media.uploaded', 'media.uploaded', 'settings.updated'], $this->auditActions());

        $this->browser->post('/admin/settings', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'theme_color' => '#ffffff', 'logo_id' => (string) $logo->id]);
        self::assertNull($site->of('en')->themeColor, 'without the checkbox there is no colour');
        self::assertFalse($site->of('en')->hideFromSearch);
        self::assertFalse($site->of('en')->accessibilityToolbar, 'an unchecked switch turns the toolbar off');
        self::assertStringContainsString('id="accessibility_toolbar" name="accessibility_toolbar" value="1" aria-labelledby="accessibility_toolbar-label">', (string) $this->browser->get('/admin/settings?locale=en')->getBody());
    }

    public function testMistakesStayAtTheirFieldsAndMarkTheirTab(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $small = $this->upload(TestImages::png(300, 200), 'wide.png', 'image/png');
        $this->browser->get('/admin/settings');

        $response = $this->browser->post('/admin/settings', [
            '_csrf' => $this->csrfToken(),
            'locale' => 'en',
            'site_name' => 'Club',
            'title_pattern' => '{site}',
            'yandex_verification' => 'a b',
            'icon_id' => (string) $small->id,
        ]);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p>Please fix 3 fields.</p>', $body);
        self::assertStringContainsString('value="Club"', $body);
        self::assertStringContainsString('id="tab-seo" aria-controls="panel-seo" aria-selected="true" tabindex="0" data-tab="seo" data-errors="1"', $body);
        self::assertStringContainsString('data-tab="integrations" data-errors="1"', $body);
        self::assertStringContainsString('data-tab="general" data-errors="0"', $body);
        self::assertMatchesRegularExpression('~<section class="admin-tab-panel admin-card admin-tab-panel-open" role="tabpanel" id="panel-seo"~', $body);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="title_pattern-error"', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="yandex_verification-error">', $body);
        self::assertStringContainsString('<span class="admin-field-error" id="icon_id-error">The site icon must be square.</span>', $body);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="icon_id-error"', $body);
        self::assertEquals(new Settings(), $this->app->container()->service(SiteSettings::class)->of('en'));

        $response = $this->browser->post('/admin/settings', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'logo_id' => 'abc']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<span class="admin-field-error" id="logo_id-error">Choose files that already exist in “Photos and files”.</span>', (string) $response->getBody());
    }

    /**
     * The contact details and the map moved to the contact page's entry form (Plan 26).
     */
    public function testTheScreenHasNoContactsSocialOrMap(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings?locale=en')->getBody();
        foreach (['tab-contacts', 'tab-social', 'name="phone"', 'name="address"', 'name="opening_hours"', 'name="social_vk"', 'map_', 'admin-map'] as $gone) {
            self::assertStringNotContainsString($gone, $page, $gone);
        }
    }

    public function testTheThemeColourOffersThePresetsAndThereIsNoAdminColour(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));

        $page = (string) $this->browser->get('/admin/settings')->getBody();

        self::assertStringNotContainsString('admin_accent', $page);
        self::assertStringNotContainsString('Admin color', $page);
        $field = Regex::match('~<div class="admin-color-field">.*?</div>\s*<span class="admin-help">Also picks the color of the default site icon and of the admin\.</span>\s*</div>\s*<div class="admin-field" role="group" aria-labelledby="accessibility_toolbar-label">~s', $page, 'the help line, then the accessibility toolbar');
        self::assertMatchesRegularExpression('~<span class="admin-color-presets" data-color-presets>\s*<details class="admin-menu admin-media-dropdown" data-media-dropdown>\s*<summary class="admin-media-dropdown-trigger" title="Preset" aria-label="Preset"[^>]*><span class="admin-media-dropdown-current" data-dropdown-current><span class="admin-media-option-text" data-option-text>Preset</span></span>~', $field[0], 'without a matching colour the button names the list');
        self::assertSame(8, preg_match_all('~<label class="admin-media-option"><input type="radio" class="admin-visually-hidden" name="theme_color_preset" value="(#[0-9a-f]{6})"><span class="admin-media-option-icon" data-option-icon><span class="admin-accent-swatch admin-accent-swatch-([a-z]+)"></span></span><span class="admin-media-option-text" data-option-text>([^<]+)</span>~', $field[0], $options));
        foreach (AdminAccent::cases() as $index => $accent) {
            self::assertSame([$accent->logoColor(), $accent->value], [$options[1][$index], $options[2][$index]]);
        }
        self::assertSame(['Green', 'Teal', 'Blue', 'Indigo', 'Violet', 'Cherry', 'Terracotta', 'Graphite'], $options[3]);
    }

    public function testThePresetOfTheCurrentThemeColourIsShownAndThePresetRadiosAreNotStored(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->app->container()->service(SettingsService::class)->save('en', new SettingsInput(themeColor: AdminAccent::Teal->logoColor()), null, null);

        $page = (string) $this->browser->get('/admin/settings')->getBody();
        self::assertStringContainsString('name="theme_color_preset" value="#1f7a80" checked>', $page);
        self::assertStringContainsString('title="Preset: Teal" aria-label="Preset: Teal"', $page);

        $response = $this->browser->post('/admin/settings', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'theme_color_on' => '1', 'theme_color' => '#2563b8', 'theme_color_preset' => '#7a45a8', 'admin_accent' => 'violet']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('#2563b8', $this->app->container()->service(SiteSettings::class)->of('en')->themeColor, 'the colour input is what is saved');
        self::assertSame(0, Cast::int($this->app->container()->service(\AtarCms\Database\Connection::class)->scalar("SELECT COUNT(*) FROM settings WHERE name = 'admin.accent'")), 'an old admin colour field is ignored');
    }

    public function testAnUnsupportedLanguageIsAToast(): void
    {
        $this->actingAs($this->createUser('admin@example.com'));
        $this->browser->get('/admin/settings');

        $response = $this->browser->post('/admin/settings', ['_csrf' => $this->csrfToken(), 'locale' => 'de', 'site_name' => 'Club']);
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertMatchesRegularExpression('~<div class="admin-toast admin-toast-error" role="alert" data-toast>.*?This language is not available~s', $body);
        self::assertStringNotContainsString('<div class="admin-alert" role="alert">', $body);
        self::assertStringContainsString('value="Club"', $body);
    }

    public function testOnlyAdministratorsManageTheSettings(): void
    {
        $this->actingAs($this->createUser('ed@example.com', 'editor'));

        self::assertStringNotContainsString('/admin/settings', (string) $this->browser->get('/admin')->getBody());
        self::assertSame(403, $this->browser->get('/admin/settings')->getStatusCode());
        self::assertSame(403, $this->browser->post('/admin/settings', ['_csrf' => $this->csrfToken(), 'locale' => 'en', 'phone' => '1'])->getStatusCode());
        self::assertEquals(new Settings(), $this->app->container()->service(SiteSettings::class)->of('en'));
    }

    private function upload(string $contents, string $name, string $type): Media
    {
        return $this->app->container()->service(MediaService::class)->upload(TestImages::uploaded($contents, $name, $type), null, null);
    }
}
