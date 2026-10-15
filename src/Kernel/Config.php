<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use Closure;

final class Config
{
    public const string PRODUCTION = 'production';

    public const string LOCAL = 'local';

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private readonly array $items = []) {}

    public static function fromDirectory(string $directory, Env $env): self
    {
        $items = [];
        foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
            $value = (static fn(string $__file): mixed => require $__file)($file);
            if ($value instanceof Closure) {
                $value = $value($env);
            }
            if (!is_array($value)) {
                throw new ConfigException(sprintf(
                    'Config file %s must return an array or a closure returning an array',
                    basename($file),
                ));
            }
            $items[basename($file, '.php')] = $value;
        }
        ksort($items);

        return new self($items);
    }

    /**
     * A copy with the given dotted keys set ("app.theme" => "other"); Application::boot() applies its overrides so.
     *
     * @param array<string, mixed> $values
     */
    public function with(array $values): self
    {
        $items = $this->items;
        foreach ($values as $key => $value) {
            $items = self::put($items, explode('.', $key), $value);
        }

        return new self($items);
    }

    /**
     * @param array<string, mixed> $items
     * @param list<string> $path
     *
     * @return array<string, mixed>
     */
    private static function put(array $items, array $path, mixed $value): array
    {
        $segment = array_shift($path);
        if ($segment === null) {
            return $items;
        }
        if ($path === []) {
            $items[$segment] = $value;

            return $items;
        }
        $child = $items[$segment] ?? [];
        /** @var array<string, mixed> $child */
        $child = is_array($child) ? $child : [];
        $items[$segment] = self::put($child, $path, $value);

        return $items;
    }

    /**
     * The application environment: app.env (config/app.php sets it from APP_ENV) trimmed and lower-cased; production
     * when it is missing or empty. Everything that depends on the environment reads it here.
     */
    public function environment(): string
    {
        $value = strtolower(trim($this->string('app.env', '')));

        return $value === '' ? self::PRODUCTION : $value;
    }

    /**
     * The public URL of the site root from app.url, without a trailing slash ("https://example.com"): the base of every
     * absolute URL (sitemap, feeds, hreflang, og:image, the Google redirect URI, login links). Application::boot()
     * reads it once, so a missing or invalid value stops the web and bin/atar alike.
     *
     * @throws ConfigException unless app.url is an absolute http(s) URL with a host and no userinfo, path, query or fragment
     */
    public function appUrl(): string
    {
        $url = $this->string('app.url', '');
        $parts = parse_url($url);
        $valid = is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && preg_match('~[\s?#\\\\]~', $url) !== 1;
        if (!$valid) {
            throw new ConfigException(sprintf(
                'app.url must be the absolute http(s) URL of the site root, like "https://example.com" (no path, query or fragment); got "%s"',
                self::withoutUserinfo($url),
            ));
        }

        return rtrim($url, '/');
    }

    private static function withoutUserinfo(string $url): string
    {
        return preg_replace('~^([a-z][a-z0-9+.-]*://)[^/?#]*@~i', '$1', $url) ?? '';
    }

    public function has(string $key): bool
    {
        return $this->lookup($key)[0];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->lookup($key);

        return $found ? $value : $default;
    }

    public function string(string $key, ?string $default = null): string
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            return $default ?? throw $this->missing($key);
        }
        if (!is_string($value)) {
            throw $this->typeError($key, 'string', $value);
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            return $default ?? throw $this->missing($key);
        }
        if (!is_int($value)) {
            throw $this->typeError($key, 'int', $value);
        }

        return $value;
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            return $default ?? throw $this->missing($key);
        }
        if (!is_bool($value)) {
            throw $this->typeError($key, 'bool', $value);
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        [$found, $value] = $this->lookup($key);
        if (!$found) {
            return [];
        }
        if (!is_array($value)) {
            throw $this->typeError($key, 'array', $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function lookup(string $key): array
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    private function missing(string $key): ConfigException
    {
        return new ConfigException(sprintf('Missing config "%s"', $key));
    }

    private function typeError(string $key, string $expected, mixed $value): ConfigException
    {
        return new ConfigException(sprintf('Config "%s" must be %s, got %s', $key, $expected, get_debug_type($value)));
    }
}
