<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxonomiesTest extends TestCase
{
    public function testBuilderDefaultsAndSettings(): void
    {
        $topics = Taxonomy::make('topics')->label('Topics')->hierarchical()->route('/topics/{slug}', 5);

        self::assertSame('topics', $topics->name);
        self::assertSame('Topics', $topics->label);
        self::assertTrue($topics->hierarchical);
        self::assertSame('/topics/{slug}', $topics->route);
        self::assertSame('/topics/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', $topics->routePattern());
        self::assertSame(5, $topics->perPage);
        self::assertSame('taxonomies.manage', Taxonomy::PERMISSION);

        $tags = Taxonomy::make('tags');
        self::assertSame('tags', $tags->label);
        self::assertFalse($tags->hierarchical);
        self::assertNull($tags->route);
        self::assertNull($tags->routePattern());
        self::assertSame(10, $tags->perPage);
        self::assertNull($tags->icon);
        self::assertSame('map-pin', Taxonomy::make('regions')->icon('map-pin')->icon);
    }

    public function testATaxonomyMayLiveAtTheRoot(): void
    {
        $sections = Taxonomy::make('sections')->hierarchical()->route('/{slug}', 20);

        self::assertSame('/{slug}', $sections->route);
        self::assertSame('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', $sections->routePattern());
        self::assertTrue($sections->isRoot());
        self::assertFalse(Taxonomy::make('topics')->route('/topics/{slug}')->isRoot());
        self::assertFalse(Taxonomy::make('tags')->isRoot());
    }

    public function testParentTermsMayRedirectToTheirFirstChild(): void
    {
        $sections = Taxonomy::make('sections')->hierarchical()->route('/{slug}')->redirectToFirstChild();
        $taxonomies = new Taxonomies([$sections]);

        self::assertTrue($taxonomies->get('sections')->redirectToFirstChild);
        self::assertFalse(Taxonomy::make('topics')->redirectToFirstChild);
    }

    /**
     * @return iterable<string, array{Closure(): array<mixed>, string}>
     */
    public static function invalidRedirects(): iterable
    {
        yield 'flat' => [static fn() => [Taxonomy::make('t')->route('/t/{slug}')->redirectToFirstChild()], 'Taxonomy "t": redirectToFirstChild() needs a hierarchical() taxonomy with a route()'];
        yield 'without a route' => [static fn() => [Taxonomy::make('t')->hierarchical()->redirectToFirstChild()], 'needs a hierarchical() taxonomy with a route()'];
    }

    /**
     * @param Closure(): array<mixed> $taxonomies
     */
    #[DataProvider('invalidRedirects')]
    public function testRedirectingNeedsARoutedTree(Closure $taxonomies, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Taxonomies($taxonomies());
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function invalidBuilders(): iterable
    {
        yield 'uppercase name' => [static fn() => Taxonomy::make('Topics'), 'Invalid taxonomy name'];
        yield 'relative route' => [static fn() => Taxonomy::make('t')->route('topics/{slug}'), 'must start with "/"'];
        yield 'double slash' => [static fn() => Taxonomy::make('t')->route('/topics//{slug}'), 'must start with "/"'];
        yield 'no slug' => [static fn() => Taxonomy::make('t')->route('/topics'), 'needs {slug} once'];
        yield 'two slugs' => [static fn() => Taxonomy::make('t')->route('/t/{slug}/{slug}'), 'needs {slug} once'];
        yield 'other placeholder' => [static fn() => Taxonomy::make('t')->route('/t/{id}'), 'needs {slug} once'];
        yield 'placeholder before a literal' => [static fn() => Taxonomy::make('t')->route('/{slug}/feed'), 'literal first segment'];
        yield 'placeholder in the first segment' => [static fn() => Taxonomy::make('t')->route('/topic-{slug}'), 'literal first segment'];
        yield 'admin' => [static fn() => Taxonomy::make('t')->route('/admin/{slug}'), 'reserved'];
        yield 'bad per page' => [static fn() => Taxonomy::make('t')->route('/t/{slug}', 0), 'per page'];
        yield 'bad icon' => [static fn() => Taxonomy::make('t')->icon('map pin'), 'Invalid icon "map pin"'];
    }

    #[DataProvider('invalidBuilders')]
    public function testBuilderRejectsInvalidSettings(Closure $build, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * @return iterable<string, array{Closure(): array<mixed>, string}>
     */
    public static function invalidRegistries(): iterable
    {
        yield 'not a taxonomy' => [static fn() => ['x'], 'must return Taxonomy objects'];
        yield 'key mismatch' => [static fn() => ['tags' => Taxonomy::make('labels')], 'key "tags"'];
        yield 'duplicate' => [static fn() => [Taxonomy::make('tags'), Taxonomy::make('tags')], 'twice'];
    }

    /**
     * @param Closure(): array<mixed> $taxonomies
     */
    #[DataProvider('invalidRegistries')]
    public function testRegistryRejectsInvalidConfig(Closure $taxonomies, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Taxonomies($taxonomies());
    }

    public function testRegistryLookups(): void
    {
        $taxonomies = Taxonomies::fromConfig(new Config(['taxonomies' => ['topics' => Taxonomy::make('topics'), Taxonomy::make('tags')]]));

        self::assertSame(['topics', 'tags'], array_keys($taxonomies->all()));
        self::assertSame('tags', $taxonomies->get('tags')->name);
        self::assertNull($taxonomies->find('ghost'));
        self::assertSame([], Taxonomies::fromConfig(new Config())->all());

        $this->expectException(InvalidArgumentException::class);
        $taxonomies->get('ghost');
    }
}
