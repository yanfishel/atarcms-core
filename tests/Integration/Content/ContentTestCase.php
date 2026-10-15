<?php

declare(strict_types=1);

namespace AtarCms\Tests\Integration\Content;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Collections;
use AtarCms\Content\ContentModule;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryMedia;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryTerms;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\ReservedSlugs;
use AtarCms\Content\RevisionRepository;
use AtarCms\Content\RichTextTheme;
use AtarCms\Content\RootSlugs;
use AtarCms\Content\ShareImage\ShareImages;
use AtarCms\Content\SlugHistoryRepository;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\TermRepository;
use AtarCms\Content\TermService;
use AtarCms\Content\TermUrls;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Media\MediaStorage;
use AtarCms\Media\MediaUploader;
use AtarCms\Media\Share\ShareCard;
use AtarCms\Search\SearchIndexer;
use AtarCms\Search\SearchRepository;
use AtarCms\Search\TextNormalizer;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use AtarCms\Tests\Integration\DatabaseTestCase;
use AtarCms\Tests\Support\Cast;
use AtarCms\Tests\Support\FrozenClock;
use AtarCms\Tests\Support\MemoryLogger;
use AtarCms\View\EditorSettings;
use AtarCms\View\ThemeConfig;
use Closure;
use Psr\Log\LoggerInterface;

abstract class ContentTestCase extends DatabaseTestCase
{
    protected FrozenClock $clock;

    protected Collections $collections;

    protected EntryRepository $entries;

    protected TermRepository $terms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateCore();
        $this->clock = new FrozenClock('2026-09-28 12:00:00');
        $collections = require dirname(__DIR__, 2) . '/Fixtures/site/config/collections.php';
        $taxonomies = require dirname(__DIR__, 2) . '/Fixtures/site/config/taxonomies.php';
        self::assertIsArray($collections);
        self::assertIsArray($taxonomies);
        $this->collections = new Collections($collections, new Taxonomies($taxonomies));
        $this->entries = new EntryRepository($this->db, $this->collections);
        $this->terms = new TermRepository($this->db);
    }

    /**
     * Inserts a row directly, bypassing validation: by default a published "articles" entry "hello" in "en".
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertEntry(array $overrides = []): int
    {
        $now = $this->clock->now();

        return $this->entries->insert([
            'collection' => 'articles',
            'locale' => 'en',
            'translation_group' => EntryRepository::newTranslationGroup(),
            'slug' => 'hello',
            'title' => 'Hello',
            'status' => 'published',
            'published_at' => $now->modify('-1 hour'),
            'parent_id' => null,
            'position' => 0,
            'author_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'data' => [],
            ...$overrides,
        ]);
    }

    /**
     * Inserts a term directly, bypassing validation: by default the "topics" term "PHP" (slug "php") in "en".
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertTerm(array $overrides = []): int
    {
        $now = $this->clock->now();

        return $this->terms->insert([
            'taxonomy' => 'topics',
            'locale' => 'en',
            'translation_group' => EntryRepository::newTranslationGroup(),
            'slug' => 'php',
            'name' => 'PHP',
            'parent_id' => null,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    /**
     * Inserts a menu item directly, bypassing validation: by default the "main" URL item "Home" linking to "/" in "en".
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertMenuItem(array $overrides = []): int
    {
        $now = $this->clock->now();

        return $this->menuRepository()->insert([
            'location' => 'main',
            'locale' => 'en',
            'parent_id' => null,
            'position' => 0,
            'label' => 'Home',
            'target_type' => 'url',
            'entry_id' => null,
            'term_id' => null,
            'url' => '/',
            'new_tab' => false,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    protected function mediaRepository(): MediaRepository
    {
        return new MediaRepository($this->db);
    }

    /**
     * A MediaStorage in the test's temporary directory; only for tests that use the TempDirectory trait.
     */
    protected function mediaStorage(): MediaStorage
    {
        if (!method_exists($this, 'tempDir')) {
            self::fail('Use the TempDirectory trait to write media files');
        }
        $root = $this->tempDir();
        self::assertIsString($root);

        return new MediaStorage($root . '/uploads', $root . '/cache/media', $root . '/tmp');
    }

    protected function mediaService(?MediaStorage $storage = null): MediaService
    {
        $storage ??= $this->mediaStorage();

        return new MediaService(
            $this->db,
            $this->mediaRepository(),
            new MediaUploader(new MediaConfig(), $storage, new ImageProcessor(), $this->clock),
            $storage,
            new Locales('en', ['en', 'ru']),
            new AuditLog($this->db, $this->clock),
            $this->clock,
            $this->siteSettings(),
            new MediaFolderRepository($this->db),
        );
    }

    /**
     * Inserts a media row directly, bypassing the uploader (no file is written): by default a 1200×800 JPEG
     * "photo.jpg" with a random path.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertMedia(array $overrides = []): int
    {
        $now = $this->clock->now();

        return $this->mediaRepository()->insert([
            'path' => '2026/09/' . bin2hex(random_bytes(16)) . '.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'width' => 1200,
            'height' => 800,
            'alt' => [],
            'uploaded_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    protected function menuRepository(): MenuRepository
    {
        return new MenuRepository($this->db);
    }

    protected function menuService(): MenuService
    {
        return new MenuService(
            $this->db,
            $this->menuRepository(),
            $this->entries,
            $this->terms,
            $this->collections->taxonomies,
            new ThemeConfig(['main' => 'Main', 'footer' => 'Footer']),
            new Locales('en', ['en', 'ru']),
            new AuditLog($this->db, $this->clock),
            $this->clock,
        );
    }

    protected function revisions(int $keep = 20): RevisionRepository
    {
        return new RevisionRepository($this->db, $this->clock, new Config(['content' => ['revisions' => $keep]]));
    }

    /**
     * The share image service of the fixture site; its files go to the test's temporary directory with the
     * TempDirectory trait, else to a directory no test reads (entries without a photo file never draw).
     */
    protected function shareImageService(?ThemeConfig $theme = null, string $appUrl = 'https://example.com', ?LoggerInterface $logger = null): ShareImages
    {
        $storage = method_exists($this, 'tempDir')
            ? $this->mediaStorage()
            : new MediaStorage(sys_get_temp_dir() . '/atar-share-unused/uploads', sys_get_temp_dir() . '/atar-share-unused/cache', sys_get_temp_dir() . '/atar-share-unused/tmp');

        return new ShareImages(
            $this->entries,
            $this->mediaRepository(),
            $storage,
            new ShareCard(new ImageProcessor()),
            $this->siteSettings(),
            new Config(['app' => ['url' => $appUrl]]),
            $theme ?? new ThemeConfig(),
            $logger ?? new MemoryLogger(),
        );
    }

    protected function service(int $keepRevisions = 20, ?EntryAccess $access = null, ?RichTextTheme $richText = null, ?ShareImages $shareImages = null): EntryService
    {
        $locales = new Locales('en', ['en', 'ru']);

        return new EntryService(
            $this->db,
            $this->entries,
            $this->revisions($keepRevisions),
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs(new Router(), $locales, $this->rootSlugs()),
            $this->entryTerms(),
            $this->entryMedia(),
            $access ?? $this->entryAccess(),
            $this->collections,
            $this->searchIndexer(),
            $richText ?? $this->richTextTheme(),
            $this->translator(),
            $shareImages ?? $this->shareImageService(),
            new SlugHistoryRepository($this->db),
        );
    }

    /**
     * The core catalogs and the fixture theme's, like the fixture site's.
     */
    protected function translator(): Translator
    {
        return new Translator(
            new Locales('en', ['en', 'ru']),
            [dirname(__DIR__, 3) . '/resources/lang', dirname(__DIR__, 2) . '/Fixtures/site/themes/test/lang'],
            new MemoryLogger(),
        );
    }

    /**
     * A theme with the given editor styles and no templates or stylesheets: it keeps only their classes and CKEditor's own.
     *
     * @param array<string, array{label: string, element: string, classes: list<string>}> $styles
     */
    protected function richTextTheme(array $styles = []): RichTextTheme
    {
        return new RichTextTheme(new EditorSettings(styles: $styles), '');
    }

    /**
     * EntryAccess with the default roles plus $roles (name => ['label' => …, 'permissions' => […]]).
     *
     * @param array<string, array{label: string, permissions: list<string>}> $roles
     */
    protected function entryAccess(array $roles = []): EntryAccess
    {
        return new EntryAccess(new Authorizer(new Roles([...Roles::DEFAULTS, ...$roles])));
    }

    protected function searchIndexer(?Collections $collections = null): SearchIndexer
    {
        return new SearchIndexer($collections ?? $this->collections, new TextNormalizer(), new SearchRepository($this->db));
    }

    protected function entryMedia(): EntryMedia
    {
        return new EntryMedia($this->mediaRepository());
    }

    protected function entryTerms(): EntryTerms
    {
        return new EntryTerms($this->terms, $this->termService(), $this->collections->taxonomies);
    }

    protected function termService(?Router $router = null): TermService
    {
        $locales = new Locales('en', ['en', 'ru']);

        return new TermService(
            $this->db,
            $this->terms,
            $this->collections->taxonomies,
            $locales,
            new AuditLog($this->db, $this->clock),
            $this->clock,
            new ReservedSlugs($router ?? new Router(), $locales, $this->rootSlugs()),
            $this->collections,
            new SlugHistoryRepository($this->db),
        );
    }

    /**
     * URLs of the fixture's routes, in English and Russian, at https://example.com.
     */
    protected function localizedUrls(): LocalizedUrls
    {
        $router = new Router();
        ContentModule::registerRoutes($router, $this->collections);

        return new LocalizedUrls($router, new Locales('en', ['en', 'ru']), new Config(['app' => ['url' => 'https://example.com']]));
    }

    protected function termUrls(): TermUrls
    {
        return new TermUrls($this->collections->taxonomies, $this->localizedUrls(), new Config(['app' => ['url' => 'https://example.com']]), $this->terms);
    }

    protected function entryUrls(): EntryUrls
    {
        return new EntryUrls($this->entries, $this->localizedUrls(), new Config(['app' => ['url' => 'https://example.com']]), $this->terms, $this->termUrls());
    }

    protected function siteSettings(): SiteSettings
    {
        return new SiteSettings(new SettingsRepository($this->db));
    }

    protected function settingsService(): SettingsService
    {
        return new SettingsService(
            $this->db,
            new SettingsRepository($this->db),
            $this->siteSettings(),
            $this->mediaRepository(),
            new Locales('en', ['en', 'ru']),
            new AuditLog($this->db, $this->clock),
            $this->clock,
        );
    }

    protected function rootSlugs(): RootSlugs
    {
        return new RootSlugs($this->collections, $this->entries, $this->terms);
    }

    protected function user(string $email = 'ann@example.com', string $role = 'admin'): User
    {
        return new UserRepository($this->db, $this->clock)->create($email, $role);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    protected static function assertValidation(array $errors, Closure $action, array $params = []): void
    {
        try {
            $action();
        } catch (ValidationFailed $e) {
            self::assertEquals($errors, $e->errors);
            self::assertEquals($params, $e->params);

            return;
        }
        self::fail('Expected ValidationFailed');
    }

    /**
     * @return list<string> audit actions, oldest first
     */
    protected function auditActions(): array
    {
        return array_map(
            static fn(array $row): string => Cast::string($row['action']),
            $this->db->select('SELECT action FROM audit_log ORDER BY id'),
        );
    }
}
