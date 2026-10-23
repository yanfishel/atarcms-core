<?php

declare(strict_types=1);

namespace AtarCms\Http;

/**
 * Content negotiation on the Accept header (RFC 9110, section 12.5.1): media ranges with their q-values. A media type
 * takes the quality of the most specific range that matches it ("text/markdown" before "text/*" before "*\/*"); a
 * missing or empty header accepts everything. Ranges that are not "type/subtype", and ranges with a malformed q, are
 * ignored; media type parameters other than q are too.
 */
final class AcceptHeader
{
    /**
     * The quality (0–1) $accept gives the media type $type ("text/markdown"); 0 when no range matches.
     */
    public static function quality(string $accept, string $type): float
    {
        if (trim($accept) === '') {
            return 1.0;
        }
        [$main] = explode('/', strtolower($type), 2);
        $type = strtolower($type);
        $best = null;
        $specificity = -1;
        foreach (explode(',', $accept) as $range) {
            $parameters = explode(';', $range);
            $media = strtolower(trim(array_shift($parameters)));
            $rank = match (true) {
                $media === $type => 2,
                $media === $main . '/*' => 1,
                $media === '*/*' => 0,
                default => -1,
            };
            if ($rank <= $specificity) {
                continue;
            }
            $quality = self::q($parameters);
            if ($quality === null) {
                continue;
            }
            $best = $quality;
            $specificity = $rank;
        }

        return $best ?? 0.0;
    }

    /**
     * Whether $accept prefers $type to $over: it accepts $type with a strictly higher quality (a tie goes to $over).
     */
    public static function prefers(string $accept, string $type, string $over): bool
    {
        $quality = self::quality($accept, $type);

        return $quality > 0.0 && $quality > self::quality($accept, $over);
    }

    /**
     * The q parameter (1 without one), or null when it is malformed.
     *
     * @param list<string> $parameters
     */
    private static function q(array $parameters): ?float
    {
        foreach ($parameters as $parameter) {
            $pair = explode('=', $parameter, 2);
            if (strtolower(trim($pair[0])) !== 'q') {
                continue;
            }
            $value = trim($pair[1] ?? '');

            return preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $value) === 1 ? (float) $value : null;
        }

        return 1.0;
    }
}
