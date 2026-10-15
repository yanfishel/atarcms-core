<?php

declare(strict_types=1);

namespace AtarCms\Demo;

use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryInput;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\EntryService;
use AtarCms\Content\EntryStatus;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Menu\MenuItemInput;
use AtarCms\Content\Menu\MenuService;
use AtarCms\Content\Menu\MenuTarget;
use AtarCms\Content\Preset\PresetPages;
use AtarCms\Content\Slug;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use AtarCms\Content\TermService;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Paths;
use AtarCms\Media\MediaConfig;
use AtarCms\Media\MediaFolderService;
use AtarCms\Media\MediaService;
use AtarCms\Media\Share\ShareFont;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\SettingsInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\ThemeConfig;
use DateTimeImmutable;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Fills an empty site (DemoWiper) with demo content for every configured language, through the services only: the
 * site settings, placeholder pictures in a "Demo" folder, the sections of the root taxonomy (three, two subsections
 * each), two articles per leaf section, six news with tags, the pages of the root collection, the preset pages
 * (PresetPages makes them again after the wipe; the "privacy" and "accessibility" presets get a privacy policy and an
 * accessibility statement as templates, any other preset text by field type), every single() page (fields filled by
 * type), the menus of the theme's other locations (without the presets: a theme links those itself, the skeleton from
 * its footer) and the main menu's order. Translations are linked (createTranslation, then the language's texts). The
 * same configuration always gives the same content.
 */
final class DemoSeeder
{
    public const int NEWS = 6;

    public const int ARTICLES_PER_SECTION = 2;

    /** Placeholder pictures: purpose => [width, height, how many]. */
    public const array PICTURES = [
        'hero' => ['Hero', 1600, 900, 3],
        'card' => ['Card', 1200, 800, 8],
        'gallery' => ['Gallery', 1200, 800, 6],
    ];

    private Randomizer $random;

    /** @var array<string, LoremText> */
    private array $lorem = [];

    /** @var array<string, list<int>> purpose => media ids */
    private array $pictures = [];

    private ?User $actor = null;

    /** Numbers the entries' addresses within one seed. */
    private int $serial = 0;

    /** @var array<string, array<string, int>> locale => what => how many */
    private array $counts = [];

    public function __construct(
        private readonly Collections $collections,
        private readonly Locales $locales,
        private readonly EntryService $entries,
        private readonly TermService $terms,
        private readonly MenuService $menus,
        private readonly MainMenu $mainMenu,
        private readonly ThemeConfig $theme,
        private readonly MediaService $media,
        private readonly MediaFolderService $folders,
        private readonly SettingsService $settingsService,
        private readonly SiteSettings $settings,
        private readonly PlaceholderImages $images,
        private readonly Clock $clock,
        private readonly MediaConfig $mediaConfig,
        private readonly Paths $paths,
        private readonly PresetPages $presets,
        private readonly EntryRepository $entryRepository,
    ) {
        $this->random = self::randomizer();
    }

    /**
     * @return array<string, array<string, int>> locale => counts (sections, entries per collection, tags, menu items),
     *                                           plus "" => ['files' => n]
     */
    public function seed(?User $actor): array
    {
        $this->random = self::randomizer();
        $this->lorem = [];
        $this->counts = [];
        $this->serial = 0;
        $this->pictures = [];
        $this->actor = $actor;

        $this->uploadPictures();
        $this->saveSettings();
        // The preset pages first, like "bin/atar migrate" makes them: no section or page may take their addresses.
        $this->presets->sync();
        [$tops, $leaves] = $this->createSections();

        $now = $this->clock->now();
        $pages = [];
        $singles = [];
        $day = 2;
        foreach ($this->collections->all() as $collection) {
            if ($collection->route === null) {
                continue;
            }
            if ($collection->single) {
                $single = $this->createSingle($collection);
                if ($collection->route !== '/') {
                    $singles[] = $single;
                }

                continue;
            }
            foreach (array_keys($collection->presets) as $preset) {
                $this->fillPreset($collection, $preset);
            }
            if ($collection->routeTaxonomy !== null && $collection->routeTaxonomy === $this->collections->taxonomies->root()?->name) {
                foreach ($leaves as $leaf) {
                    for ($i = 0; $i < self::ARTICLES_PER_SECTION; $i++) {
                        $this->createArticle($collection, $leaf, $i, $now->modify(sprintf('-%d days', $day)));
                        $day += 3;
                    }
                }

                continue;
            }
            if ($collection->route === Taxonomy::ROOT_ROUTE) {
                foreach (DemoTexts::PAGES as $slug => $titles) {
                    $pages[] = $this->createPage($collection, $slug, $titles, $now->modify('-30 days'));
                }

                continue;
            }
            $count = $collection->archive !== null ? self::NEWS : 2;
            for ($i = 0; $i < $count; $i++) {
                $this->createNews($collection, $i, $now->modify(sprintf('-%d days', 1 + $i * 4)));
            }
        }
        $this->createMenus([...$pages, ...$singles]);
        $this->orderMainMenu(count($tops));

        return [...$this->counts, '' => ['files' => count($this->pictures, COUNT_RECURSIVE) - count($this->pictures)]];
    }

    /**
     * What keeps this site from being seeded, checked before anything is deleted: GD that draws JPEG and text
     * (FreeType), JPEG uploads allowed (config/media.php), writable upload and cache directories, and at least one
     * collection with a route.
     *
     * @param bool $create create missing directories (false for a dry run: a missing one only needs a writable parent)
     *
     * @return list<string> the problems, in plain words; empty when the site can be seeded
     */
    public function problems(bool $create = true): array
    {
        $problems = [];
        if (!function_exists('imagejpeg') || !ShareFont::available()) {
            $problems[] = 'PHP\'s GD extension must write JPEG and draw text (FreeType): the demo pictures are drawn with it.';
        }
        if (!$this->mediaConfig->allows('image/jpeg')) {
            $problems[] = 'config/media.php must allow image/jpeg uploads: the demo pictures are JPEG files.';
        }
        foreach (['uploads', 'cache', 'tmp'] as $directory) {
            $path = $this->paths->storage($directory);
            if (!$create && !is_dir($path)) {
                $parent = dirname($path);
                while (!is_dir($parent) && dirname($parent) !== $parent) {
                    $parent = dirname($parent);
                }
                if (!is_writable($parent)) {
                    $problems[] = sprintf('The directory %s cannot be created.', $path);
                }
            } elseif (!is_dir($path) && !@mkdir($path, 0o777, true)) {
                $problems[] = sprintf('The directory %s cannot be created.', $path);
            } elseif (!is_writable($path)) {
                $problems[] = sprintf('The directory %s is not writable.', $path);
            }
        }
        $routed = array_filter($this->collections->all(), static fn(Collection $c): bool => $c->route !== null);
        if ($routed === []) {
            $problems[] = 'config/collections.php has no collection with a route: there is nothing to fill.';
        }

        return $problems;
    }

    private static function randomizer(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', 'atar-demo', true)));
    }

    private function lorem(string $locale): LoremText
    {
        return $this->lorem[$locale] ??= new LoremText($this->random, $locale);
    }

    private function count(string $locale, string $what, int $add = 1): void
    {
        $this->counts[$locale][$what] = ($this->counts[$locale][$what] ?? 0) + $add;
    }

    private function uploadPictures(): void
    {
        $folder = $this->folders->create('Demo', null, $this->actor, null);
        $colour = 0;
        foreach (self::PICTURES as $purpose => [$label, $width, $height, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $bytes = $this->images->jpeg($label, $width, $height, $colour++);
                $file = new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, sprintf('%s-%d.jpg', $purpose, $i), 'image/jpeg');
                $media = $this->media->upload($file, $this->actor, null, $folder->id);
                $alt = [];
                foreach ($this->locales->all() as $locale) {
                    $alt[$locale] = DemoTexts::in(DemoTexts::ALT, $locale) ?? DemoTexts::ALT['en'];
                }
                $this->media->updateAlt($media, $alt, MediaService::version($media), $this->actor, null);
                $this->pictures[$purpose][] = $media->id;
            }
        }
    }

    private function picture(string $purpose, int $index): int
    {
        $ids = $this->pictures[$purpose];

        return $ids[$index % count($ids)];
    }

    /**
     * @return list<int>
     */
    private function gallery(int $offset, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->picture('gallery', $offset + $i);
        }

        return $ids;
    }

    private function saveSettings(): void
    {
        foreach ($this->locales->all() as $locale) {
            $kept = $this->settings->of($locale);
            $this->settingsService->save($locale, new SettingsInput(
                metaTitle: DemoTexts::in(DemoTexts::META_TITLE, $locale) ?? DemoTexts::META_TITLE['en'],
                metaDescription: DemoTexts::in(DemoTexts::META_DESCRIPTION, $locale) ?? DemoTexts::META_DESCRIPTION['en'],
                ogImageId: $this->picture('hero', 0),
                phone: '+1 555 0100',
                email: 'hello@example.com',
                siteName: DemoTexts::in(DemoTexts::SITE_NAME, $locale) ?? DemoTexts::SITE_NAME['en'],
                siteShortName: DemoTexts::in(DemoTexts::SHORT_NAME, $locale) ?? DemoTexts::SHORT_NAME['en'],
                themeColor: '#1e5e55',
                hideFromSearch: $kept->hideFromSearch,
                whatsapp: '+1 555 0100',
                telegram: 'atar_demo',
                address: DemoTexts::in(DemoTexts::ADDRESS, $locale) ?? DemoTexts::ADDRESS['en'],
                openingHours: DemoTexts::in(DemoTexts::HOURS, $locale) ?? DemoTexts::HOURS['en'],
                social: [
                    'facebook' => 'https://www.facebook.com/example',
                    'instagram' => 'https://www.instagram.com/example',
                    'youtube' => 'https://www.youtube.com/@example',
                ],
                yandexVerification: $kept->yandexVerification ?? '',
                googleVerification: $kept->googleVerification ?? '',
                accessibilityToolbar: $kept->accessibilityToolbar,
                accessibilityToolbarPosition: $kept->accessibilityToolbarPosition,
                yandexMetrica: $kept->yandexMetrica ?? '',
                yandexWebvisor: $kept->yandexWebvisor,
                googleAnalytics: $kept->googleAnalytics ?? '',
                metaPixel: $kept->metaPixel ?? '',
                googleAds: $kept->googleAds ?? '',
                vkPixel: $kept->vkPixel ?? '',
                tiktokPixel: $kept->tiktokPixel ?? '',
                chatButton: $kept->chatButton,
            ), $this->actor, null);
        }
        // The site's map (one for every language): Tel Aviv, by its coordinates.
        $this->settingsService->saveMap(GoogleMap::query('32.085300,34.781800', 15), $this->actor, null);
    }

    /**
     * The sections: top-level terms with their subsections (when the taxonomy is hierarchical), every language.
     *
     * @return array{0: list<array<string, Term>>, 1: list<array<string, Term>>} the top sections and the leaves, each a
     *                                                                          map locale => term
     */
    private function createSections(): array
    {
        $taxonomy = $this->collections->taxonomies->root();
        if ($taxonomy === null) {
            return [[], []];
        }
        $tops = [];
        $leaves = [];
        $position = 0;
        foreach (DemoTexts::SECTIONS as $slug => $section) {
            $top = $this->createTerm($taxonomy, $slug, $section['names'], null, $position++);
            $tops[] = $top;
            if (!$taxonomy->hierarchical) {
                $leaves[] = $top;

                continue;
            }
            $childPosition = 0;
            foreach ($section['children'] as $childSlug => $names) {
                $leaves[] = $this->createTerm($taxonomy, $childSlug, $names, $top, $childPosition++);
            }
        }

        return [$tops, $leaves];
    }

    /**
     * @param array<string, string> $names
     * @param array<string, Term>|null $parent
     *
     * @return array<string, Term>
     */
    private function createTerm(Taxonomy $taxonomy, string $slug, array $names, ?array $parent, int $position): array
    {
        $default = $this->locales->default();
        $name = fn(string $locale): string => DemoTexts::in($names, $locale) ?? $this->lorem($locale)->title(1, 2);
        $first = $this->terms->create($taxonomy, $default, new TermInput($name($default), $slug, $parent[$default]->id ?? null, $position), $this->actor, null);
        $terms = [$default => $first];
        $this->count($default, 'sections');
        foreach ($this->locales->all() as $locale) {
            if ($locale === $default) {
                continue;
            }
            $translation = $this->terms->createTranslation($first, $locale, $this->actor, null);
            $terms[$locale] = $this->terms->update($translation, new TermInput($name($locale), $slug, $translation->parentId), $this->actor, null);
            $this->count($locale, 'sections');
        }

        return $terms;
    }

    /**
     * Creates the entry in the default language and its translations (the collection's languages), published.
     *
     * @param callable(string): string $title locale => title
     * @param callable(string): array<string, mixed> $fields locale => field values
     * @param callable(string): (array<string, list<int|string>>|null) $terms locale => terms
     *
     * @return array<string, Entry> locale => entry
     */
    private function createEntry(Collection $collection, string $slug, callable $title, callable $fields, callable $terms, DateTimeImmutable $published): array
    {
        $default = $this->locales->default();
        $input = static function (string $locale) use ($collection, $slug, $title, $fields, $terms, $published): EntryInput {
            $text = $title($locale);

            return new EntryInput(
                title: $text,
                slug: $collection->single ? '' : $slug,
                status: EntryStatus::Published,
                publishedAt: $published,
                fields: $fields($locale),
                // The title for search engines is the title, which is also what share images show.
                metaTitle: $collection->single ? '' : $text,
                terms: $terms($locale),
            );
        };
        $first = $this->entries->create($collection, $default, $input($default), $this->actor, null);
        $entries = [$default => $first];
        $this->count($default, $collection->name);
        if (!$collection->translatable) {
            return $entries;
        }
        foreach ($this->locales->all() as $locale) {
            if ($locale === $default) {
                continue;
            }
            $translation = $this->entries->createTranslation($first, $locale, $this->actor, null);
            $entries[$locale] = $this->entries->update($translation, $input($locale), $this->actor, null);
            $this->count($locale, $collection->name);
        }

        return $entries;
    }

    /**
     * @param array<string, Term> $section locale => the article's leaf section
     */
    private function createArticle(Collection $collection, array $section, int $index, DateTimeImmutable $published): void
    {
        $titles = $this->titles();
        $picture = $this->random->getInt(0, 100);
        $this->createEntry(
            $collection,
            $this->slug($titles, $collection->name),
            static fn(string $locale): string => $titles[$locale],
            fn(string $locale): array => $this->fields($collection, $locale, $picture, withSummary: $index === 0, withGallery: $index === 0),
            static fn(string $locale): array => [(string) $collection->routeTaxonomy => [$section[$locale]->id]],
            $published,
        );
    }

    private function createNews(Collection $collection, int $index, DateTimeImmutable $published): void
    {
        $titles = $this->titles();
        $tagsField = null;
        foreach ($collection->fields as $field) {
            if ($field->type->name() === 'tags' && $field->taxonomy !== null) {
                $tagsField ??= $field;
            }
        }
        $tagIndexes = [$index % 4, ($index + 1) % 4];
        $this->createEntry(
            $collection,
            $this->slug($titles, $collection->name),
            static fn(string $locale): string => $titles[$locale],
            fn(string $locale): array => $this->fields($collection, $locale, $index, withSummary: true, withGallery: $index % 2 === 0),
            function (string $locale) use ($tagsField, $tagIndexes): ?array {
                if ($tagsField === null) {
                    return null;
                }
                $names = DemoTexts::TAGS[$locale] ?? null;
                $tags = [];
                foreach ($tagIndexes as $i) {
                    $tags[] = $names[$i] ?? $this->lorem($locale)->title(1, 1);
                }

                return [(string) $tagsField->taxonomy => $tags];
            },
            $published,
        );
    }

    /**
     * @param array<string, string> $titles
     *
     * @return array<string, Entry>
     */
    private function createPage(Collection $collection, string $slug, array $titles, DateTimeImmutable $published): array
    {
        return $this->createEntry(
            $collection,
            $slug,
            fn(string $locale): string => DemoTexts::in($titles, $locale) ?? $this->lorem($locale)->title(1, 3),
            fn(string $locale): array => $this->fields($collection, $locale, strlen($slug), withSummary: false, withGallery: false),
            static fn(string $locale): ?array => null,
            $published,
        );
    }

    /**
     * @return array<string, Entry>
     */
    private function createSingle(Collection $collection): array
    {
        return $this->createEntry(
            $collection,
            '',
            fn(string $locale): string => $this->lorem($locale)->title(),
            fn(string $locale): array => $this->fields($collection, $locale, 0, withSummary: true, withGallery: true),
            static fn(string $locale): ?array => null,
            $this->clock->now()->modify('-60 days'),
        );
    }

    /**
     * Fills the preset page $preset (made by PresetPages) in every language: it keeps its title; "privacy" gets the
     * privacy policy and "accessibility" the accessibility statement in every rich-text field, with the site's name and
     * contact details (saved by saveSettings() before), any other preset values by field type.
     */
    private function fillPreset(Collection $collection, string $preset): void
    {
        foreach ($this->entryRepository->presetPages($collection->name, $preset) as $locale => $entry) {
            $settings = $this->settings->of($locale);
            $site = $this->settings->siteName($locale, $settings);
            $text = match ($preset) {
                'privacy' => DemoTexts::privacy($locale, $site, $settings->email ?? '', $settings->phone ?? ''),
                'accessibility' => DemoTexts::statement($locale, $site, $settings->email ?? '', $settings->phone ?? ''),
                default => null,
            };
            $values = $text === null ? $this->fields($collection, $locale, 0, withSummary: false, withGallery: false) : [];
            foreach ($text === null ? [] : $collection->fields as $name => $field) {
                if ($field->type->name() === 'richText') {
                    $values[$name] = $text;
                }
            }
            $this->entries->update($entry, new EntryInput(
                title: $entry->title,
                fields: $values,
                // The title for search engines is the title, which is also what share images show.
                metaTitle: $entry->title,
            ), $this->actor, null);
            $this->count($locale, $collection->name);
        }
    }

    /**
     * Values for the collection's fields by their type: text a sentence (the home page's "lead" a fixed one), textarea
     * two lines, rich text paragraphs with a heading, a list and a quote (a minimal one: two sentences), image a
     * picture ("hero" fields the hero ones), gallery four pictures, slides three slides (slides()). A field named "summary" is filled only with
     * $withSummary (an article without one is shown in full on its section's page); other types stay empty.
     *
     * @return array<string, mixed>
     */
    private function fields(Collection $collection, string $locale, int $picture, bool $withSummary, bool $withGallery): array
    {
        $lorem = $this->lorem($locale);
        $values = [];
        foreach ($collection->fields as $name => $field) {
            $type = $field->type->name();
            $value = match ($type) {
                'text' => $name === 'lead' ? (DemoTexts::in(DemoTexts::LEAD, $locale) ?? $lorem->sentence()) : $lorem->sentence(4, 9),
                'textarea' => $lorem->sentence(4, 8) . "\n" . $lorem->sentence(4, 8),
                'richText' => $field->minimal ? '<p>' . $lorem->sentence(10, 18) . ' ' . $lorem->sentence(8, 14) . '</p>' : $this->richText($lorem),
                'image' => str_contains($name, 'hero') ? $this->picture('hero', $picture) : $this->picture('card', $picture),
                'gallery' => $withGallery ? $this->gallery($picture, 4) : null,
                'slides' => $this->slides($locale),
                default => null,
            };
            if ($name === 'summary' && !$withSummary) {
                $value = null;
            }
            if ($value !== null) {
                $values[$name] = self::limit($field, $value);
            }
        }

        return $values;
    }

    /**
     * The slides of a slides field (DemoTexts::SLIDES) on the hero pictures: the first with a button to the first
     * archive, the third linking the first page of the root collection (each link only when the site has it), in the
     * language's addresses.
     *
     * @return list<array<string, int|string>>
     */
    private function slides(string $locale): array
    {
        $prefix = $locale === $this->locales->default() ? '' : '/' . $locale;
        $archive = null;
        $page = null;
        foreach ($this->collections->all() as $collection) {
            $archive ??= $collection->archive;
            if ($collection->route === Taxonomy::ROOT_ROUTE) {
                $page ??= '/' . array_key_first(DemoTexts::PAGES);
            }
        }
        $links = [$archive, null, $page];
        $slides = [];
        foreach (DemoTexts::SLIDES as $index => $texts) {
            $slide = ['image' => $this->picture('hero', $index)];
            foreach ($texts as $part => $values) {
                $slide[$part] = DemoTexts::in($values, $locale) ?? $values['en'];
            }
            if ($links[$index] === null) {
                unset($slide['button']);
            } else {
                $slide['url'] = $prefix . $links[$index];
            }
            $slides[] = $slide;
        }
        $this->count($locale, 'slides', count($slides));

        return $slides;
    }

    private function richText(LoremText $lorem): string
    {
        return '<p>' . $lorem->paragraph(4) . '</p>'
            . '<h2>' . $lorem->title(2, 4) . '</h2>'
            . '<p>' . $lorem->paragraph(3) . '</p>'
            . '<ul><li>' . $lorem->sentence(4, 8) . '</li><li>' . $lorem->sentence(4, 8) . '</li><li>' . $lorem->sentence(4, 8) . '</li></ul>'
            . '<blockquote><p>' . $lorem->sentence(10, 16) . '</p></blockquote>'
            . '<p>' . $lorem->paragraph(4) . '</p>';
    }

    private static function limit(Field $field, mixed $value): mixed
    {
        if (is_string($value) && $field->max !== null && !str_starts_with($value, '<') && mb_strlen($value) > (int) $field->max) {
            return rtrim(mb_substr($value, 0, (int) $field->max - 1)) . '.';
        }

        return $value;
    }

    /**
     * A title per language, from the same random stream.
     *
     * @return array<string, string>
     */
    private function titles(): array
    {
        $titles = [];
        foreach ($this->locales->all() as $locale) {
            $titles[$locale] = $this->lorem($locale)->title();
        }

        return $titles;
    }

    /**
     * The address of an entry in every language: its default-language title as Latin letters, numbered so that equal
     * titles never clash.
     *
     * @param array<string, string> $titles
     */
    private function slug(array $titles, string $collection): string
    {
        $base = Slug::fromText($titles[$this->locales->default()] ?? '');

        return Slug::withSuffix($base === '' ? $collection : $base, ++$this->serial);
    }

    /**
     * Every other menu location of the theme gets, in every language, the given entries (the pages and the single
     * pages but the home page).
     *
     * @param list<array<string, Entry>> $entries
     */
    private function createMenus(array $entries): void
    {
        foreach (array_keys($this->mainMenu->menuLocations($this->theme)) as $location) {
            foreach ($this->locales->all() as $locale) {
                foreach ($entries as $translations) {
                    $entry = $translations[$locale] ?? null;
                    if ($entry !== null) {
                        $this->menus->create($location, $locale, new MenuItemInput('', MenuTarget::Entry, $entry->id), $this->actor, null);
                        $this->count($locale, 'menu_items');
                    }
                }
            }
        }
    }

    /**
     * The main menu: the first pinned collection (the home page) first, the sections, then the other pinned ones.
     */
    private function orderMainMenu(int $sections): void
    {
        $pinned = $this->mainMenu->pinned ?? [];
        if ($pinned === []) {
            return;
        }
        $order = [];
        foreach ($pinned as $index => $collection) {
            $order[$collection->name] = $index === 0 ? 0 : $sections + $index;
        }
        $this->settingsService->saveMainMenuOrder($order, $this->actor, null);
    }
}
