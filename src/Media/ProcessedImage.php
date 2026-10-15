<?php

declare(strict_types=1);

namespace AtarCms\Media;

final readonly class ProcessedImage
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}
