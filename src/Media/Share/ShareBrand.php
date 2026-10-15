<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

/**
 * The site's brand on a share card: the logo file (the site icon's original or the AtarCMS logo PNG), the site name
 * (the short name in the panel layout), the domain and the accent colour (the admin colour).
 */
final readonly class ShareBrand
{
    /**
     * @param string $logoFile a local image file, cover-cropped to a square and rounded
     * @param string $logoSource what the logo is, for fingerprints ("icon:{id}:{path}" or "atar:{preset}")
     * @param string $accent the accent colour as "#rrggbb"
     * @param bool $rtl whether the brand's language is written right to left (the site name's paragraph direction)
     */
    public function __construct(
        public string $name,
        public string $shortName,
        public string $domain,
        public string $accent,
        public string $logoFile,
        public string $logoSource,
        public ShareLayout $layout = ShareLayout::DEFAULT,
        public bool $rtl = false,
    ) {}

    /**
     * The name drawn by this brand's layout.
     */
    public function shownName(): string
    {
        return $this->layout === ShareLayout::Panel ? $this->shortName : $this->name;
    }
}
