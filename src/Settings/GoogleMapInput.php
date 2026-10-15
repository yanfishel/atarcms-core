<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Content\ValidationErrors;
use AtarCms\Http\Exception\ValidationFailed;
use InvalidArgumentException;

/**
 * Reads the map the admin enters (the contact map dialog's four tabs, and bin/atar settings:import) into a GoogleMap:
 *
 * - "embed": Google Maps → Share → Embed a map, the whole <iframe> or its src (entities decoded, http made https), or
 *   the bare "pb" value; only https://www.google.com/maps/embed?pb=… is taken, and only its pb is kept;
 * - "link": a full Google Maps address (www.google.<tld>/maps/…, maps.google.<tld>): the marker of its data
 *   ("!3d<lat>!4d<lng>"), else its view ("@<lat>,<lng>,<N>z"; a view in metres has no zoom), else its "q"/"query"
 *   search, else the text of its "/place/<name>/" or "/search/<text>"; short links (maps.app.goo.gl, goo.gl/maps)
 *   are refused with their own message, since following them needs a request to Google;
 * - "address": free text, whitespace collapsed, 1 to GoogleMap::MAX_QUERY characters;
 * - "coordinates": "latitude" and "longitude" (a pair pasted into the latitude is split), "zoom" (empty: the default);
 * - "query": the stored shape (settings:import): "query" and "zoom".
 *
 * Errors are translation keys under the input names (embed, link, address, query, latitude, longitude, zoom).
 */
final class GoogleMapInput
{
    public const array METHODS = ['embed', 'link', 'address', 'coordinates', 'query'];

    /** The inputs each method reads. */
    public const array FIELDS = [
        'embed' => ['embed'],
        'link' => ['link'],
        'address' => ['address'],
        'coordinates' => ['latitude', 'longitude', 'zoom'],
        'query' => ['query', 'zoom'],
    ];

    private const string EMBED_ERROR = 'validation.map.embed_invalid';

    private const string LINK_ERROR = 'validation.map.link_invalid';

    private const string COORDINATES_ERROR = 'validation.map.coordinates';

    private const string NUMBER = '-?\d{1,3}(?:\.\d+)?';

    /**
     * @param array<mixed> $input the submitted values, by input name
     *
     * @throws ValidationFailed
     * @throws InvalidArgumentException for a method that is not one of METHODS
     */
    public static function parse(string $method, array $input): GoogleMap
    {
        $value = static fn(string $name): string => is_string($input[$name] ?? null) ? $input[$name] : '';
        $errors = new ValidationErrors();
        $map = match ($method) {
            'embed' => self::embed($value('embed'), $errors),
            'link' => self::link($value('link'), $errors),
            'address' => self::text($value('address'), GoogleMap::DEFAULT_ZOOM, 'address', $errors),
            'query' => self::text($value('query'), self::zoom($value('zoom'), $errors), 'query', $errors),
            'coordinates' => self::coordinates($value('latitude'), $value('longitude'), $value('zoom'), $errors),
            default => throw new InvalidArgumentException(sprintf('Unknown map input "%s"', $method)),
        };
        $errors->throwIfAny();

        return $map ?? throw new ValidationFailed([self::FIELDS[$method][0] => 'validation.field.invalid']);
    }

    private static function embed(string $value, ValidationErrors $errors): ?GoogleMap
    {
        $value = trim($value);
        if (str_starts_with($value, '!')) {
            $pb = $value;
        } else {
            if (str_starts_with($value, '<')) {
                $value = preg_match('/^<iframe\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/is', $value, $match) === 1 ? $match[2] : '';
            }
            $url = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = (string) preg_replace('~^http://~i', 'https://', $url);
            $parts = parse_url($url);
            $pb = is_array($parts)
                && strtolower($parts['scheme'] ?? '') === 'https'
                && strtolower($parts['host'] ?? '') === 'www.google.com'
                && !isset($parts['user']) && !isset($parts['port'])
                && ($parts['path'] ?? '') === '/maps/embed'
                && preg_match('/(?:^|&)pb=([^&]*)/', $parts['query'] ?? '', $match) === 1 ? $match[1] : '';
        }
        if (preg_match(GoogleMap::PB_PATTERN, $pb) !== 1) {
            $errors->add('embed', self::EMBED_ERROR);

            return null;
        }

        return GoogleMap::embed($pb);
    }

    private static function link(string $value, ValidationErrors $errors): ?GoogleMap
    {
        $value = trim($value);
        if (preg_match('~^(?:https?://)?(?:maps\.app\.goo\.gl|goo\.gl/maps)(?:[/?#]|$)~i', $value) === 1) {
            $errors->add('link', 'validation.map.link_short');

            return null;
        }
        $parts = parse_url($value);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $google = preg_match('/^(?:(www)\.|(maps)\.)?google\.(?:[a-z]{2,3}|co\.[a-z]{2}|com\.[a-z]{2})$/D', $host, $hostMatch) === 1;
        if (!is_array($parts)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !$google
            || isset($parts['user']) || isset($parts['port'])
            || (($hostMatch[2] ?? '') !== 'maps' && preg_match('~^/maps(?:/|$)~', $path) !== 1)) {
            $errors->add('link', self::LINK_ERROR);

            return null;
        }
        $zoom = GoogleMap::DEFAULT_ZOOM;
        $view = preg_match('~/@(' . self::NUMBER . '),(' . self::NUMBER . '),(\d+(?:\.\d+)?)([zma])~', $path, $viewMatch) === 1 ? $viewMatch : null;
        if ($view !== null && $view[4] === 'z') {
            $zoom = max(GoogleMap::MIN_ZOOM, min(GoogleMap::MAX_ZOOM, (int) floor((float) $view[3])));
        }
        $point = null;
        if (preg_match('/!3d(' . self::NUMBER . ')!4d(' . self::NUMBER . ')/', $value, $marker) === 1) {
            $point = [$marker[1], $marker[2]];
        } elseif ($view !== null) {
            $point = [$view[1], $view[2]];
        }
        if ($point !== null) {
            $query = self::point($point[0], $point[1]);
            if ($query === null) {
                $errors->add('link', self::LINK_ERROR);
            }

            return $query === null ? null : GoogleMap::query($query, $zoom);
        }
        parse_str($parts['query'] ?? '', $search);
        $text = $search['q'] ?? $search['query'] ?? null;
        if (!is_string($text) && preg_match('~^/maps/(?:place|search)/([^/@]+)~', $path, $place) === 1) {
            $text = urldecode($place[1]);
        }
        $text = is_string($text) ? self::collapse($text) : '';
        if ($text === '' || mb_strlen($text) > GoogleMap::MAX_QUERY) {
            $errors->add('link', self::LINK_ERROR);

            return null;
        }

        return GoogleMap::query($text, $zoom);
    }

    private static function text(string $value, ?int $zoom, string $field, ValidationErrors $errors): ?GoogleMap
    {
        $value = self::collapse($value);
        if ($value === '') {
            $errors->add($field, 'validation.map.address_required');

            return null;
        }
        if (mb_strlen($value) > GoogleMap::MAX_QUERY) {
            $errors->add($field, 'validation.map.address_long', ['max' => GoogleMap::MAX_QUERY]);

            return null;
        }

        return $zoom === null ? null : GoogleMap::query($value, $zoom);
    }

    private static function coordinates(string $latitude, string $longitude, string $zoom, ValidationErrors $errors): ?GoogleMap
    {
        $latitude = trim($latitude);
        $longitude = trim($longitude);
        // A position pasted whole ("55.7558, 37.6173" as map sites copy it, or two numbers separated by a space).
        if ($longitude === '' && preg_match('/^(' . self::NUMBER . ')\s*[,;]\s*(' . self::NUMBER . ')$/D', $latitude, $pair) === 1 && str_contains($pair[1], '.')) {
            [$latitude, $longitude] = [$pair[1], $pair[2]];
        } elseif ($longitude === '' && preg_match('/^(' . self::NUMBER . ')\s+(' . self::NUMBER . ')$/D', $latitude, $pair) === 1) {
            [$latitude, $longitude] = [$pair[1], $pair[2]];
        }
        if (self::degrees($latitude, 90) === null) {
            $errors->add('latitude', self::COORDINATES_ERROR);
        }
        if (self::degrees($longitude, 180) === null) {
            $errors->add('longitude', self::COORDINATES_ERROR);
        }
        $level = self::zoom($zoom, $errors);
        $query = self::point($latitude, $longitude);

        return $query === null || $level === null ? null : GoogleMap::query($query, $level);
    }

    /**
     * A zoom level from 3 to 19; empty is the default.
     */
    private static function zoom(string $value, ValidationErrors $errors): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return GoogleMap::DEFAULT_ZOOM;
        }
        if (preg_match('/^\d{1,2}$/D', $value) !== 1 || (int) $value < GoogleMap::MIN_ZOOM || (int) $value > GoogleMap::MAX_ZOOM) {
            $errors->add('zoom', 'validation.map.zoom', ['min' => GoogleMap::MIN_ZOOM, 'max' => GoogleMap::MAX_ZOOM]);

            return null;
        }

        return (int) $value;
    }

    /**
     * "lat,lng" with six decimals (about 10 cm), or null when either is not a number in range.
     */
    private static function point(string $latitude, string $longitude): ?string
    {
        $lat = self::degrees($latitude, 90);
        $lng = self::degrees($longitude, 180);

        return $lat === null || $lng === null ? null : sprintf('%.6F,%.6F', $lat, $lng);
    }

    private static function degrees(string $value, int $limit): ?float
    {
        return preg_match('/^' . self::NUMBER . '$/D', $value) === 1 && abs((float) $value) <= $limit ? (float) $value : null;
    }

    /**
     * Text on one line: control characters and whitespace collapsed to single spaces, trimmed; not UTF-8 is empty.
     */
    private static function collapse(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value)) : '';
    }
}
