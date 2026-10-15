<?php

declare(strict_types=1);

namespace AtarCms\I18n;

/**
 * Per-page language alternates set by a controller, e.g. the translations of an entry (locale => path).
 * LocalizedUrls::alternates() prefers it over the route-based alternates.
 */
final readonly class Alternates
{
    /**
     * @param array<string, string> $paths locale => path
     */
    public function __construct(public array $paths) {}
}
