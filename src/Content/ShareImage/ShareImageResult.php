<?php

declare(strict_types=1);

namespace AtarCms\Content\ShareImage;

/**
 * The outcome of ShareImages::sync() and the entry's share image file name afterwards (null without one).
 */
final readonly class ShareImageResult
{
    public function __construct(
        public ShareImageOutcome $outcome,
        public ?string $name,
    ) {}
}
