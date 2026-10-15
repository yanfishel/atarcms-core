<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Kernel\Config;
use InvalidArgumentException;

/**
 * Public URLs of media: originals at /uploads/{path}, variants at /media/{variant}/{path} (original format) and
 * /media/{variant}/{path}.webp or .avif (the site icon's: {path}.png), with "?v=" and the variant's version so that
 * changed settings get new URLs.
 */
final class MediaUrls
{
    public function __construct(
        private readonly ImageVariants $variants,
        private readonly Config $config,
    ) {}

    public function original(Media $media): string
    {
        return '/uploads/' . $media->path;
    }

    /**
     * A generated share image (ShareImages): its name changes with its content, so it needs no version.
     */
    public function share(string $name): string
    {
        return '/uploads/share/' . $name;
    }

    public function variant(Media $media, string $variant, bool $webp = false): string
    {
        $spec = $this->spec($media, $variant);
        if (ImageVariants::isIcon($spec->name)) {
            // The site icon's variants are always PNG.
            $path = $media->mime === 'image/png' ? $media->path : $media->path . '.png';
        } else {
            $path = $webp && $media->mime !== 'image/webp' ? $media->path . '.webp' : $media->path;
        }

        return '/media/' . $spec->name . '/' . $path . '?v=' . $spec->version();
    }

    /**
     * The AVIF twin of a variant (/media/{variant}/{path}.avif, WebP originals included); only for sites where
     * ImageFormats::$avif is on, elsewhere the URL answers 404. The site icon's variants have none.
     */
    public function avif(Media $media, string $variant): string
    {
        $spec = $this->spec($media, $variant);
        if (ImageVariants::isIcon($spec->name)) {
            throw new InvalidArgumentException(sprintf('The site icon variant "%s" is PNG only', $variant));
        }

        return '/media/' . $spec->name . '/' . $media->path . '.avif?v=' . $spec->version();
    }

    private function spec(Media $media, string $variant): ImageVariant
    {
        $spec = $this->variants->get($variant)
            ?? throw new InvalidArgumentException(sprintf('Unknown image variant "%s"; declare it under "images" in theme.php', $variant));
        if (!$media->isImage()) {
            throw new InvalidArgumentException(sprintf('Media #%d is not an image', $media->id));
        }

        return $spec;
    }

    /**
     * $url prefixed with app.url, e.g. for og:image.
     */
    public function absolute(string $url): string
    {
        return $this->config->appUrl() . $url;
    }
}
