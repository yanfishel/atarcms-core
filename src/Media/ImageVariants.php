<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\View\ThemeConfig;

/**
 * The variants /media/{variant}/{path} serves: the active theme's plus the reserved ones of the core, so they work with
 * any theme: the "admin" thumbnail and the site icon's sizes (ICONS: square PNGs for the favicon, the Apple touch icon
 * and the web app manifest; MediaUrls builds them with ".png" unless the original is a PNG).
 */
final readonly class ImageVariants
{
    public const string ADMIN = 'admin';

    /** The site icon's variants: name => the side in pixels. */
    public const array ICONS = ['icon_32' => 32, 'icon_180' => 180, 'icon_192' => 192, 'icon_512' => 512];

    /** @var array<string, ImageVariant> */
    public array $variants;

    /**
     * @param array<string, ImageVariant> $theme
     */
    public function __construct(array $theme = [])
    {
        $reserved = [self::ADMIN => new ImageVariant(self::ADMIN, 320, 320, ImageVariant::CONTAIN, 75)];
        foreach (self::ICONS as $name => $size) {
            $reserved[$name] = new ImageVariant($name, $size, $size, ImageVariant::CROP);
        }
        $this->variants = [...$theme, ...$reserved];
    }

    public static function fromTheme(ThemeConfig $theme): self
    {
        return new self($theme->images);
    }

    /**
     * Whether $name is one of the site icon's variants (always PNG).
     */
    public static function isIcon(string $name): bool
    {
        return isset(self::ICONS[$name]);
    }

    public function get(string $name): ?ImageVariant
    {
        return $this->variants[$name] ?? null;
    }

    /**
     * The theme variants that can share a srcset with $variant: the same fit and, for "crop", the same aspect ratio
     * ("contain" keeps every image's own ratio). The reserved variants (admin, icons) only go with themselves.
     *
     * @return list<ImageVariant>
     */
    public function family(ImageVariant $variant): array
    {
        if ($variant->name === self::ADMIN || self::isIcon($variant->name)) {
            return [$variant];
        }
        $family = [];
        foreach ($this->variants as $candidate) {
            if ($candidate->name === self::ADMIN || self::isIcon($candidate->name) || $candidate->fit !== $variant->fit) {
                continue;
            }
            if ($variant->fit === ImageVariant::CROP && $candidate->width * (int) $variant->height !== $variant->width * (int) $candidate->height) {
                continue;
            }
            $family[] = $candidate;
        }

        return $family;
    }
}
