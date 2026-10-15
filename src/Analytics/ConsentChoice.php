<?php

declare(strict_types=1);

namespace AtarCms\Analytics;

/**
 * A visitor's stored choice per consent category (the atar_consent cookie, written by consent.js): "Statistics" (the
 * counters) and "Advertising" (the pixels), each granted (true), denied (false) or not decided yet (null; the banner
 * shows again while a configured category is undecided).
 *
 * The value is a dot-separated token per decided category, statistics first: "s" or "a" and "1" or "0" ("s1", "s1.a0",
 * "a1"). Plan 40's "1" and "0" were given on its statistics-only banner: they read as "s1" and "s0", so advertising is
 * asked anew. Anything else is no choice at all.
 */
final readonly class ConsentChoice
{
    private const string FORMAT = '/^(?:s([01])(?:\.a([01]))?|a([01]))$/D';

    public function __construct(
        public ?bool $statistics,
        public ?bool $advertising,
    ) {}

    public static function fromCookie(?string $value): ?self
    {
        if ($value === '1' || $value === '0') {
            return new self($value === '1', null);
        }
        if ($value === null || preg_match(self::FORMAT, $value, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $flag = static fn(?string $digit): ?bool => $digit === null ? null : $digit === '1';

        return new self($flag($match[1]), $flag($match[2] ?? $match[3]));
    }
}
