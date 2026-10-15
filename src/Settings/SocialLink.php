<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * One filled link of the site settings: a social network page or a messenger chat. The theme draws its icon and label
 * from $network (the core ships no brand icons); $url is user input or built from it: escape it in templates.
 */
final readonly class SocialLink
{
    /**
     * @param string $network the setting's name: one of Settings::SOCIAL_NETWORKS or Settings::MESSENGERS
     * @param string $url the absolute link (https://…, or viber://… for Viber)
     */
    public function __construct(
        public string $network,
        public string $url,
    ) {}
}
