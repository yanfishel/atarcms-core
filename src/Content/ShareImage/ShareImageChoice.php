<?php

declare(strict_types=1);

namespace AtarCms\Content\ShareImage;

use AtarCms\Media\Media;

/**
 * The picture an entry is shared with (og:image): its generated share image, else its main photo, else none (the
 * site's sharing image of the settings applies then).
 */
final readonly class ShareImageChoice
{
    /**
     * @param string|null $generated the generated share image's file name
     * @param Media|null $photo the main photo, when there is no generated image
     */
    public function __construct(
        public ?string $generated = null,
        public ?Media $photo = null,
    ) {}
}
