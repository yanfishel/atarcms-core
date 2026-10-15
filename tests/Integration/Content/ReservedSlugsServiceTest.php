<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\SlugHistoryRepository;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use stdClass;

final class ReservedSlugsServiceTest extends ContentTestCase
{
    public function testTopLevelEntriesOfARootCollectionAvoidReservedSlugs(): void
    {
        $collections = new Collections([Collection::make('pages')->route('/{slug}')->hierarchical()->translatable()]);
        $router = new Router();
        $router->get('/admin', [stdClass::class, 'x'], 'admin.dashboard');
        $locales = new Locales('en', ['en', 'ru']);
        $service = new EntryService(
            $this->db,
            new EntryRepository($this->db, $collections),
            $this->revisions(),
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs($router, $locales),
            $this->entryTerms(),
            $this->entryMedia(),
            $this->entryAccess(),
            $collections,
            $this->searchIndexer($collections),
            $this->richTextTheme(),
            $this->translator(),
            $this->shareImageService(),
            new SlugHistoryRepository($this->db),
        );
        $pages = $collections->get('pages');

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $service->create($pages, 'en', new EntryInput('About', slug: 'admin'), null, null),
        );
        self::assertSame('admin-2', $service->create($pages, 'en', new EntryInput('Admin'), null, null)->slug);
        self::assertSame('ru-2', $service->create($pages, 'en', new EntryInput('RU'), null, null)->slug);

        $docs = $service->create($pages, 'en', new EntryInput('Docs'), null, null);
        self::assertSame('admin', $service->create($pages, 'en', new EntryInput('Admin guide', slug: 'admin', parentId: $docs->id), null, null)->slug);
    }

    public function testEntriesUnderAPrefixAvoidTheLiteralSegmentsOfOtherRoutes(): void
    {
        $collections = new Collections([Collection::make('news')->route('/news/{slug}')]);
        $router = new Router();
        $router->get('/news/feed', [stdClass::class, 'x'], 'site.news.feed');
        $locales = new Locales('en', ['en', 'ru']);
        $service = new EntryService(
            $this->db,
            new EntryRepository($this->db, $collections),
            $this->revisions(),
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs($router, $locales),
            $this->entryTerms(),
            $this->entryMedia(),
            $this->entryAccess(),
            $collections,
            $this->searchIndexer($collections),
            $this->richTextTheme(),
            $this->translator(),
            $this->shareImageService(),
            new SlugHistoryRepository($this->db),
        );
        $news = $collections->get('news');

        self::assertValidation(
            ['slug' => 'validation.slug.reserved'],
            fn() => $service->create($news, 'en', new EntryInput('Feed', slug: 'feed'), null, null),
        );
        self::assertSame('feed-2', $service->create($news, 'en', new EntryInput('Feed'), null, null)->slug);
        self::assertSame('ru', $service->create($news, 'en', new EntryInput('RU', slug: 'ru'), null, null)->slug, 'locale codes only matter at the root');
    }

    public function testPositionMustFitTheColumn(): void
    {
        $pages = $this->collections->get('pages');

        self::assertValidation(
            ['position' => 'validation.field.invalid'],
            fn() => $this->service()->create($pages, 'en', new EntryInput('Too far', position: 2147483648), null, null),
        );
        self::assertSame(-5, $this->service()->create($pages, 'en', new EntryInput('Negative', position: -5), null, null)->position);
    }
}
