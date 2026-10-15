<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

/**
 * The URLs a menu item may link to (spec §6.5: admin-provided URLs are validated). Anything outside this allowlist
 * is refused, which rules out javascript:, data: and other schemes in every spelling (case, leading whitespace,
 * control characters, HTML entities), protocol-relative "//host" and backslash tricks.
 */
final class MenuUrl
{
    public const int MAX_LENGTH = 2048;

    /**
     * Whitespace (Unicode included), control characters, backslashes and invisible format characters (\p{Cf}:
     * zero-width space/joiner, soft hyphen, word joiner, bidirectional overrides and marks, the BOM, …).
     */
    private const string FORBIDDEN = '/[\s\p{Z}\p{Cc}\p{Cf}\\\\]/u';

    /**
     * The trimmed URL when it is an http(s) URL with a host, a site path ("/about", never "//"), an "#anchor",
     * a mailto: link with a valid address or a tel: number; null otherwise. Non-ASCII hosts (IDN) and paths are
     * accepted and kept as entered.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH || !mb_check_encoding($url, 'UTF-8') || preg_match(self::FORBIDDEN, $url) !== 0) {
            return null;
        }
        if (str_starts_with($url, '#')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }
        // parse_url() is not used for the scheme: it reads "tel:112" as a host and a port.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) !== 1) {
            return null;
        }

        return match (strtolower($match[1])) {
            'http', 'https' => self::isWebUrl($url) ? $url : null,
            'mailto' => filter_var(rawurldecode(explode('?', substr($url, 7), 2)[0]), FILTER_VALIDATE_EMAIL) !== false ? $url : null,
            'tel' => preg_match('/^tel:\+?(?=[^0-9]*[0-9])[0-9().\-]{1,32}$/i', $url) === 1 ? $url : null,
            default => null,
        };
    }

    /**
     * An "http(s)://host…" URL. FILTER_VALIDATE_URL only knows ASCII, so it checks a copy with the host converted
     * to its IDNA ASCII form and the other non-ASCII bytes percent-encoded; the scheme is the ASCII prefix matched
     * here and cannot change.
     */
    private static function isWebUrl(string $url): bool
    {
        if (preg_match('~^(https?://)([^/?#]*)(.*)$~is', $url, $parts) !== 1) {
            return false;
        }
        [, $scheme, $authority, $rest] = $parts;
        $at = strrpos($authority, '@');
        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostPort = $at === false ? $authority : substr($authority, $at + 1);
        if (preg_match('/^(.*?)(:[0-9]*)?$/s', $hostPort, $hostParts) !== 1) {
            return false;
        }
        $host = $hostParts[1];
        $port = $hostParts[2] ?? '';
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            // UTS #46 maps some characters to ASCII punctuation (fullwidth "/" or "@"); only a plain name may result.
            if ($host === false || preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
                return false;
            }
        }
        $ascii = $scheme . self::encodeNonAscii($userInfo) . $host . $port . self::encodeNonAscii($rest);

        return filter_var($ascii, FILTER_VALIDATE_URL) !== false && (string) parse_url($ascii, PHP_URL_HOST) !== '';
    }

    private static function encodeNonAscii(string $value): string
    {
        return (string) preg_replace_callback('/[^\x00-\x7F]/', static fn(array $byte): string => rawurlencode($byte[0]), $value);
    }
}
