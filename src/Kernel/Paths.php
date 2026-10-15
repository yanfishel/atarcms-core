<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

final readonly class Paths
{
    public string $base;

    /** The storage directory: "{base}/storage" unless another one was given (tests use a temporary one). */
    public string $storageRoot;

    public function __construct(string $base, ?string $storage = null)
    {
        $this->base = self::normalize($base);
        $this->storageRoot = $storage === null ? $this->base . '/storage' : self::normalize($storage);
    }

    public function path(string $relative = ''): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $this->base : $this->base . '/' . $relative;
    }

    public function config(): string
    {
        return $this->path('config');
    }

    public function storage(string $relative = ''): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $this->storageRoot : $this->storageRoot . '/' . $relative;
    }

    public function theme(string $name): string
    {
        return $this->path('themes/' . $name);
    }

    public function migrations(): string
    {
        return $this->path('database/migrations');
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
