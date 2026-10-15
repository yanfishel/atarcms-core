<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaUrls;

/**
 * The site icon of the settings as URLs of its PNG variants (ImageVariants::ICONS), for siteHead(), the web app
 * manifest and /favicon.ico.
 */
final class SiteIcon
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaUrls $urls,
    ) {}

    /**
     * The icon's media row: null without an icon, or when it is not an image (anymore).
     */
    public function of(Settings $settings): ?Media
    {
        $icon = $settings->iconId === null ? null : $this->media->find($settings->iconId);

        return $icon !== null && $icon->isImage() ? $icon : null;
    }

    /**
     * The URL of the icon's $variant (one of ImageVariants::ICONS).
     */
    public function url(Media $icon, string $variant): string
    {
        return $this->urls->variant($icon, $variant);
    }
}
