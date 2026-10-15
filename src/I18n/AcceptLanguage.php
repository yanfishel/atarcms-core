<?php

declare(strict_types=1);

namespace AtarCms\I18n;

/**
 * The browser's Accept-Language header (RFC 9110) against a list of available locales: the range with the highest
 * quality whose tag, or its primary subtag ("ru-RU" → "ru"), is available. Ranges with q=0, the wildcard and anything
 * malformed are skipped; at most 20 ranges are read. The legacy "iw" counts as "he".
 */
final class AcceptLanguage
{
    private const int MAX_RANGES = 20;

    /**
     * @param list<string> $available lower-case locale codes
     */
    public static function negotiate(string $header, array $available): ?string
    {
        $ranges = [];
        foreach (array_slice(explode(',', $header), 0, self::MAX_RANGES) as $index => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            if (preg_match('/^[a-z]{1,8}(?:-[a-z0-9]{1,8})*$/', $tag) !== 1) {
                continue;
            }
            // The legacy code of Hebrew, still sent by some older browsers.
            $tag = (string) preg_replace('/^iw(?=-|$)/', 'he', $tag);
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\s*$/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                } else {
                    continue 2;
                }
            }
            if ($quality > 0) {
                $ranges[] = [$tag, $quality, $index];
            }
        }
        usort($ranges, static fn(array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        foreach ($ranges as [$tag]) {
            if (in_array($tag, $available, true)) {
                return $tag;
            }
            $primary = explode('-', $tag, 2)[0];
            if (in_array($primary, $available, true)) {
                return $primary;
            }
        }

        return null;
    }
}
