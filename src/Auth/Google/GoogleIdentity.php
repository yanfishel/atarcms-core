<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public ?string $hostedDomain,
        public string $name,
        /** The profile photo address of the "picture" claim, unchecked (AvatarDownloader::isAllowed()). */
        public ?string $picture = null,
    ) {}
}
