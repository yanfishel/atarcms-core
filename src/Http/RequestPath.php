<?php

declare(strict_types=1);

namespace AtarCms\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The canonical decoded request path. Application::handle() stores it as a request attribute;
 * everything that inspects the path (router, error pages, locale) must go through it.
 */
final readonly class RequestPath
{
    private function __construct(
        public string $raw,
        public string $decoded,
        public bool $valid,
        public string $prefix = '',
    ) {}

    public static function fromRaw(string $raw): self
    {
        $decoded = rawurldecode($raw);

        return new self($raw, $decoded, mb_check_encoding($decoded, 'UTF-8') && !str_contains($decoded, "\0"));
    }

    public static function of(ServerRequestInterface $request): self
    {
        $path = $request->getAttribute(self::class);

        return $path instanceof self ? $path : self::fromRaw($request->getUri()->getPath());
    }

    public function isUnder(string $prefix): bool
    {
        $prefix = rtrim($prefix, '/');

        return $prefix === '' || $this->decoded === $prefix || str_starts_with($this->decoded, $prefix . '/');
    }

    /**
     * The path with a literal leading prefix (e.g. "/ru") removed. The prefix is kept so redirects can put it back.
     */
    public function withoutPrefix(string $prefix): self
    {
        if ($prefix === '' || ($this->raw !== $prefix && !str_starts_with($this->raw, $prefix . '/'))) {
            throw new InvalidArgumentException(sprintf('Path "%s" does not start with the segment "%s"', $this->raw, $prefix));
        }
        $rest = substr($this->raw, strlen($prefix));
        $stripped = self::fromRaw($rest === '' ? '/' : $rest);

        return new self($stripped->raw, $stripped->decoded, $stripped->valid, $this->prefix . $prefix);
    }
}
