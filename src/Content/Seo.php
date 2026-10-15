<?php

declare(strict_types=1);

namespace AtarCms\Content;

final readonly class Seo
{
    public function __construct(
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
    ) {}
}
