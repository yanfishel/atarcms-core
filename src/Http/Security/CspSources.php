<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The sources one response adds to its Content Security Policy: SecurityHeadersMiddleware puts an empty one on the
 * request (a request attribute, like CspNonce) and merges what the handler allowed into that response's policy. Only
 * the helper that prints a third-party script adds its hosts (consentHead(): the analytics counters), so the policy of
 * every other response stays as it is.
 */
final class CspSources
{
    /** The directives a response may widen. */
    public const array DIRECTIVES = ['script-src', 'img-src', 'connect-src', 'frame-src', 'frame-ancestors'];

    /** @var array<string, list<string>> directive => sources, each once, in the order allowed */
    private array $sources = [];

    public static function of(ServerRequestInterface $request): ?self
    {
        $sources = $request->getAttribute(self::class);

        return $sources instanceof self ? $sources : null;
    }

    /**
     * Adds hosts (https://…, wss://…, a "*." prefix allowed) to $directive; keywords and anything else are refused.
     *
     * @throws InvalidArgumentException
     */
    public function allow(string $directive, string ...$sources): void
    {
        if (!in_array($directive, self::DIRECTIVES, true)) {
            throw new InvalidArgumentException(sprintf('The directive "%s" cannot be widened per response', $directive));
        }
        foreach ($sources as $source) {
            if (preg_match('~^(?:https|wss)://(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)+$~D', $source) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a host source such as https://mc.yandex.ru', $source));
            }
        }
        foreach ($sources as $source) {
            if (!in_array($source, $this->sources[$directive] ?? [], true)) {
                $this->sources[$directive][] = $source;
            }
        }
    }

    /**
     * Forgets every added source: ErrorMiddleware starts an error page from the plain policy (its own render may add
     * the hosts again).
     */
    public function reset(): void
    {
        $this->sources = [];
    }

    public function any(): bool
    {
        return $this->sources !== [];
    }

    /**
     * @return list<string>
     */
    public function for(string $directive): array
    {
        return $this->sources[$directive] ?? [];
    }
}
