<?php

declare(strict_types=1);

namespace AtarCms\View;

use AtarCms\Kernel\ConfigException;

/**
 * The "editor" key of theme.php: what the admin rich-text editor takes from the theme.
 * - css: stylesheets inside the theme's assets/ directory, loaded into the admin editor (their rules must be scoped
 *   to .ck-content: the editor lives in the admin page);
 * - styles: entries of the editor's Style dropdown, name => [label, element (p, h2, h3, h4 or table), classes]; no
 *   two styles share a label (CKEditor names a style by it);
 * - templates: ready-made blocks, name => [label, file (a UTF-8 .html file inside the theme directory, at most 64 KB)];
 * - colors: the palette of the font colour, background and table colour pickers, a list of [label, color];
 * - fonts: the font family list, a list of [label, family] (stored text keeps only these families);
 * - fontSizes: name => [label, class] (the name "default" is the editor's own);
 * - markers: highlighters, name => [label, class, color of the toolbar swatch, type "marker" or "pen"].
 * Labels are translation keys or plain text. The files must exist when the theme is loaded. Without colors, fonts,
 * fontSizes or markers the editor keeps its own defaults (null); an empty list turns the font, size or marker
 * feature off and leaves the colour pickers without swatches.
 */
final readonly class EditorSettings
{
    /** A class name the editor may write: a letter or "_" first, then letters, digits, "_" or "-" (64 at most). */
    public const string CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D';

    public const array STYLE_ELEMENTS = ['p', 'h2', 'h3', 'h4', 'table'];

    public const int MAX_STYLE_CLASSES = 4;

    public const int MAX_TEMPLATE_BYTES = 65536;

    public const int MAX_COLORS = 30;

    public const int MAX_FONTS = 16;

    public const int MAX_FONT_SIZES = 8;

    public const int MAX_MARKERS = 12;

    public const array MARKER_TYPES = ['marker', 'pen'];

    private const array KEYS = ['css', 'styles', 'templates', 'colors', 'fonts', 'fontSizes', 'markers'];

    /** Relative paths whose segments never start with a dot (so no "..", no hidden files) and no backslashes. */
    private const string PATH = '~^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*\.%s$~D';

    /**
     * @param list<string> $css paths inside the theme's assets/ directory
     * @param array<string, array{label: string, element: string, classes: list<string>}> $styles in theme order
     * @param array<string, array{label: string, file: string}> $templates in theme order; files relative to the theme directory
     * @param list<array{label: string, color: string}>|null $colors null keeps the editor's palette
     * @param list<array{label: string, family: string}>|null $fonts families normalized (CssValue::fontFamily()); null keeps the editor's fonts
     * @param array<string, array{label: string, class: string}>|null $fontSizes null keeps the editor's sizes
     * @param array<string, array{label: string, class: string, color: string, type: string}>|null $markers null keeps the editor's markers
     */
    public function __construct(
        public array $css = [],
        public array $styles = [],
        public array $templates = [],
        public ?array $colors = null,
        public ?array $fonts = null,
        public ?array $fontSizes = null,
        public ?array $markers = null,
    ) {}

    /**
     * Validates the "editor" key of theme.php; the files it names are looked up in $themeDirectory.
     */
    public static function fromArray(mixed $config, string $themeDirectory): self
    {
        if (!is_array($config)) {
            throw new ConfigException('theme.php: "editor" must be an array with the keys ' . implode(', ', self::KEYS));
        }
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ConfigException(sprintf('Unknown key "%s" under "editor" in theme.php; known keys: %s', $key, implode(', ', self::KEYS)));
            }
        }
        $directory = rtrim($themeDirectory, '/');

        return new self(
            self::css($config['css'] ?? [], $directory),
            self::styles($config['styles'] ?? []),
            self::templates($config['templates'] ?? [], $directory),
            array_key_exists('colors', $config) ? self::colors($config['colors']) : null,
            array_key_exists('fonts', $config) ? self::fonts($config['fonts']) : null,
            array_key_exists('fontSizes', $config) ? self::fontSizes($config['fontSizes']) : null,
            array_key_exists('markers', $config) ? self::markers($config['markers']) : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function css(mixed $css, string $directory): array
    {
        if (!is_array($css) || !array_is_list($css)) {
            throw new ConfigException('theme.php: "editor.css" must be a list of stylesheet paths inside the theme\'s assets/ directory');
        }
        $paths = [];
        foreach ($css as $path) {
            if (!is_string($path) || preg_match(sprintf(self::PATH, 'css'), $path) !== 1) {
                throw new ConfigException(sprintf(
                    'Invalid editor stylesheet %s in theme.php; use a relative path to a .css file inside the theme\'s assets/ directory, such as "css/editor.css"',
                    is_string($path) ? '"' . $path . '"' : get_debug_type($path),
                ));
            }
            if (!is_file($directory . '/assets/' . $path)) {
                throw new ConfigException(sprintf('The editor stylesheet "%s" in theme.php does not exist in %s/assets', $path, $directory));
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return array<string, array{label: string, element: string, classes: list<string>}>
     */
    private static function styles(mixed $styles): array
    {
        if (!is_array($styles)) {
            throw new ConfigException('theme.php: "editor.styles" must be an array of style name => settings');
        }
        $parsed = [];
        $labels = [];
        foreach ($styles as $name => $style) {
            $name = self::name($name, 'style');
            $style = self::settings($style, sprintf('style "%s"', $name), ['label', 'element', 'classes']);
            $element = $style['element'] ?? null;
            if (!is_string($element) || !in_array($element, self::STYLE_ELEMENTS, true)) {
                throw new ConfigException(sprintf('The editor style "%s" in theme.php: "element" must be one of %s', $name, implode(', ', self::STYLE_ELEMENTS)));
            }
            $classes = $style['classes'] ?? null;
            if (
                !is_array($classes) || !array_is_list($classes) || $classes === [] || count($classes) > self::MAX_STYLE_CLASSES
                || array_filter($classes, static fn(mixed $class): bool => !is_string($class) || preg_match(self::CLASS_PATTERN, $class) !== 1) !== []
            ) {
                throw new ConfigException(sprintf(
                    'The editor style "%s" in theme.php: "classes" must list 1 to %d class names (a letter or "_" first, then letters, digits, "_" or "-", at most 64 characters)',
                    $name,
                    self::MAX_STYLE_CLASSES,
                ));
            }
            $label = self::label($style, sprintf('style "%s"', $name));
            // CKEditor's Style feature identifies a style by its name, which is this label: a second style with the
            // same label would shadow the first in the dropdown.
            if (isset($labels[$label])) {
                throw new ConfigException(sprintf(
                    'The editor style "%s" in theme.php repeats the label "%s" of the style "%s"; the editor tells its styles apart by their labels',
                    $name,
                    $label,
                    $labels[$label],
                ));
            }
            $labels[$label] = $name;
            /** @var list<string> $classes */
            $parsed[$name] = ['label' => $label, 'element' => $element, 'classes' => array_values(array_unique($classes))];
        }

        return $parsed;
    }

    /**
     * @return array<string, array{label: string, file: string}>
     */
    private static function templates(mixed $templates, string $directory): array
    {
        if (!is_array($templates)) {
            throw new ConfigException('theme.php: "editor.templates" must be an array of template name => settings');
        }
        $parsed = [];
        foreach ($templates as $name => $template) {
            $name = self::name($name, 'template');
            $template = self::settings($template, sprintf('template "%s"', $name), ['label', 'file']);
            $file = $template['file'] ?? null;
            if (!is_string($file) || preg_match(sprintf(self::PATH, 'html'), $file) !== 1) {
                throw new ConfigException(sprintf(
                    'The editor template "%s" in theme.php: "file" must be a relative path to an .html file inside the theme directory, such as "editor/note.html"',
                    $name,
                ));
            }
            $path = $directory . '/' . $file;
            if (!is_file($path)) {
                throw new ConfigException(sprintf('The editor template file "%s" in theme.php does not exist in %s', $file, $directory));
            }
            if ((int) filesize($path) > self::MAX_TEMPLATE_BYTES) {
                throw new ConfigException(sprintf('The editor template file "%s" in theme.php is larger than 64 KB', $file));
            }
            // The admin form sends the blocks to the editor as JSON, which needs UTF-8: catch it here, at boot.
            if (!mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
                throw new ConfigException(sprintf('The editor template file "%s" in theme.php is not valid UTF-8; save it as UTF-8', $file));
            }
            $parsed[$name] = ['label' => self::label($template, sprintf('template "%s"', $name)), 'file' => $file];
        }

        return $parsed;
    }

    /**
     * @return list<array{label: string, color: string}>
     */
    private static function colors(mixed $colors): array
    {
        if (!is_array($colors) || !array_is_list($colors) || count($colors) > self::MAX_COLORS) {
            throw new ConfigException(sprintf('theme.php: "editor.colors" must be a list of at most %d colours, each with the keys label and color', self::MAX_COLORS));
        }
        $parsed = [];
        foreach ($colors as $index => $color) {
            $what = sprintf('colour %d', $index + 1);
            $color = self::settings($color, $what, ['label', 'color']);
            $value = $color['color'] ?? null;
            if (!is_string($value) || !CssValue::isColor($value)) {
                throw new ConfigException(sprintf('The editor %s in theme.php: "color" must be a CSS colour (#hex, rgb(), hsl() or a colour name)', $what));
            }
            $parsed[] = ['label' => self::label($color, $what), 'color' => CssValue::normalize('color', $value) ?? $value];
        }

        return $parsed;
    }

    /**
     * @return list<array{label: string, family: string}>
     */
    private static function fonts(mixed $fonts): array
    {
        if (!is_array($fonts) || !array_is_list($fonts) || count($fonts) > self::MAX_FONTS) {
            throw new ConfigException(sprintf('theme.php: "editor.fonts" must be a list of at most %d fonts, each with the keys label and family', self::MAX_FONTS));
        }
        $parsed = [];
        $families = [];
        foreach ($fonts as $index => $font) {
            $what = sprintf('font %d', $index + 1);
            $font = self::settings($font, $what, ['label', 'family']);
            $family = is_string($font['family'] ?? null) ? CssValue::fontFamily($font['family']) : null;
            if ($family === null) {
                throw new ConfigException(sprintf(
                    'The editor %s in theme.php: "family" must list 1 to %d font names separated by commas (letters, digits, spaces, "_" and "-", optionally in quotes)',
                    $what,
                    CssValue::MAX_FAMILIES,
                ));
            }
            if (isset($families[$family])) {
                throw new ConfigException(sprintf('The editor %s in theme.php repeats the family "%s"', $what, $family));
            }
            $families[$family] = true;
            $parsed[] = ['label' => self::label($font, $what), 'family' => $family];
        }

        return $parsed;
    }

    /**
     * @return array<string, array{label: string, class: string}>
     */
    private static function fontSizes(mixed $sizes): array
    {
        if (!is_array($sizes) || count($sizes) > self::MAX_FONT_SIZES) {
            throw new ConfigException(sprintf('theme.php: "editor.fontSizes" must be an array of at most %d size name => settings', self::MAX_FONT_SIZES));
        }
        $parsed = [];
        $classes = [];
        foreach ($sizes as $name => $size) {
            $name = self::name($name, 'font size');
            if ($name === 'default') {
                throw new ConfigException('The editor font size name "default" in theme.php is reserved by the editor (it removes the size); choose another name');
            }
            $what = sprintf('font size "%s"', $name);
            $size = self::settings($size, $what, ['label', 'class']);
            $class = self::className($size, $what, $classes);
            $parsed[$name] = ['label' => self::label($size, $what), 'class' => $class];
        }

        return $parsed;
    }

    /**
     * @return array<string, array{label: string, class: string, color: string, type: string}>
     */
    private static function markers(mixed $markers): array
    {
        if (!is_array($markers) || count($markers) > self::MAX_MARKERS) {
            throw new ConfigException(sprintf('theme.php: "editor.markers" must be an array of at most %d marker name => settings', self::MAX_MARKERS));
        }
        $parsed = [];
        $classes = [];
        foreach ($markers as $name => $marker) {
            $name = self::name($name, 'marker');
            $what = sprintf('marker "%s"', $name);
            $marker = self::settings($marker, $what, ['label', 'class', 'color', 'type']);
            $class = self::className($marker, $what, $classes);
            $color = $marker['color'] ?? null;
            if (!is_string($color) || !CssValue::isColor($color)) {
                throw new ConfigException(sprintf('The editor %s in theme.php: "color" must be a CSS colour (#hex, rgb(), hsl() or a colour name)', $what));
            }
            $type = $marker['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::MARKER_TYPES, true)) {
                throw new ConfigException(sprintf('The editor %s in theme.php: "type" must be marker or pen', $what));
            }
            $parsed[$name] = ['label' => self::label($marker, $what), 'class' => $class, 'color' => CssValue::normalize('color', $color) ?? $color, 'type' => $type];
        }

        return $parsed;
    }

    /**
     * The "class" of a font size or marker: one class name, used by no other entry of the same list.
     *
     * @param array<mixed> $settings
     * @param array<string, true> $classes the classes of the entries before; this one is added
     */
    private static function className(array $settings, string $what, array &$classes): string
    {
        $class = $settings['class'] ?? null;
        if (!is_string($class) || preg_match(self::CLASS_PATTERN, $class) !== 1) {
            throw new ConfigException(sprintf(
                'The editor %s in theme.php: "class" must be a class name (a letter or "_" first, then letters, digits, "_" or "-", at most 64 characters)',
                $what,
            ));
        }
        if (isset($classes[$class])) {
            throw new ConfigException(sprintf('The editor %s in theme.php repeats the class "%s"', $what, $class));
        }
        $classes[$class] = true;

        return $class;
    }

    private static function name(int|string $name, string $kind): string
    {
        if (!is_string($name) || preg_match(ThemeConfig::LOCATION_PATTERN, $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid editor %s name "%s" in theme.php; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
                $kind,
                $name,
            ));
        }

        return $name;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<mixed>
     */
    private static function settings(mixed $settings, string $what, array $keys): array
    {
        if (!is_array($settings)) {
            throw new ConfigException(sprintf('The editor %s in theme.php must be an array with the keys %s', $what, implode(', ', $keys)));
        }
        foreach (array_keys($settings) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new ConfigException(sprintf('Unknown key "%s" in the editor %s in theme.php; known keys: %s', $key, $what, implode(', ', $keys)));
            }
        }

        return $settings;
    }

    /**
     * @param array<mixed> $settings
     */
    private static function label(array $settings, string $what): string
    {
        $label = $settings['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw new ConfigException(sprintf('The editor %s in theme.php needs a label (a translation key or plain text)', $what));
        }

        return $label;
    }
}
