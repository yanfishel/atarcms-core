<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Cli\Output;
use AtarCms\Cli\Prompt;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Kernel\Paths;
use AtarCms\Messages\MessageRepository;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Support\Cast;

/**
 * bin/atar demo:seed on the skeleton site: it deletes the content and fills every language with demo content.
 */
final class DemoSeedTest extends SkeletonTestCase
{
    public function testWithoutATerminalItNeedsForce(): void
    {
        $this->app->container()->set(Prompt::class, new Prompt(self::stream(''), false));
        [$code, $out] = $this->console(['demo:seed']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Run it with --force to confirm without a terminal.', $out);
    }

    public function testASiteThatCannotBeSeededLosesNothing(): void
    {
        $container = $this->app->container();
        $container->set(\AtarCms\Media\MediaConfig::class, \AtarCms\Media\MediaConfig::fromConfig(new \AtarCms\Kernel\Config(['media' => ['types' => ['application/pdf']]])));
        $this->createUser('admin@example.com');
        $container->service(MessageRepository::class)->insert(['form' => 'contact', 'locale' => 'en', 'name' => 'Old', 'email' => 'old@example.com', 'body' => 'An old message', 'page_url' => 'https://localhost/contacts', 'ip_hash' => str_repeat('b', 64), 'created_at' => new \DateTimeImmutable()]);

        [$code, $out] = $this->console(['demo:seed', '--force']);

        self::assertSame(1, $code);
        self::assertStringContainsString('The demo content cannot be made on this site; nothing was deleted:', $out);
        self::assertStringContainsString('- config/media.php must allow image/jpeg uploads', $out);
        self::assertSame(1, $container->service(MessageRepository::class)->count());
    }

    public function testInProductionItWarnsAndCountsEvenWithForce(): void
    {
        $this->app->container()->service(MessageRepository::class)->insert(['form' => 'contact', 'locale' => 'en', 'name' => 'Old', 'email' => 'old@example.com', 'body' => 'An old message', 'page_url' => 'https://localhost/contacts', 'ip_hash' => str_repeat('b', 64), 'created_at' => new \DateTimeImmutable()]);

        [$code, $out] = $this->console(['demo:seed', '--force']);

        self::assertSame(0, $code, $out);
        self::assertLessThan(strpos($out, 'Deleted '), strpos($out, 'Warning: this site runs in the production environment.'));
        self::assertStringContainsString('This deletes 0 entries, 0 sections and tags, 0 menu items, 0 files and 1 messages', $out);
    }

    /**
     * What an agent runs before asking: the content demo:seed would delete, without a terminal and without --force.
     */
    public function testADryRunCountsAndChangesNothing(): void
    {
        $this->app->container()->set(Prompt::class, new Prompt(self::stream(''), false));
        $this->app->container()->service(MessageRepository::class)->insert(['form' => 'contact', 'locale' => 'en', 'name' => 'Old', 'email' => 'old@example.com', 'body' => 'An old message', 'page_url' => 'https://localhost/contacts', 'ip_hash' => str_repeat('b', 64), 'created_at' => new \DateTimeImmutable()]);

        [$code, $out] = $this->console(['demo:seed', '--dry-run']);

        self::assertSame(0, $code, $out);
        self::assertSame("Dry run: demo:seed would delete 0 entries, 0 sections and tags, 0 menu items, 0 files and 1 messages, and the site settings.\nNothing was changed.\n", $out);
        self::assertSame(1, $this->app->container()->service(MessageRepository::class)->count());
    }

    public function testADryRunReportsWhyTheSiteCannotBeSeeded(): void
    {
        $this->app->container()->set(\AtarCms\Media\MediaConfig::class, \AtarCms\Media\MediaConfig::fromConfig(new \AtarCms\Kernel\Config(['media' => ['types' => ['application/pdf']]])));

        $cache = $this->app->container()->service(Paths::class)->storage('cache');
        if (is_dir($cache) && (scandir($cache) ?: []) === ['.', '..']) {
            rmdir($cache);
        }

        [$code, $out] = $this->console(['demo:seed', '--dry-run']);

        self::assertSame(1, $code);
        self::assertStringStartsWith('Dry run: demo:seed would delete 0 entries', $out, 'the counts come first');
        self::assertStringContainsString("Nothing was changed.\nThe demo content cannot be made on this site:\n- config/media.php must allow image/jpeg uploads", $out);
        self::assertDirectoryDoesNotExist($cache, 'a dry run creates no directories');
    }

    public function testItAsksFirst(): void
    {
        $this->app->container()->set(Prompt::class, new Prompt(self::stream("no\n"), true));
        [$code, $out] = $this->console(['demo:seed']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Type "yes" to continue:', $out);
        self::assertStringContainsString('Nothing was changed.', $out);
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
    }

    public function testItReplacesTheContentWithDemoContentInEveryLanguage(): void
    {
        $container = $this->app->container();
        $admin = $this->createUser('admin@example.com');
        $settings = $container->service(SettingsRepository::class);
        $now = new \DateTimeImmutable();
        $settings->put(SiteSettings::PHONE, '', '+1 000', $now);
        $settings->put(SiteSettings::ACCESSIBILITY_TOOLBAR, '', '0', $now);
        $settings->put(SiteSettings::ACCESSIBILITY_TOOLBAR_POSITION, '', 'top-start', $now);
        $settings->put(SiteSettings::CHAT_BUTTON, '', '1', $now);
        $settings->put(SiteSettings::YANDEX_METRICA, '', '12345678', $now);
        $settings->put(SiteSettings::YANDEX_WEBVISOR, '', '1', $now);
        $settings->put(SiteSettings::GOOGLE_ANALYTICS, '', 'G-TEST123456', $now);
        $settings->put(SiteSettings::CONSENT_TEXT, 'en', 'Old banner text', $now);
        $settings->put(SiteSettings::META_PIXEL, '', '123456789012345', $now);
        $settings->put(SiteSettings::GOOGLE_ADS, '', 'AW-123456789', $now);
        $settings->put(SiteSettings::VK_PIXEL, '', '3456789', $now);
        $settings->put(SiteSettings::TIKTOK_PIXEL, '', 'C4ABCDEFGH1234567890', $now);
        $container->service(MessageRepository::class)->insert(['form' => 'contact', 'locale' => 'en', 'name' => 'Old', 'email' => 'old@example.com', 'body' => 'An old message', 'page_url' => 'https://localhost/contacts', 'ip_hash' => str_repeat('b', 64), 'created_at' => $now]);
        $this->app->container()->set(Prompt::class, new Prompt(self::stream("yes\n"), true));

        [$code, $out] = $this->console(['demo:seed']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Deleted 0 entries, 0 sections and tags, 0 menu items, 0 files, 1 messages.', $out);
        self::assertMatchesRegularExpression('~en: 9 sections, 3 slides, 1 home, 1 contacts, 6 news, 12 articles, 3 pages, 2 menu items\.~', $out);
        self::assertMatchesRegularExpression('~he: 9 sections, 3 slides, 1 home, 1 contacts, 6 news, 12 articles, 3 pages, 2 menu items\.~', $out);
        // The home page's slides: the hero pictures, a button to the news, a slide without a link, one linking a page.
        foreach (['' => 'en', '/ru' => 'ru'] as $prefix => $locale) {
            $hero = json_decode(Cast::string($this->db->scalar("SELECT JSON_EXTRACT(data, '$.hero') FROM entries WHERE collection = 'home' AND locale = ?", [$locale])), true);
            self::assertIsArray($hero, $locale);
            self::assertCount(3, $hero, $locale);
            self::assertContainsOnlyArray($hero, $locale);
            self::assertSame([$prefix . '/news', null, $prefix . '/about'], array_map(static fn(array $slide): mixed => $slide['url'] ?? null, $hero), $locale);
            self::assertArrayHasKey('button', $hero[0]);
            self::assertArrayNotHasKey('button', $hero[2], 'a linked slide without a button');
        }
        self::assertMatchesRegularExpression('~Uploaded 17 demo pictures\. Done in [\d.]+ s\.~', $out);

        $entries = $container->service(EntryRepository::class);
        foreach (['en', 'ru', 'he'] as $locale) {
            self::assertSame(12, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries WHERE collection = ? AND locale = ? AND status = ?', ['articles', $locale, 'published'])));
        }
        $groups = Cast::int($this->db->scalar('SELECT COUNT(DISTINCT translation_group) FROM entries WHERE collection = ?', ['articles']));
        self::assertSame(12, $groups, 'the translations of an article share its group');
        self::assertSame(0, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries e WHERE e.collection = ? AND NOT EXISTS (SELECT 1 FROM entry_terms et JOIN terms t ON t.id = et.term_id WHERE et.entry_id = e.id AND t.taxonomy = ? AND t.locale = e.locale)', ['articles', 'sections'])), 'every article has a section of its language');
        self::assertCount(2, $container->service(MenuRepository::class)->forLocation('footer', 'ru'), 'the footer: a page and the contacts page (the theme links the preset pages itself)');
        // The preset pages, made again after the wipe: the accessibility statement (a template naming the standard,
        // the toolbar and the site's contact details) and the privacy policy (the form, the browser, the map).
        $text = fn(string $preset, string $locale): string => html_entity_decode(Cast::string($this->db->scalar("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.body')) FROM entries WHERE collection = 'pages' AND preset = ? AND locale = ? AND status = 'published'", [$preset, $locale])), ENT_QUOTES | ENT_HTML5);
        foreach (['en' => ['SI 5568', 'Google Maps'], 'ru' => ['WCAG 2.1', 'Google Maps'], 'he' => ['ת״י 5568', 'Google Maps']] as $locale => [$standard, $map]) {
            foreach (['accessibility' => $standard, 'privacy' => $map] as $preset => $words) {
                $body = $text($preset, $locale);
                self::assertStringContainsString($words, $body, $locale . ' ' . $preset);
                self::assertStringContainsString('<a href="mailto:hello@example.com">hello@example.com</a>', $body, $locale . ' ' . $preset);
                self::assertStringContainsString("<span dir=\"ltr\">+1\u{00A0}555\u{00A0}0100</span>", $body, $locale . ': the number never breaks');
                self::assertStringContainsString('<h2>', $body, $locale);
            }
        }
        self::assertStringContainsString('Atar Demo', $text('accessibility', 'en'), 'the site name');
        self::assertSame(['Privacy policy', 'Политика конфиденциальности', 'הצהרת נגישות'], [
            $entries->presetPages('pages', 'privacy')['en']->title ?? null,
            $entries->presetPages('pages', 'privacy')['ru']->title ?? null,
            $entries->presetPages('pages', 'accessibility')['he']->title ?? null,
        ], 'titled by their labels');
        self::assertNull($container->service(MessageRepository::class)->find(1));
        $site = $container->service(SiteSettings::class);
        self::assertSame(['Атар Демо', 'teal', '+1 555 0100', '32.085300,34.781800'], [$site->of('ru')->siteName, $site->of('ru')->accent()->value, $site->of('ru')->phone, $site->of('ru')->map?->query]);
        self::assertFalse($site->of('en')->accessibilityToolbar, 'the toolbar switch is the owner\'s, like hiding from search engines');
        self::assertSame('top-start', $site->of('en')->accessibilityToolbarPosition, 'and so is its corner');
        self::assertTrue($site->of('en')->chatButton, 'and the chat button switch');
        self::assertSame(['12345678', true, 'G-TEST123456', null], [$site->of('en')->yandexMetrica, $site->of('en')->yandexWebvisor, $site->of('en')->googleAnalytics, $site->of('en')->consentText], 'the counters stay, the banner text is content');
        self::assertSame(['123456789012345', 'AW-123456789', '3456789', 'C4ABCDEFGH1234567890'], [$site->of('en')->metaPixel, $site->of('en')->googleAds, $site->of('en')->vkPixel, $site->of('en')->tiktokPixel], 'and so do the advertising pixels');
        self::assertSame(['home' => 0, 'news' => 4, 'contacts' => 5], $site->mainMenuOrder());
        self::assertNotNull($container->service(\AtarCms\Auth\UserRepository::class)->findByEmail('admin@example.com'));
        self::assertSame((string) $admin->id, Cast::string($this->db->scalar('SELECT author_id FROM entries WHERE preset IS NULL ORDER BY id LIMIT 1')), 'the first administrator is the author (the preset pages are the system\'s)');
        self::assertContains('demo.seeded', $this->auditActions());
        $shared = Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries WHERE share_image IS NOT NULL'));
        self::assertGreaterThan(30, $shared, 'share images are drawn as on every save');
        $uploads = $container->service(Paths::class)->storage('uploads');
        self::assertNotSame([], glob($uploads . '/share/*.jpg') ?: []);

        // Again: the same content, the old files gone.
        $before = $entries->find(Cast::int($this->db->scalar('SELECT MIN(id) FROM entries')))?->slug;
        $oldFiles = glob($uploads . '/*/*/*.jpg') ?: [];
        [$code, $out] = $this->console(['demo:seed', '--force']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Deleted 69 entries, ', $out);
        self::assertSame(69, Cast::int($this->db->scalar('SELECT COUNT(*) FROM entries')));
        self::assertSame($before, $entries->find(Cast::int($this->db->scalar('SELECT MIN(id) FROM entries')))?->slug, 'the same content');
        foreach ($oldFiles as $file) {
            self::assertFileDoesNotExist($file);
        }

        foreach (['/', '/ru', '/he', '/contacts', '/accessibility', '/he/accessibility', '/privacy', '/ru/privacy', '/news', '/he/news', '/services/consulting', '/about'] as $path) {
            self::assertSame(200, $this->browser->get($path)->getStatusCode(), $path);
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function console(array $argv): array
    {
        $stdout = self::stream('');
        $stderr = self::stream('');
        $code = $this->app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = fopen('php://memory', 'w+') ?: self::fail('No stream');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
