<?php

declare(strict_types=1);

namespace AtarCms\Tests\Functional\Skeleton;

use AtarCms\Cli\Output;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Support\Cast;

/**
 * bin/atar settings:import on the skeleton site (en, ru, he): the brief's real values after the demo content.
 */
final class SettingsImportCommandTest extends SkeletonTestCase
{
    public function testWritesTheValuesOfEveryLanguageAndKeepsTheRest(): void
    {
        $admin = $this->createUser('admin@example.com');
        $now = new \DateTimeImmutable();
        $repository = $this->app->container()->service(SettingsRepository::class);
        $repository->put(SiteSettings::EMAIL, '', 'old@example.com', $now);
        $repository->put(SiteSettings::SITE_NAME, 'he', 'ישן', $now);
        $repository->put(SiteSettings::SOCIAL . 'vk', '', 'https://vk.com/old', $now);
        $file = $this->writeFile('settings.json', json_encode([
            'site' => ['phone' => '+972 3 555 0100', 'whatsapp' => '+972 50 555 0100', 'social' => ['youtube' => 'https://www.youtube.com/@clinic'], 'map' => ['latitude' => 32.0853, 'longitude' => 34.7818, 'zoom' => 15]],
            'locales' => ['en' => ['site_name' => 'Clinic', 'address' => "1 Main St\nTel Aviv"], 'ru' => ['site_name' => 'Клиника']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        [$code, $out] = $this->console(['settings:import', $file]);

        self::assertSame(0, $code, $out);
        self::assertSame("Saved the site settings: en, ru.\n", $out);
        $site = $this->app->container()->service(SiteSettings::class);
        $en = $site->of('en');
        self::assertSame(['Clinic', "1 Main St\nTel Aviv", '+972 3 555 0100', '+972505550100', 'old@example.com', ['query' => '32.085300,34.781800', 'zoom' => 15]], [$en->siteName, $en->address, $en->phone, $en->whatsapp, $en->email, $en->map?->toArray()]);
        self::assertSame(['youtube' => 'https://www.youtube.com/@clinic', 'vk' => 'https://vk.com/old'], array_column(array_map(static fn($l): array => (array) $l, $en->socialLinks), 'url', 'network'));
        self::assertSame('Клиника', $site->of('ru')->siteName);
        self::assertSame('ישן', $site->of('he')->siteName, 'a language not listed is kept');
        self::assertSame((string) $admin->id, Cast::string($this->db->scalar("SELECT user_id FROM audit_log WHERE action = 'settings.updated'")));
        self::assertSame(['locales' => ['en', 'ru'], 'source' => 'import'], json_decode(Cast::string($this->db->scalar("SELECT meta FROM audit_log WHERE action = 'settings.updated'")), true));
    }

    public function testInvalidValuesAreListedAndNothingIsWritten(): void
    {
        $file = $this->writeFile('settings.json', (string) json_encode([
            'site' => ['email' => 'not-an-email', 'social' => ['vk' => 'http://vk.com/x'], 'map' => ['link' => 'https://maps.app.goo.gl/abc']],
            'locales' => ['he' => ['site_name' => 'מרפאה']],
        ]));

        [$code, $out] = $this->console(['settings:import', $file]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Nothing was saved:', $out);
        self::assertStringContainsString('- site.email: validation.email.invalid (', $out);
        self::assertStringContainsString('- site.social.vk: ', $out);
        self::assertStringContainsString('- site.map.link: validation.map.link_short (A short link cannot be read', $out);
        self::assertNull($this->app->container()->service(SiteSettings::class)->of('he')->siteName);

        $this->writeFile('bad.json', '{"site": {"fax": "1"}}');
        [$code, $out] = $this->console(['settings:import', $this->tempDir() . '/bad.json']);
        self::assertSame(1, $code);
        self::assertStringContainsString('- Unknown key "site.fax"', $out);

        $this->writeFile('empty.json', '{"locales": {}}');
        [$code, $out] = $this->console(['settings:import', $this->tempDir() . '/empty.json']);
        self::assertSame(1, $code);
        self::assertSame("Nothing was saved:\n- The file names no settings: give \"site\" values or a language under \"locales\"\n", $out);

        [$code, $out] = $this->console(['settings:import', $this->tempDir() . '/missing.json']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Cannot read', $out);
    }

    public function testADryRunValidatesOnlyAndTheExampleRoundTrips(): void
    {
        $file = $this->writeFile('settings.json', '{"locales": {"en": {"site_name": "Clinic"}}}');

        [$code, $out] = $this->console(['settings:import', $file, '--dry-run']);
        self::assertSame(0, $code, $out);
        self::assertSame("Dry run: the settings are valid (en). Nothing was saved.\n", $out);
        self::assertNull($this->app->container()->service(SiteSettings::class)->of('en')->siteName);

        [$code, $example] = $this->console(['settings:import', '--example']);
        self::assertSame(0, $code);
        $data = json_decode($example, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['locales']);
        self::assertSame(['en', 'ru', 'he'], array_keys($data['locales']));
        $this->writeFile('example.json', $example);
        [$code, $out] = $this->console(['settings:import', $this->tempDir() . '/example.json']);
        self::assertSame(0, $code, $out);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function console(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $stderr = fopen('php://memory', 'w+') ?: self::fail('No stream');
        $code = $this->app->console()->run($argv, new Output($stdout, $stderr));
        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr)];
    }
}
