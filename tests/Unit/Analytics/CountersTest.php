<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Analytics;

use AtarCms\Analytics\Counters;
use AtarCms\Http\Security\CspSources;
use AtarCms\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class CountersTest extends TestCase
{
    public function testComesFromTheSettings(): void
    {
        $counters = Counters::of(new Settings(yandexMetrica: '12345678', yandexWebvisor: true, googleAnalytics: 'G-TEST123456'));

        self::assertFalse($counters->isEmpty());
        self::assertSame('12345678', $counters->metrica);
        self::assertTrue($counters->webvisor);
        self::assertSame('G-TEST123456', $counters->googleAnalytics);
        self::assertSame(['metrica', 'google_analytics'], $counters->services());
        self::assertSame(['metrica', 'google_analytics'], $counters->statistics());
        self::assertSame([], $counters->advertising());
        self::assertSame('https://mc.yandex.ru/metrika/tag.js', Counters::METRICA_SCRIPT);
        self::assertSame('https://www.googletagmanager.com/gtag/js?id=', Counters::GTAG_SCRIPT, 'the id is the first allowed one, added by consent.js');
    }

    public function testThePixelsAreTheAdvertisingCategory(): void
    {
        $counters = Counters::of(new Settings(yandexMetrica: '12345678', googleAnalytics: 'G-TEST123456', metaPixel: '123456789012345', googleAds: 'AW-123456789', vkPixel: '3456789', tiktokPixel: 'C4ABCDEFGH1234567890'));

        self::assertSame(['123456789012345', 'AW-123456789', '3456789', 'C4ABCDEFGH1234567890'], [$counters->metaPixel, $counters->googleAds, $counters->vkPixel, $counters->tiktokPixel]);
        self::assertSame(['metrica', 'google_analytics'], $counters->statistics());
        self::assertSame(['meta', 'google_ads', 'vk', 'tiktok'], $counters->advertising());
        self::assertSame(['metrica', 'google_analytics', 'meta', 'google_ads', 'vk', 'tiktok'], $counters->services());
        self::assertSame('https://connect.facebook.net/en_US/fbevents.js', Counters::META_SCRIPT);
        self::assertSame('https://top-fwz1.mail.ru/js/code.js', Counters::VK_SCRIPT);
        self::assertSame('https://analytics.tiktok.com/i18n/pixel/events.js', Counters::TIKTOK_SCRIPT);

        $adsOnly = Counters::of(new Settings(tiktokPixel: 'C4ABCDEFGH1234567890'));
        self::assertFalse($adsOnly->isEmpty());
        self::assertSame([], $adsOnly->statistics());
        self::assertSame(['tiktok'], $adsOnly->advertising());
    }

    public function testMetaHosts(): void
    {
        self::assertSame([
            'script-src' => ['https://connect.facebook.net'],
            'img-src' => ['https://www.facebook.com'],
            'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'],
            'frame-src' => [],
            'frame-ancestors' => [],
        ], self::hosts(new Settings(metaPixel: '123456789012345')));
    }

    public function testGoogleAdsHosts(): void
    {
        // www.google.<TLD> one by one: .com and the countries of the five interface languages (the smoke saw
        // www.google.co.il from Israel).
        $tag = ['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://googleads.g.doubleclick.net', 'https://pagead2.googlesyndication.com', 'https://www.google.com', 'https://www.google.ru', 'https://www.google.co.il', 'https://www.google.de', 'https://www.google.fr', 'https://www.google.co.uk'];
        self::assertSame([
            'script-src' => ['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://www.google.com'],
            'img-src' => [...$tag, 'https://*.g.doubleclick.net'],
            'connect-src' => [...$tag, 'https://ad.doubleclick.net', 'https://*.g.doubleclick.net'],
            'frame-src' => ['https://www.googletagmanager.com', 'https://td.doubleclick.net'],
            'frame-ancestors' => [],
        ], self::hosts(new Settings(googleAds: 'AW-123456789')));
    }

    public function testGoogleAnalyticsAndGoogleAdsShareTheirHosts(): void
    {
        $hosts = self::hosts(new Settings(googleAnalytics: 'G-TEST123456', googleAds: 'AW-123456789'));

        self::assertSame(['https://www.googletagmanager.com', 'https://www.googleadservices.com', 'https://www.google.com'], $hosts['script-src']);
        self::assertSame(1, array_count_values($hosts['connect-src'])['https://www.googletagmanager.com']);
        self::assertContains('https://*.google-analytics.com', $hosts['connect-src']);
    }

    /**
     * Google's GA4 list with its advertising features (Google signals) adds *.g.doubleclick.net; a Google tag needs it
     * once the site runs advertising at all.
     */
    public function testAGoogleTagBesideAnAdvertisingPixelReachesDoubleClick(): void
    {
        foreach ([
            [new Settings(googleAnalytics: 'G-TEST123456', metaPixel: '123456789012345'), true],
            [new Settings(googleAds: 'AW-123456789'), true],
            [new Settings(googleAnalytics: 'G-TEST123456'), false],
            [new Settings(googleAnalytics: 'G-TEST123456', yandexMetrica: '12345678'), false],
            [new Settings(metaPixel: '123456789012345'), false],
        ] as $index => [$settings, $expected]) {
            $hosts = self::hosts($settings);
            self::assertSame($expected, in_array('https://*.g.doubleclick.net', $hosts['img-src'], true), (string) $index);
            self::assertSame($expected, in_array('https://*.g.doubleclick.net', $hosts['connect-src'], true), (string) $index);
            self::assertNotContains('https://*.g.doubleclick.net', $hosts['script-src']);
        }
    }

    public function testVkHosts(): void
    {
        self::assertSame([
            'script-src' => ['https://top-fwz1.mail.ru', 'https://privacy-cs.mail.ru'],
            'img-src' => ['https://top-fwz1.mail.ru', 'https://ad.mail.ru'],
            'connect-src' => ['https://top-fwz1.mail.ru', 'https://privacy-cs.mail.ru'],
            'frame-src' => [],
            'frame-ancestors' => [],
        ], self::hosts(new Settings(vkPixel: '3456789')));
    }

    public function testTikTokHosts(): void
    {
        self::assertSame([
            'script-src' => ['https://analytics.tiktok.com'],
            'img-src' => ['https://analytics.tiktok.com'],
            'connect-src' => ['https://analytics.tiktok.com'],
            'frame-src' => [],
            'frame-ancestors' => [],
        ], self::hosts(new Settings(tiktokPixel: 'C4ABCDEFGH1234567890')));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function hosts(Settings $settings): array
    {
        $sources = new CspSources();
        Counters::of($settings)->allowIn($sources);
        $hosts = [];
        foreach (CspSources::DIRECTIVES as $directive) {
            $hosts[$directive] = $sources->for($directive);
        }

        return $hosts;
    }

    public function testWebvisorNeedsTheCounter(): void
    {
        $counters = Counters::of(new Settings(yandexWebvisor: true));

        self::assertTrue($counters->isEmpty());
        self::assertFalse($counters->webvisor);
        self::assertSame([], $counters->services());
    }

    public function testNoCounterAllowsNothing(): void
    {
        $sources = new CspSources();
        Counters::of(new Settings())->allowIn($sources);

        self::assertFalse($sources->any());
    }

    public function testMetricaHosts(): void
    {
        $sources = new CspSources();
        Counters::of(new Settings(yandexMetrica: '12345678'))->allowIn($sources);

        self::assertSame(['https://mc.yandex.ru', 'https://mc.yandex.com', 'https://yastatic.net'], $sources->for('script-src'));
        foreach (['img-src', 'connect-src', 'frame-src'] as $directive) {
            self::assertContains('https://mc.yandex.ru', $sources->for($directive), $directive);
            self::assertContains('https://mc.yandex.com.tr', $sources->for($directive), $directive);
        }
        self::assertCount(18, $sources->for('img-src'));
        self::assertCount(18, $sources->for('frame-src'));
        self::assertCount(36, $sources->for('connect-src'), 'https and wss: tag.js opens wss://mc.yandex.ru/solid.ws');
        self::assertContains('wss://mc.yandex.ru', $sources->for('connect-src'));
        self::assertContains('https://metrika.yandex.ru', $sources->for('frame-ancestors'));
        self::assertContains('https://metrika.yandex.uz', $sources->for('frame-ancestors'));
        foreach (CspSources::DIRECTIVES as $directive) {
            foreach ($sources->for($directive) as $source) {
                self::assertStringNotContainsString('webvisor', $source, 'Webvisor is off');
            }
        }
    }

    public function testWebvisorAddsItsHosts(): void
    {
        $sources = new CspSources();
        Counters::of(new Settings(yandexMetrica: '12345678', yandexWebvisor: true))->allowIn($sources);

        self::assertContains('https://mc.webvisor.com', $sources->for('connect-src'));
        self::assertContains('https://mc.webvisor.org', $sources->for('frame-src'));
        self::assertContains('wss://mc.yandex.ru', $sources->for('connect-src'));
        self::assertContains('wss://mc.webvisor.org', $sources->for('connect-src'));
        self::assertNotContains('wss://mc.yandex.ru', $sources->for('script-src'));
    }

    public function testGoogleAnalyticsHosts(): void
    {
        $sources = new CspSources();
        Counters::of(new Settings(googleAnalytics: 'G-TEST123456'))->allowIn($sources);

        self::assertSame(['https://www.googletagmanager.com'], $sources->for('script-src'));
        self::assertSame(['https://www.googletagmanager.com', 'https://*.google-analytics.com'], $sources->for('img-src'));
        self::assertSame(['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.google.com'], $sources->for('connect-src'));
        self::assertSame([], $sources->for('frame-src'));
        self::assertSame([], $sources->for('frame-ancestors'));
    }
}
