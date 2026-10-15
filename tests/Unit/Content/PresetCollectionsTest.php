<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Seo;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\ConfigException;
use AtarCms\Search\Http\SearchController;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Collection::presets(): pages a collection always has (slug => label), checked by Collections and, against the other
 * routes, at boot.
 */
final class PresetCollectionsTest extends TestCase
{
    public function testACollectionDeclaresItsPresetPages(): void
    {
        $pages = Collection::make('pages')->route('/{slug}')->translatable()
            ->presets(['privacy' => 'site.presets.privacy', 'accessibility' => 'site.presets.accessibility']);

        self::assertSame(['privacy' => 'site.presets.privacy', 'accessibility' => 'site.presets.accessibility'], $pages->presets);
        self::assertSame([], Collection::make('news')->presets);
        new Collections([$pages]);
    }

    public function testAnEntryIsAPresetWhileItsKeyIsConfigured(): void
    {
        $pages = Collection::make('pages')->route('/{slug}')->presets(['privacy' => 'Privacy']);

        self::assertTrue(self::entry($pages, 'privacy')->isPreset());
        self::assertFalse(self::entry($pages, null)->isPreset());
        self::assertFalse(self::entry($pages, 'gone')->isPreset(), 'a key no longer configured: an ordinary page');
    }

    /**
     * @return iterable<string, array{0: list<Collection>, 1: string}>
     */
    public static function invalidPresets(): iterable
    {
        yield 'no route' => [[Collection::make('notes')->presets(['privacy' => 'Privacy'])], 'presets() need a route ending in {slug}'];
        yield 'single' => [[Collection::make('about')->route('/about')->single()->presets(['privacy' => 'Privacy'])], 'presets() need a route ending in {slug}'];
        yield 'hierarchical' => [[Collection::make('pages')->route('/{slug}')->hierarchical()->presets(['privacy' => 'Privacy'])], 'a hierarchical() collection cannot have presets()'];
        yield 'taxonomy placeholder' => [[Collection::make('pages')->route('/{slug}/x')->presets(['privacy' => 'Privacy'])], 'presets() need a route ending in {slug}'];
        yield 'invalid slug' => [[Collection::make('pages')->route('/{slug}')->presets(['Privacy Policy' => 'Privacy'])], 'the preset "Privacy Policy" is not a valid address'];
        yield 'empty label' => [[Collection::make('pages')->route('/{slug}')->presets(['privacy' => ''])], 'the preset "privacy" needs a label'];
        yield 'twice' => [[
            Collection::make('pages')->route('/{slug}')->presets(['privacy' => 'Privacy']),
            Collection::make('docs')->route('/docs/{slug}')->presets(['privacy' => 'Privacy']),
        ], 'the preset "privacy" is declared by "pages" too'];
    }

    /**
     * @param list<Collection> $collections
     */
    #[DataProvider('invalidPresets')]
    public function testInvalidPresetsAreRefused(array $collections, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Collections($collections);
    }

    public function testAPresetSlugAnotherRouteAnswersIsRefusedAtBoot(): void
    {
        $collections = new Collections([
            Collection::make('pages')->route('/{slug}')->presets(['search' => 'Search page']),
        ]);
        $router = new Router();
        $router->get('/search', [SearchController::class, 'show'], 'search');
        ContentModule::registerRoutes($router, $collections);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('The preset "search" of collection "pages" is an address another route answers');

        ContentModule::checkPresets($router, $collections, new Locales('en', ['en', 'ru']));
    }

    public function testALocaleCodeIsNoPresetSlug(): void
    {
        $collections = new Collections([Collection::make('pages')->route('/{slug}')->presets(['ru' => 'Russian'])]);
        $router = new Router();
        ContentModule::registerRoutes($router, $collections);

        $this->expectException(ConfigException::class);

        ContentModule::checkPresets($router, $collections, new Locales('en', ['en', 'ru']));
    }

    private static function entry(Collection $collection, ?string $preset): Entry
    {
        $now = new DateTimeImmutable('2026-10-09 12:00:00');

        return new Entry(1, $collection, 'en', 'g', 'privacy', 'Privacy', EntryStatus::Published, $now, null, 0, null, $now, $now, new Seo(null, null), [], preset: $preset);
    }
}
