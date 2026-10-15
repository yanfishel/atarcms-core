<?php

declare(strict_types=1);

namespace AtarCms\View;

use AtarCms\Kernel\ConfigException;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\Share\ShareLayout;

/**
 * The active theme's settings from its optional theme.php, which returns an array such as
 * ['menus' => ['main' => 'site.menus.main', 'footer' => 'Footer']] (menu location => label, a translation key or
 * plain text). "images" declares image variants (see ImageVariant), "editor" what the admin rich-text editor takes
 * from the theme (see EditorSettings), "share_image" the layout of the generated share images (see ShareLayout), "direction" whether
 * the theme lays out right to left too (see ThemeDirection); any
 * other key is a config error, so a typo surfaces at once.
 */
final readonly class ThemeConfig
{
    public const string LOCATION_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var list<string> */
    private const array KEYS = ['menus', 'images', 'editor', 'share_image', 'direction'];

    /** @var array<string, string> menu location => label, in theme order */
    public array $menus;

    /** @var array<string, ImageVariant> variant name => variant, in theme order */
    public array $images;

    /**
     * @param array<mixed> $menus location => label
     * @param array<mixed> $images variant name => spec (see ImageVariant::fromArray())
     */
    public function __construct(array $menus = [], array $images = [], public EditorSettings $editor = new EditorSettings(), public ShareLayout $shareLayout = ShareLayout::DEFAULT, public ThemeDirection $direction = ThemeDirection::Both)
    {
        $parsed = [];
        foreach ($menus as $location => $label) {
            if (!is_string($location) || preg_match(self::LOCATION_PATTERN, $location) !== 1) {
                throw new ConfigException(sprintf(
                    'Invalid menu location "%s" in theme.php; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
                    $location,
                ));
            }
            if (!is_string($label) || trim($label) === '') {
                throw new ConfigException(sprintf('The menu location "%s" in theme.php needs a label (a translation key or plain text)', $location));
            }
            $parsed[$location] = $label;
        }
        $this->menus = $parsed;
        $variants = [];
        foreach ($images as $name => $spec) {
            if (!is_string($name) || preg_match(ImageVariant::NAME_PATTERN, $name) !== 1) {
                throw new ConfigException(sprintf(
                    'Invalid image variant name "%s" in theme.php; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
                    $name,
                ));
            }
            if ($name === 'admin') {
                throw new ConfigException('The image variant name "admin" is reserved for the admin\'s thumbnails; choose another name in theme.php');
            }
            if (ImageVariants::isIcon($name)) {
                throw new ConfigException(sprintf('The image variant name "%s" is reserved for the site icon; choose another name in theme.php', $name));
            }
            $variants[$name] = ImageVariant::fromArray($name, $spec);
        }
        $this->images = $variants;
    }

    /**
     * @param array<mixed> $config the array theme.php returns
     * @param string $themeDirectory where the files of the "editor" key are looked up
     */
    public static function fromArray(array $config, string $themeDirectory = ''): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ConfigException(sprintf('Unknown key "%s" in theme.php; known keys: %s', $key, implode(', ', self::KEYS)));
            }
        }
        $menus = $config['menus'] ?? [];
        if (!is_array($menus)) {
            throw new ConfigException('theme.php: "menus" must be an array of location => label');
        }

        $images = $config['images'] ?? [];
        if (!is_array($images)) {
            throw new ConfigException('theme.php: "images" must be an array of variant name => settings');
        }

        return new self($menus, $images, EditorSettings::fromArray($config['editor'] ?? [], $themeDirectory), self::shareLayout($config['share_image'] ?? []), self::direction($config['direction'] ?? ThemeDirection::Both->value));
    }

    private static function direction(mixed $value): ThemeDirection
    {
        return (is_string($value) ? ThemeDirection::tryFrom($value) : null)
            ?? throw new ConfigException('theme.php: "direction" must be "both" or "ltr" (a theme laid out left to right only)');
    }

    /**
     * The layout of the generated share images: "share_image" => ['layout' => 'band'|'bottom'|'panel'], band by default.
     */
    private static function shareLayout(mixed $config): ShareLayout
    {
        if (!is_array($config)) {
            throw new ConfigException('theme.php: "share_image" must be an array such as [\'layout\' => \'band\']');
        }
        foreach (array_keys($config) as $key) {
            if ($key !== 'layout') {
                throw new ConfigException(sprintf('theme.php: unknown key "%s" under "share_image"; known keys: layout', $key));
            }
        }
        $layout = $config['layout'] ?? ShareLayout::DEFAULT->value;

        return (is_string($layout) ? ShareLayout::tryFrom($layout) : null) ?? throw new ConfigException(sprintf(
            'theme.php: unknown share image layout "%s"; use %s',
            is_scalar($layout) ? (string) $layout : get_debug_type($layout),
            implode(', ', array_map(static fn(ShareLayout $l): string => $l->value, array_slice(ShareLayout::cases(), 0, -1))) . ' or ' . ShareLayout::Panel->value,
        ));
    }

    /**
     * Reads {theme}/theme.php; a theme without one has no menu locations.
     */
    public static function load(string $themeDirectory): self
    {
        $file = rtrim($themeDirectory, '/') . '/theme.php';
        if (!is_file($file)) {
            return new self();
        }
        $config = (static fn(string $__file): mixed => require $__file)($file);
        if (!is_array($config)) {
            throw new ConfigException(sprintf('%s must return an array', $file));
        }

        return self::fromArray($config, $themeDirectory);
    }

    public function hasMenu(string $location): bool
    {
        return isset($this->menus[$location]);
    }
}
