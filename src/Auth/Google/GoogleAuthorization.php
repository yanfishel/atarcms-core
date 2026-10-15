<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

final readonly class GoogleAuthorization
{
    public function __construct(
        public string $url,
        public string $state,
        public string $codeVerifier,
    ) {}
}
