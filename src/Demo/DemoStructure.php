<?php

declare(strict_types=1);

namespace AtarCms\Demo;

use AtarCms\Content\Slug;
use AtarCms\Kernel\Config;

/**
 * The structure demo:seed builds, from the site's optional config/demo.php (the config key "demo"): the sections of
 * the root taxonomy ("sections": slug => ['names' => [locale => name], 'children' => [slug => [locale => name]]]), the
 * pages of the collection routed at the root ("pages": slug => [locale => title]) and the news tags ("tags": locale =>
 * names), in the shapes of DemoTexts. A key the file leaves out takes the generic DemoTexts one; without the file the
 * seed is the generic one. problems() checks the file before anything is deleted; a malformed part is never used.
 */
final class DemoStructure
{
    private const array KEYS = ['sections', 'pages', 'tags'];

    /**
     * @param list<string> $locales the site's language codes, for the language-key check (empty: not checked)
     */
    private function __construct(private readonly mixed $demo, private readonly array $locales) {}

    public static function fromConfig(Config $config): self
    {
        $site = $config->get('locales');
        $codes = [];
        if (is_array($site) && is_array($site['locales'] ?? null)) {
            foreach ($site['locales'] as $code) {
                if (is_string($code)) {
                    $codes[] = $code;
                }
            }
        }

        return new self($config->get('demo'), $codes);
    }

    /**
     * @return array<string, array{names: array<string, string>, children: array<string, array<string, string>>}>
     */
    public function sections(): array
    {
        $part = $this->part('sections');
        if ($part === null) {
            return DemoTexts::SECTIONS;
        }

        return self::readSections($part, $problems);
    }

    /**
     * @return array<string, array<string, string>> slug => locale => title
     */
    public function pages(): array
    {
        $part = $this->part('pages');
        if ($part === null) {
            return DemoTexts::PAGES;
        }

        return self::readSlugs('pages', $part, $problems);
    }

    /**
     * @return array<string, list<string>> locale => names
     */
    public function tags(): array
    {
        $part = $this->part('tags');
        if ($part === null) {
            return DemoTexts::TAGS;
        }

        return self::readTags($part, $problems);
    }

    /**
     * What is wrong with config/demo.php, in plain words; empty without the file and for a usable one.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if ($this->demo === null) {
            return [];
        }
        if (!is_array($this->demo)) {
            return ['config/demo.php must return an array with "sections", "pages" and/or "tags".'];
        }
        $problems = [];
        foreach (array_keys($this->demo) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $problems[] = sprintf('config/demo.php has the unknown key "%s": only "sections", "pages" and "tags" are read.', $key);
            }
        }
        foreach (self::KEYS as $key) {
            $part = $this->demo[$key] ?? null;
            if ($part === null) {
                continue;
            }
            if (!is_array($part)) {
                $problems[] = sprintf('config/demo.php: "%s" must be an array.', $key);

                continue;
            }
            match ($key) {
                'sections' => $sections = self::readSections($part, $problems),
                'pages' => $pages = self::readSlugs('pages', $part, $problems),
                'tags' => $tags = self::readTags($part, $problems),
            };
        }

        // A language key outside the site's languages is almost always a typo whose texts would silently fall
        // back to lorem; an address used twice would only surface as a skipped item after the wipe.
        foreach ($sections ?? [] as $slug => $section) {
            $this->checkLanguages(sprintf('the section "%s"', $slug), array_keys($section['names']), $problems);
            foreach ($section['children'] as $childSlug => $names) {
                $this->checkLanguages(sprintf('the section "%s"', $childSlug), array_keys($names), $problems);
            }
        }
        foreach ($pages ?? [] as $slug => $titles) {
            $this->checkLanguages(sprintf('the page "%s"', $slug), array_keys($titles), $problems);
        }
        $this->checkLanguages('"tags"', array_keys($tags ?? []), $problems);

        $slugs = [];
        foreach ($sections ?? [] as $slug => $section) {
            $slugs[] = $slug;
            foreach (array_keys($section['children']) as $child) {
                $slugs[] = (string) $child;
            }
        }
        foreach (array_count_values($slugs) as $slug => $count) {
            if ($count > 1) {
                $problems[] = sprintf('config/demo.php: the address "%s" is used by two sections.', $slug);
            }
        }
        foreach (array_keys($pages ?? []) as $slug) {
            if (array_key_exists($slug, $sections ?? [])) {
                $problems[] = sprintf('config/demo.php: the page "%s" and a section share the address "%s".', $slug, $slug);
            }
        }

        return $problems;
    }

    /**
     * @param list<int|string> $keys
     * @param list<string> $problems
     *
     * @param-out list<string> $problems
     */
    private function checkLanguages(string $what, array $keys, array &$problems): void
    {
        if ($this->locales === []) {
            return;
        }
        foreach ($keys as $locale) {
            if (!in_array((string) $locale, $this->locales, true)) {
                $problems[] = sprintf("config/demo.php: %s has a text in \"%s\", but the site's languages are %s.", $what, $locale, implode(', ', $this->locales));
            }
        }
    }

    /**
     * The part $key of the file, or null when the file has none (the DemoTexts one is used then).
     *
     * @return array<mixed>|null
     */
    private function part(string $key): ?array
    {
        if (!is_array($this->demo)) {
            return null;
        }
        $part = $this->demo[$key] ?? null;

        return is_array($part) ? $part : null;
    }

    /**
     * @param array<mixed> $part
     * @param list<string>|null $problems
     *
     * @param-out list<string> $problems
     *
     * @return array<string, array{names: array<string, string>, children: array<string, array<string, string>>}>
     */
    private static function readSections(array $part, ?array &$problems): array
    {
        $problems ??= [];
        if (self::isList('"sections"', $part, $problems)) {
            return [];
        }
        $sections = [];
        foreach ($part as $slug => $section) {
            $slug = (string) $slug;
            if (!Slug::isValid($slug)) {
                $problems[] = sprintf('config/demo.php: the section "%s" needs an address of Latin letters, digits and hyphens.', $slug);

                continue;
            }
            if (!is_array($section)) {
                $problems[] = sprintf('config/demo.php: the section "%s" must be an array with "names" and "children".', $slug);

                continue;
            }
            $unknown = array_diff(array_map('strval', array_keys($section)), ['names', 'children']);
            if ($unknown !== []) {
                $problems[] = sprintf('config/demo.php: the section "%s" has the unknown key "%s": only "names" and "children" are read.', $slug, reset($unknown));

                continue;
            }
            $names = self::names($section['names'] ?? []);
            if ($names === null) {
                $problems[] = sprintf('config/demo.php: the names of the section "%s" must be locale => name.', $slug);

                continue;
            }
            $children = $section['children'] ?? [];
            if (!is_array($children)) {
                $problems[] = sprintf('config/demo.php: the children of the section "%s" must be slug => [locale => name].', $slug);

                continue;
            }
            $before = count($problems);
            $read = self::isList(sprintf('the children of the section "%s"', $slug), $children, $problems)
                ? []
                : self::readSlugs('sections', $children, $problems);
            if (count($problems) === $before) {
                $sections[$slug] = ['names' => $names, 'children' => $read];
            }
        }

        return $sections;
    }

    /**
     * slug => [locale => text] (pages, or the children of a section).
     *
     * @param array<mixed> $part
     * @param list<string>|null $problems
     *
     * @param-out list<string> $problems
     *
     * @return array<string, array<string, string>>
     */
    private static function readSlugs(string $what, array $part, ?array &$problems): array
    {
        $problems ??= [];
        $label = $what === 'pages' ? 'page' : 'section';
        if ($what === 'pages' && self::isList('"pages"', $part, $problems)) {
            return [];
        }
        $read = [];
        foreach ($part as $slug => $texts) {
            $slug = (string) $slug;
            if (!Slug::isValid($slug)) {
                $problems[] = sprintf('config/demo.php: the %s "%s" needs an address of Latin letters, digits and hyphens.', $label, $slug);

                continue;
            }
            $names = self::names($texts);
            if ($names === null) {
                $problems[] = sprintf('config/demo.php: the %s "%s" must be locale => %s.', $label, $slug, $what === 'pages' ? 'title' : 'name');

                continue;
            }
            $read[$slug] = $names;
        }

        return $read;
    }

    /**
     * @param array<mixed> $part
     * @param list<string>|null $problems
     *
     * @param-out list<string> $problems
     *
     * @return array<string, list<string>>
     */
    private static function readTags(array $part, ?array &$problems): array
    {
        $problems ??= [];
        if (array_is_list($part) && $part !== []) {
            $problems[] = 'config/demo.php: "tags" must be locale => a list of names, like "ru" => ["News", "Guides"].';

            return [];
        }
        $tags = [];
        foreach ($part as $locale => $names) {
            $list = is_string($locale) && is_array($names) && array_is_list($names) ? self::texts($names) : null;
            if ($list === null) {
                $problems[] = sprintf('config/demo.php: "tags" must be locale => a list of names; "%s" is not.', $locale);

                continue;
            }
            $tags[(string) $locale] = $list;
        }

        return $tags;
    }

    /**
     * Reports $part when it is a list: the parts are keyed by addresses, a list has none.
     *
     * @param array<mixed> $part
     * @param list<string> $problems
     */
    private static function isList(string $what, array $part, array &$problems): bool
    {
        if ($part === [] || !array_is_list($part)) {
            return false;
        }
        $problems[] = sprintf('config/demo.php: %s must be keyed by their addresses (slug => ...), not a list.', $what);

        return true;
    }

    /**
     * locale => non-empty text, or null when $names is anything else.
     *
     * @return array<string, string>|null
     */
    private static function names(mixed $names): ?array
    {
        if (!is_array($names)) {
            return null;
        }
        $read = [];
        foreach ($names as $locale => $name) {
            if (!is_string($locale) || !is_string($name) || trim($name) === '') {
                return null;
            }
            $read[$locale] = $name;
        }

        return $read;
    }

    /**
     * The non-empty texts of $list, or null when one is anything else.
     *
     * @param list<mixed> $list
     *
     * @return list<string>|null
     */
    private static function texts(array $list): ?array
    {
        $texts = [];
        foreach ($list as $text) {
            if (!is_string($text) || trim($text) === '') {
                return null;
            }
            $texts[] = $text;
        }

        return $texts;
    }
}
