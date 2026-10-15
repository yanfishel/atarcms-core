<?php

declare(strict_types=1);

namespace AtarCms\Content\Field;

/**
 * One slide of a slides field (Field::slides()), as themes read it: an image of the media library and optional
 * texts. A slide with a url links somewhere; with a button too, the theme draws an explicit button. A slide with a
 * video plays it instead of the picture, which stays its poster and the fallback without motion.
 */
final readonly class Slide
{
    public function __construct(
        public int $imageId,
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $url = null,
        public ?string $button = null,
        public ?int $videoId = null,
    ) {}
}
