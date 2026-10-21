<?php

declare(strict_types=1);

namespace AtarCms\Content\Llms;

use AtarCms\Content\Entry;

/**
 * One public entry or term of LlmsContent: its title and absolute URL; an entry also carries itself and the entries
 * its sitemap chunk loaded (its ancestors among them), so its markdown needs no second ancestors query.
 */
final readonly class LlmsLink
{
    /**
     * @param array<int, Entry> $known
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?Entry $entry = null,
        public array $known = [],
    ) {}
}
