<?php

declare(strict_types=1);

namespace AtarCms\Media\Share;

/**
 * What one share card shows: the photo (a local image file), the title and the brand (with its layout).
 */
final readonly class ShareCardInput
{
    /**
     * @param bool $rtl the title's paragraph direction: that of the entry's language (TextDirection), not of its text
     */
    public function __construct(
        public string $photoFile,
        public string $title,
        public ShareBrand $brand,
        public bool $rtl = false,
    ) {}
}
