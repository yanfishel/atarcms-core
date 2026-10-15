<?php

declare(strict_types=1);

namespace AtarCms\Settings;

/**
 * The site's map (one for every language, shown on the page of config content.contact_page): a Google Maps iframe without
 * a key. Either Google's own embed (Share → Embed a map: its "pb" value, which holds the place, the view and the
 * language) or a search for an address or "lat,lng" at a zoom level, embedded in the page's language. GoogleMapInput
 * reads what the admin pastes; SettingsService stores toArray() as JSON. The Content Security Policy always allows
 * FRAME_SOURCE in frames.
 */
final readonly class GoogleMap
{
    public const string FRAME_SOURCE = 'https://www.google.com';

    /** Waze's universal link: opens the app on a phone, the live map elsewhere. */
    public const string WAZE = 'https://waze.com/ul';

    /** The zoom levels: 3 (a continent) to 19 (a building); without one, a few streets around the place. */
    public const int MIN_ZOOM = 3;

    public const int MAX_ZOOM = 19;

    public const int DEFAULT_ZOOM = 16;

    /** The longest embed value and query kept. */
    public const int MAX_PB = 4000;

    public const int MAX_QUERY = 255;

    /** The characters of Google's "pb" value as it stands in the embed address (percent escapes included). */
    public const string PB_PATTERN = '/^[A-Za-z0-9!._%:+-]{1,' . self::MAX_PB . '}$/D';

    private function __construct(
        public ?string $pb,
        public ?string $query,
        public int $zoom,
    ) {}

    /**
     * Google's embed of a place: the "pb" value of https://www.google.com/maps/embed?pb=…, checked by the caller.
     */
    public static function embed(string $pb): self
    {
        return new self($pb, null, self::DEFAULT_ZOOM);
    }

    /**
     * A search for $query (an address, or "lat,lng") at $zoom.
     */
    public static function query(string $query, int $zoom = self::DEFAULT_ZOOM): self
    {
        return new self(null, $query, $zoom);
    }

    /**
     * The stored shape back (toArray()); anything else, or a value out of bounds, is no map.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        if (array_keys($data) === ['embed']) {
            return is_string($data['embed']) && preg_match(self::PB_PATTERN, $data['embed']) === 1 ? self::embed($data['embed']) : null;
        }
        $query = $data['query'] ?? null;
        $zoom = $data['zoom'] ?? null;
        if (count($data) !== 2 || !is_string($query) || !is_int($zoom) || !self::validQuery($query) || $zoom < self::MIN_ZOOM || $zoom > self::MAX_ZOOM) {
            return null;
        }

        return self::query($query, $zoom);
    }

    /**
     * A query fit to keep: 1 to MAX_QUERY characters of UTF-8 without control characters, trimmed.
     */
    public static function validQuery(string $query): bool
    {
        return mb_check_encoding($query, 'UTF-8') && $query === trim($query) && $query !== ''
            && mb_strlen($query) <= self::MAX_QUERY && preg_match('/[\x00-\x1F\x7F]/', $query) !== 1;
    }

    public function isEmbed(): bool
    {
        return $this->pb !== null;
    }

    /**
     * @return array{embed: string}|array{query: string, zoom: int}
     */
    public function toArray(): array
    {
        return $this->pb !== null ? ['embed' => $this->pb] : ['query' => (string) $this->query, 'zoom' => $this->zoom];
    }

    /**
     * The iframe's address: Google's embed as it is (its language is inside it), or the search in $locale.
     */
    public function embedUrl(string $locale): string
    {
        if ($this->pb !== null) {
            return self::FRAME_SOURCE . '/maps/embed?pb=' . $this->pb;
        }

        return self::FRAME_SOURCE . '/maps?q=' . urlencode((string) $this->query) . '&z=' . $this->zoom . '&hl=' . urlencode($locale) . '&output=embed';
    }

    /**
     * The place on Google Maps itself ("Open in Google Maps"): the search, or an embed's centre ("!2d<lng>!3d<lat>");
     * null for an embed without one.
     */
    public function linkUrl(): ?string
    {
        $query = $this->pb !== null ? $this->embedCentre() : $this->query;

        return $query === null ? null : self::FRAME_SOURCE . '/maps/search/?api=1&query=' . urlencode($query);
    }

    /**
     * A route in Waze ("Directions in Waze"): to the point ("lat,lng" coordinates or an embed's centre), else a search
     * of the address; null for an embed without a centre.
     */
    public function wazeUrl(): ?string
    {
        $point = $this->pb !== null ? $this->embedCentre() : ($this->coordinates() !== null ? $this->query : null);
        if ($point !== null) {
            return self::WAZE . '?ll=' . urlencode($point) . '&navigate=yes';
        }

        return $this->query === null ? null : self::WAZE . '?q=' . urlencode($this->query) . '&navigate=yes';
    }

    /**
     * The point of a "lat,lng" query (the Coordinates tab shows it again), else null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function coordinates(): ?array
    {
        return $this->query !== null && preg_match('/^(-?\d{1,2}\.\d{6}),(-?\d{1,3}\.\d{6})$/D', $this->query, $match) === 1 ? [$match[1], $match[2]] : null;
    }

    /**
     * An embed's centre ("!2d<lng>!3d<lat>") as "lat,lng", else null.
     */
    private function embedCentre(): ?string
    {
        return $this->pb !== null && preg_match('/!2d(-?\d{1,3}(?:\.\d+)?)!3d(-?\d{1,2}(?:\.\d+)?)/', $this->pb, $match) === 1 ? $match[2] . ',' . $match[1] : null;
    }
}
