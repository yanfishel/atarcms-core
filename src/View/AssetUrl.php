<?php

declare(strict_types=1);

namespace AtarCms\View;

use InvalidArgumentException;

final class AssetUrl
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly string $assetsDirectory,
        private readonly string $urlPrefix = '/theme-assets',
    ) {}

    /**
     * Whether $path names a file of the assets (a theme's optional parts, such as a language's flag).
     */
    public function exists(string $path): bool
    {
        $path = ltrim($path, '/');

        return $path !== '' && !str_contains($path, '..') && !str_contains($path, '\\')
            && is_file(rtrim($this->assetsDirectory, '/') . '/' . $path);
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new InvalidArgumentException(sprintf('Invalid asset path "%s"', $path));
        }
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $url = rtrim($this->urlPrefix, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
        $file = rtrim($this->assetsDirectory, '/') . '/' . $path;
        if (is_file($file)) {
            $hash = hash_file('xxh128', $file);
            if ($hash !== false) {
                $url .= '?v=' . substr($hash, 0, 12);
            }
        }

        return $this->cache[$path] = $url;
    }
}
