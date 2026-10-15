<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\InterfaceLanguages;

use AtarCms\Admin\LanguageMode;
use AtarCms\Auth\UserRepository;
use AtarCms\I18n\Locales;
use AtarCms\Tests\Functional\LinkedLanguage\LinkedLanguageTestCase;
use AtarCms\Tests\Support\Regex;

/**
 * German and French are interface languages (Plan 39): a site with German and French content gets a German and a
 * French admin in the linked mode (not the English fallback), left to right, the editor's own interface translated;
 * in the separate mode the Profile offers both.
 */
final class GermanFrenchAdminTest extends LinkedLanguageTestCase
{
    protected function setUp(): void
    {
        $this->locales = new Locales('en', ['en', 'de', 'fr']);
        parent::setUp();
    }

    public function testTheLinkedAdminSpeaksTheContentLanguage(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann'));

        foreach (['de', 'fr'] as $locale) {
            $dashboard = (string) $this->browser->get('/admin?locale=' . $locale)->getBody();

            self::assertStringContainsString('<html lang="' . $locale . '" dir="ltr" data-accent="green">', $dashboard, $locale);
            self::assertStringContainsString(self::message($locale, 'admin.dashboard.welcome', ['name' => 'Ann']), $dashboard, $locale);
            self::assertStringContainsString(self::message($locale, 'admin.layout.sign_out'), $dashboard, $locale);
            self::assertStringNotContainsString('Hello, Ann!', $dashboard, $locale . ': no English fallback');
        }
    }

    public function testTheBrowsersLanguageChoosesGermanOrFrench(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann'));

        $dashboard = (string) $this->browser->get('/admin', ['Accept-Language' => 'fr-CH, fr;q=0.9, en;q=0.5'])->getBody();
        self::assertStringContainsString('<html lang="fr" dir="ltr"', $dashboard);
        self::assertStringContainsString(self::message('fr', 'admin.dashboard.welcome', ['name' => 'Ann']), $dashboard);
    }

    public function testTheLoginPageFollowsTheBrowser(): void
    {
        $login = (string) $this->browser->get('/admin/login', ['Accept-Language' => 'de-DE,de;q=0.9'])->getBody();

        self::assertStringContainsString('<html lang="de" dir="ltr"', $login);
        self::assertStringContainsString(self::message('de', 'admin.login.title'), $login);
    }

    public function testTheEditorLoadsItsGermanAndFrenchInterface(): void
    {
        $this->actingAs($this->createUser('ann@example.com', 'admin', 'Ann'));

        foreach (['de', 'fr'] as $locale) {
            $form = (string) $this->browser->get('/admin/content/articles/new?locale=' . $locale)->getBody();
            $match = Regex::match('~<textarea [^>]*data-rich-text[^>]* data-editor="([^"]*)"~', $form, $locale);
            $settings = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);
            self::assertIsArray($settings);

            self::assertSame($locale, $settings['language'], 'editor.js imports ckeditor5-48.5.2-' . $locale . '.js for it');
            self::assertStringContainsString('<html lang="' . $locale . '" dir="ltr"', $form);
        }
        self::assertFileExists(__DIR__ . '/../../../resources/admin/assets/vendor/ckeditor5-48.5.2-de.js');
        self::assertFileExists(__DIR__ . '/../../../resources/admin/assets/vendor/ckeditor5-48.5.2-fr.js');
    }

    public function testTheSeparateModesProfileOffersGermanAndFrench(): void
    {
        $this->mode = LanguageMode::Separate;
        $this->bootApp();
        $ann = $this->createUser('ann@example.com', 'admin', 'Ann');
        $this->actingAs($ann);

        $profile = (string) $this->browser->get('/admin/profile')->getBody();
        self::assertMatchesRegularExpression('~name="locale" value="de" aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="de" dir="ltr">Deutsch</span>~', $profile);
        self::assertMatchesRegularExpression('~name="locale" value="fr" aria-describedby="locale-help"><span class="admin-media-option-icon" data-option-icon>.*?</span><span class="admin-media-option-text" data-option-text lang="fr" dir="ltr">Français</span>~', $profile);

        foreach (['de', 'fr'] as $locale) {
            $response = $this->browser->post('/admin/profile', ['_csrf' => $this->csrfToken(), 'locale' => $locale]);
            self::assertSame(303, $response->getStatusCode(), $locale);
            self::assertSame($locale, $this->app->container()->service(UserRepository::class)->find($ann->id)?->locale);

            $dashboard = (string) $this->browser->get('/admin')->getBody();
            self::assertStringContainsString('<html lang="' . $locale . '" dir="ltr"', $dashboard, $locale);
            self::assertStringContainsString(self::message($locale, 'admin.dashboard.welcome', ['name' => 'Ann']), $dashboard, $locale);
        }
    }

    /**
     * A message of the core catalog, formatted and HTML-escaped as the admin prints it.
     *
     * @param array<string, string> $args
     */
    private static function message(string $locale, string $key, array $args = []): string
    {
        $value = require __DIR__ . '/../../../resources/lang/' . $locale . '.php';
        foreach (explode('.', $key) as $segment) {
            self::assertIsArray($value, $key);
            $value = $value[$segment] ?? null;
        }
        self::assertIsString($value, $key);
        $text = $args === [] ? $value : \MessageFormatter::formatMessage($locale, $value, $args);
        self::assertIsString($text, $key);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
