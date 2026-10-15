<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Tests\Support\Cast;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MainMenuTest extends TestCase
{
    public function testPinnedCollectionsLinkToTheirPageOrArchive(): void
    {
        $menu = MainMenu::fromConfig(new Config(['content' => ['main_menu' => ['home', 'news']]]), self::collections());

        self::assertTrue($menu->enabled());
        self::assertSame('sections', $menu->sections?->name);
        self::assertSame(['home', 'news'], array_map(static fn(Collection $collection): string => $collection->name, $menu->pinned ?? []));
        self::assertFalse(MainMenu::fromConfig(new Config(), self::collections())->enabled(), 'without the key "main" is an ordinary menu');
        self::assertSame('sections', MainMenu::fromConfig(new Config(), self::collections())->sections?->name);
    }

    /**
     * @return iterable<string, array{Closure(): Collections, mixed, string}>
     */
    public static function invalidMenus(): iterable
    {
        $collections = static fn(): Collections => self::collections();
        yield 'not a list' => [$collections, ['a' => 'home'], 'content.main_menu must be a list of collection names'];
        yield 'unknown collection' => [$collections, ['home', 'ghost'], 'content.main_menu names the unknown collection "ghost"'];
        yield 'twice' => [$collections, ['home', 'home'], 'content.main_menu names the collection "home" twice'];
        yield 'nothing to link to' => [$collections, ['pages'], 'content.main_menu: the collection "pages" needs a route() as a single() or an archive() to link to'];
        yield 'no sections' => [
            static fn(): Collections => new Collections([Collection::make('home')->route('/')->single()]),
            ['home'],
            'content.main_menu needs a hierarchical() taxonomy routed at "/{slug}"',
        ];
    }

    /**
     * @param Closure(): Collections $collections
     */
    #[DataProvider('invalidMenus')]
    public function testTheConfigurationIsChecked(Closure $collections, mixed $names, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        MainMenu::fromConfig(new Config(['content' => ['main_menu' => $names]]), $collections());
    }

    public function testThePinnedCollectionsTakeTheirStoredPlaces(): void
    {
        $menu = MainMenu::fromConfig(new Config(['content' => ['main_menu' => ['home', 'news', 'contacts']]]), self::collections());
        $names = static fn(array $items): array => array_map(static fn(mixed $item): string => $item instanceof Collection ? $item->name : Cast::string($item), $items);

        self::assertSame(['home', 'news', 'contacts', 'A', 'B'], $names($menu->topLevel(['A', 'B'], [])), 'first, in config order, until an order is saved');
        self::assertSame(['home', 'A', 'news', 'B', 'contacts'], $names($menu->topLevel(['A', 'B'], ['home' => 0, 'news' => 2, 'contacts' => 4])));
        self::assertSame(['home', 'A', 'news', 'contacts'], $names($menu->topLevel(['A'], ['home' => 0, 'news' => 2, 'contacts' => 4])), 'a language with fewer sections');
        self::assertSame(['news', 'A', 'B', 'home', 'contacts'], $names($menu->topLevel(['A', 'B'], ['news' => 0, 'home' => 9])), 'a collection pinned later comes last');
    }

    private static function collections(): Collections
    {
        return new Collections(
            [
                Collection::make('home')->route('/')->single(),
                Collection::make('contacts')->route('/contacts')->single(),
                Collection::make('news')->route('/news/{slug}')->archive('/news'),
                Collection::make('pages')->route('/{slug}'),
            ],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}'), Taxonomy::make('tags')]),
        );
    }
}
