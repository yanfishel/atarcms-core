<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * The site's map as a theme draws it ($this->contactMap()): the iframe's address in the page's language and, when the
 * map has a place to open, its address on Google Maps ("Open in Google Maps") and a route in Waze ("Directions in
 * Waze"). The iframe's host is GoogleMap::FRAME_SOURCE, which the Content Security Policy always allows in frames; the
 * links are plain links.
 */
final readonly class MapView
{
    public function __construct(
        public string $embedUrl,
        public ?string $linkUrl,
        public ?string $wazeUrl = null,
    ) {}

    public static function of(GoogleMap $map, string $locale): self
    {
        return new self($map->embedUrl($locale), $map->linkUrl(), $map->wazeUrl());
    }
}
