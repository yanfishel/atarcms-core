<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Content;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\ConfigException;
use AtarCms\Kernel\ModuleList;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
    }

    public function testBuilderDefaultsAndSettings(): void
    {
        $news = Collection::make('news')
            ->label('News')
            ->route('/news/{slug}')
            ->archive('/news', 5)
            ->feed()
            ->translatable()
            ->sortBy('title', 'asc')
            ->fields([Field::text('summary'), Field::date('event_date')->indexed()]);

        self::assertSame('news', $news->name);
        self::assertSame('News', $news->label);
        self::assertSame('/news/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', $news->routePattern());
        self::assertSame('/news/feed.xml', $news->feedPath());
        self::assertSame(5, $news->perPage);
        self::assertSame(['title', 'asc'], [$news->sortColumn, $news->sortDirection]);
        self::assertSame(['summary', 'event_date'], array_keys($news->fields));
        self::assertSame('event_date', $news->field('event_date')?->name);
        self::assertNull($news->field('missing'));
        self::assertSame('entries.news.edit', $news->permission('edit'));
        self::assertTrue($news->translatable);
        self::assertNull($news->icon);
        self::assertSame('content', $news->field('summary')?->tab);
        self::assertSame(['content', 'media', 'settings'], [
            Field::text('a')->tab('content')->tab,
            Field::image('b')->tab('media')->tab,
            Field::date('c')->tab('settings')->tab,
        ]);
        self::assertSame('calendar', Collection::make('events')->icon('calendar')->icon);
        self::assertFalse($news->plain);
        self::assertTrue(Collection::make('home')->single()->plain()->plain);
        self::assertFalse(Field::richText('body')->tall);
        self::assertTrue(Field::richText('body')->tall()->tall);
        self::assertNull(Field::text('a')->help);
        self::assertSame('site.fields.summary_help', Field::richText('summary')->help('site.fields.summary_help')->help);

        $pages = Collection::make('pages')->route('/{slug}')->hierarchical();
        self::assertSame('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*}', $pages->routePattern());
        self::assertSame('pages', $pages->label);
        self::assertSame(['published_at', 'desc'], [$pages->sortColumn, $pages->sortDirection]);
        self::assertNull(Collection::make('notes')->routePattern());
        self::assertNull(Collection::make('notes')->feedPath());
    }

    public function testARouteMayStartWithATaxonomyPlaceholder(): void
    {
        $articles = Collection::make('articles')->route('/{sections}/{slug}')->taxonomies(['sections']);
        $collections = new Collections([$articles], new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]));

        self::assertSame('sections', $articles->routeTaxonomy);
        self::assertSame('/{sections:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*}/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', $articles->routePattern(), 'the trail of the section');
        self::assertSame($articles, $collections->get('articles'));
        self::assertNull(Collection::make('news')->route('/news/{slug}')->routeTaxonomy);
        self::assertNull(Collection::make('news')->route('/{sections}/{slug}')->route('/news/{slug}')->routeTaxonomy, 'route() replaces the placeholder');
    }

    /**
     * @return iterable<string, array{Closure(): array<mixed>, string}>
     */
    public static function invalidSectionedRoutes(): iterable
    {
        yield 'a taxonomy the collection does not use' => [
            static fn() => [Collection::make('a')->route('/{sections}/{slug}')],
            'Collection "a": the route placeholder {sections} must name one of its taxonomies()',
        ];
        yield 'a tags field taxonomy' => [
            static fn() => [Collection::make('a')->route('/{tags}/{slug}')->fields([Field::tags('tags')])],
            'the route placeholder {tags} must name one of its taxonomies()',
        ];
        yield 'a taxonomy that is not at the root' => [
            static fn() => [Collection::make('a')->route('/{topics}/{slug}')->taxonomies(['topics'])],
            'the taxonomy "topics" of the route placeholder must be routed at "/{slug}"',
        ];
        yield 'hierarchical' => [
            static fn() => [Collection::make('a')->route('/{sections}/{slug}')->taxonomies(['sections'])->hierarchical()],
            'a route with a taxonomy placeholder cannot be hierarchical()',
        ];
        yield 'nested pages at the root' => [
            static fn() => [
                Collection::make('pages')->route('/{slug}')->hierarchical(),
                Collection::make('a')->route('/{sections}/{slug}')->taxonomies(['sections']),
            ],
            'Collection "pages" is hierarchical() at "/{slug}": its nested addresses would clash with those of the root taxonomy "sections"',
        ];
        yield 'a literal segment after the placeholder' => [
            static fn() => [Collection::make('a')->route('/{sections}/articles/{slug}')->taxonomies(['sections'])],
            'the route must be exactly "/{sections}/{slug}"',
        ];
        yield 'a suffix after the slug' => [
            static fn() => [Collection::make('a')->route('/{sections}/{slug}.html')->taxonomies(['sections'])],
            'the route must be exactly "/{sections}/{slug}"',
        ];
    }

    /**
     * @param Closure(): array<mixed> $collections
     */
    #[DataProvider('invalidSectionedRoutes')]
    public function testSectionedRoutesAreValidated(Closure $collections, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Collections($collections(), new Taxonomies([
            Taxonomy::make('sections')->hierarchical()->route('/{slug}'),
            Taxonomy::make('topics')->hierarchical()->route('/topics/{slug}'),
            Taxonomy::make('tags'),
        ]));
    }

    public function testNestedPagesCannotShareTheRootWithAFlatRootTaxonomy(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Collection "pages" is hierarchical() at "/{slug}": its nested addresses would clash with those of the root taxonomy "sections"');

        new Collections(
            [Collection::make('pages')->route('/{slug}')->hierarchical()],
            new Taxonomies([Taxonomy::make('sections')->route('/{slug}')]),
        );
    }

    public function testSearchableListsTheRoutedCollectionsSearchIsNotTurnedOffFor(): void
    {
        $collections = new Collections([
            Collection::make('news')->route('/news/{slug}'),
            Collection::make('home')->route('/')->single()->searchable(false),
            Collection::make('notes'),
            Collection::make('pages')->route('/{slug}')->searchable(false)->searchable(),
        ]);

        self::assertTrue(Collection::make('any')->searchable);
        self::assertFalse($collections->get('home')->searchable);
        self::assertSame(['news', 'pages'], array_keys($collections->searchable()));
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function invalidBuilders(): iterable
    {
        yield 'uppercase name' => [static fn() => Collection::make('News'), 'Invalid collection name'];
        yield 'dash in name' => [static fn() => Collection::make('a-b'), 'Invalid collection name'];
        yield 'relative route' => [static fn() => Collection::make('a')->route('news/{slug}'), 'must start with "/"'];
        yield 'other placeholder' => [static fn() => Collection::make('a')->route('/news/{id}'), 'only use {slug}'];
        yield 'two slugs' => [static fn() => Collection::make('a')->route('/{slug}/{slug}'), 'only use {slug}'];
        yield 'taxonomy placeholder after a literal' => [static fn() => Collection::make('a')->route('/news/{sections}/{slug}'), 'only use {slug}'];
        yield 'two taxonomy placeholders' => [static fn() => Collection::make('a')->route('/{sections}/{topics}/{slug}'), 'only use {slug}'];
        yield 'admin route' => [static fn() => Collection::make('a')->route('/admin/{slug}'), 'reserved'];
        yield 'admin archive' => [static fn() => Collection::make('a')->archive('/admin'), 'reserved'];
        yield 'double slash' => [static fn() => Collection::make('a')->route('/news//{slug}'), 'must start with "/"'];
        yield 'query in route' => [static fn() => Collection::make('a')->route('/news?x={slug}'), 'must start with "/"'];
        yield 'placeholder in archive' => [static fn() => Collection::make('a')->archive('/news/{slug}'), 'placeholders'];
        yield 'bad per page' => [static fn() => Collection::make('a')->archive('/news', 0), 'per page'];
        yield 'bad direction' => [static fn() => Collection::make('a')->sortBy('title', 'up'), '"asc" or "desc"'];
        yield 'duplicate field' => [static fn() => Collection::make('a')->fields([Field::text('x'), Field::text('x')]), 'twice'];
        yield 'not a field' => [static fn() => Collection::make('a')->fields(['x']), 'Field objects'];
        yield 'bad icon' => [static fn() => Collection::make('a')->icon('Calendar'), 'Invalid icon "Calendar"'];
        yield 'unknown tab' => [static fn() => Field::text('x')->tab('seo'), 'Field "x": unknown tab "seo"; use content, media, settings'];
        yield 'tab in another case' => [static fn() => Field::text('x')->tab('Media'), 'unknown tab "Media"'];
        yield 'tall plain text' => [static fn() => Field::textarea('x')->tall(), 'Field "x": only a rich-text field can be tall'];
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
    public static function invalidConfigs(): iterable
    {
        yield 'not a collection' => [static fn() => ['x'], 'must return Collection objects'];
        yield 'key mismatch' => [static fn() => ['news' => Collection::make('posts')], 'key "news"'];
        yield 'duplicate' => [static fn() => [Collection::make('a'), Collection::make('a')], 'twice'];
        yield 'share image of an unknown field' => [static fn() => [Collection::make('a')->fields([Field::image('cover')])->shareImage('ghost')], 'Collection "a": shareImage() names "ghost", which is not an image or gallery field of the collection'];
        yield 'share image of a file field' => [static fn() => [Collection::make('a')->fields([Field::file('cover')])->shareImage('cover')], 'shareImage() names "cover"'];
        yield 'plain but not single' => [static fn() => [Collection::make('a')->route('/a/{slug}')->plain()], 'Collection "a": a plain() collection must be single()'];
        yield 'single and hierarchical' => [static fn() => [Collection::make('a')->single()->hierarchical()], 'cannot be hierarchical'];
        yield 'single with archive' => [static fn() => [Collection::make('a')->single()->route('/a')->archive('/as')], 'no archive'];
        yield 'single with slug' => [static fn() => [Collection::make('a')->single()->route('/a/{slug}')], 'has no {slug}'];
        yield 'missing slug' => [static fn() => [Collection::make('a')->route('/a')], 'needs a {slug}'];
        yield 'archive without route' => [static fn() => [Collection::make('a')->archive('/as')], 'need a route()'];
        yield 'feed without archive' => [static fn() => [Collection::make('a')->route('/a/{slug}')->feed()], 'feed() needs an archive()'];
        yield 'unknown sort' => [static fn() => [Collection::make('a')->sortBy('rating')], 'cannot sort by "rating"'];
        yield 'unindexable field' => [static fn() => [Collection::make('a')->fields([Field::richText('body')->indexed()])], 'cannot be indexed'];
        yield 'unknown relation target' => [static fn() => [Collection::make('a')->fields([Field::relation('rel', 'ghost')])], 'unknown collection "ghost"'];
        yield 'indexed field retyped as text elsewhere' => [
            static fn() => [Collection::make('a')->fields([Field::number('price')->indexed()]), Collection::make('b')->fields([Field::text('price')])],
            'The field "price" is VARCHAR(191) in collection "b" but DOUBLE in "a"',
        ];
        yield 'indexed field retyped as boolean elsewhere' => [
            static fn() => [Collection::make('b')->fields([Field::boolean('price')]), Collection::make('a')->fields([Field::number('price')->indexed()])],
            'The field "price" is boolean (not indexable) in collection "b" but DOUBLE in "a"',
        ];
    }

    public function testFieldsSharingAnIndexedColumnMayOmitIndexedWhenTheTypeMatches(): void
    {
        $collections = new Collections([
            Collection::make('a')->fields([Field::number('price')->indexed()]),
            Collection::make('b')->fields([Field::number('price')]),
            Collection::make('c')->fields([Field::boolean('featured')]),
            Collection::make('d')->fields([Field::text('featured')]),
        ]);

        self::assertSame(['a', 'b', 'c', 'd'], array_keys($collections->all()));
    }

    /**
     * @param Closure(): array<mixed> $collections
     */
    #[DataProvider('invalidConfigs')]
    public function testRegistryRejectsInconsistentConfig(Closure $collections, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Collections($collections());
    }

    public function testTheMainPhotoFieldsDefaultToTheFirstImageAndGalleryInFieldOrder(): void
    {
        $fields = static fn(): array => [
            Field::file('doc'),
            Field::gallery('photos'),
            Field::image('cover'),
            Field::image('banner'),
            Field::gallery('more'),
        ];

        self::assertSame(['photos', 'cover'], Collection::make('a')->fields($fields())->shareImageFields());
        self::assertSame(['banner'], Collection::make('a')->fields($fields())->shareImage('banner')->shareImageFields());
        self::assertSame([], Collection::make('a')->fields($fields())->shareImage()->shareImageFields(), 'turned off');
        self::assertSame([], Collection::make('a')->fields([Field::text('x')])->shareImageFields());
        new Collections([Collection::make('a')->fields($fields())->shareImage('more', 'cover')]);
    }

    public function testRegistryLookups(): void
    {
        $collections = new Collections([
            'news' => Collection::make('news')->sortBy('rating')->fields([Field::number('rating')->indexed()]),
            Collection::make('pages'),
        ]);

        self::assertSame(['news', 'pages'], array_keys($collections->all()));
        self::assertSame('pages', $collections->get('pages')->name);
        self::assertNull($collections->find('ghost'));

        $this->expectException(InvalidArgumentException::class);
        $collections->get('ghost');
    }

    public function testFixtureSiteLoadsItsCollectionsAndContentModuleComesLast(): void
    {
        putenv('APP_DEBUG=0');
        $container = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container();
        $modules = $container->service(ModuleList::class)->modules;

        self::assertSame(['articles', 'pages', 'contact', 'notes', 'events', 'settings', 'info', 'stories', 'welcome'], array_keys($container->service(Collections::class)->all()));
        self::assertInstanceOf(ContentModule::class, end($modules));
    }

    public function testCollectionsReferenceTaxonomies(): void
    {
        $news = Collection::make('news')->taxonomies(['topics'])->fields([Field::text('summary'), Field::tags('tags'), Field::tags('labels', 'regions')]);
        $collections = new Collections([$news, Collection::make('pages')], self::taxonomies());

        self::assertSame(['topics'], $news->taxonomies);
        self::assertSame(['topics', 'tags', 'regions'], $news->termTaxonomies());
        self::assertSame('labels', $news->tagsField('regions')?->name);
        self::assertSame('tags', $news->tagsField('tags')?->taxonomy);
        self::assertNull($news->tagsField('topics'));
        self::assertSame(['news'], array_map(static fn(Collection $c): string => $c->name, $collections->usingTaxonomy('regions')));
        self::assertSame([], $collections->usingTaxonomy('ghost'));
        self::assertSame(['topics', 'tags', 'regions'], array_keys($collections->taxonomies->all()));
        self::assertSame([], new Collections([Collection::make('pages')])->taxonomies->all());
    }

    /**
     * @return iterable<string, array{Closure(): array<mixed>, string}>
     */
    public static function invalidTaxonomyReferences(): iterable
    {
        yield 'unknown taxonomy' => [static fn() => [Collection::make('a')->taxonomies(['ghost'])], 'the taxonomy "ghost" is not declared'];
        yield 'unknown tags taxonomy' => [static fn() => [Collection::make('a')->fields([Field::tags('labels')])], 'the taxonomy "labels" is not declared'];
        yield 'hierarchical tags' => [static fn() => [Collection::make('a')->fields([Field::tags('topics')])], 'needs a flat taxonomy'];
        yield 'taxonomy used twice' => [static fn() => [Collection::make('a')->taxonomies(['tags'])->fields([Field::tags('tags')])], 'uses the taxonomy "tags" twice'];
        yield 'route clash' => [static fn() => [Collection::make('a')->route('/topics/{slug}')], 'Taxonomy "topics" and collection "a" use the same route'];
    }

    /**
     * @param Closure(): array<mixed> $collections
     */
    #[DataProvider('invalidTaxonomyReferences')]
    public function testTaxonomyReferencesAreValidated(Closure $collections, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        new Collections($collections(), self::taxonomies());
    }

    public function testTaxonomiesNeedDistinctRoutes(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Taxonomies "a" and "b" use the same route "/t/{slug}"');

        new Collections([], new Taxonomies([Taxonomy::make('a')->route('/t/{slug}'), Taxonomy::make('b')->route('/t/{slug}')]));
    }

    public function testARootTaxonomySharesTheRootWithACollection(): void
    {
        $collections = new Collections(
            [Collection::make('pages')->route('/{slug}')],
            new Taxonomies([Taxonomy::make('sections')->hierarchical()->route('/{slug}')]),
        );

        self::assertSame('/{slug}', $collections->get('pages')->route);
        self::assertTrue($collections->taxonomies->get('sections')->isRoot());
    }

    public function testCollectionTaxonomyListsAreValidated(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('lists the taxonomy "tags" twice');

        Collection::make('a')->taxonomies(['tags', 'tags']);
    }

    public function testFixtureSiteLoadsItsTaxonomies(): void
    {
        putenv('APP_DEBUG=0');
        $container = Application::fromSite(__DIR__ . '/../../Fixtures/site')->container();

        self::assertSame(['topics', 'tags', 'regions', 'sections'], array_keys($container->service(Taxonomies::class)->all()));
        self::assertSame($container->service(Collections::class)->taxonomies, $container->service(Taxonomies::class));
        self::assertSame(['topics', 'tags'], $container->service(Collections::class)->get('articles')->termTaxonomies());
    }

    private static function taxonomies(): Taxonomies
    {
        return new Taxonomies([
            Taxonomy::make('topics')->hierarchical()->route('/topics/{slug}'),
            Taxonomy::make('tags'),
            Taxonomy::make('regions'),
        ]);
    }

    public function testTagsFieldsStoreNothingAndSplitNames(): void
    {
        $field = Field::tags('tags');

        self::assertSame('tags', $field->type->name());
        self::assertNull($field->type->normalize('php, go', $field));
        self::assertNull($field->type->columnType($field));
        self::assertSame(['PHP', 'web dev', 'Go'], TagsType::names(" PHP,web \t dev,, php ,Go,"));
        self::assertSame(['a', 'b'], TagsType::names(['a', 3, 'b', 'A']));
        self::assertSame([], TagsType::names(null));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('$this->terms($entry, \'tags\')');
        $field->type->read(null, $field);
    }
}
