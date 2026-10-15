<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Doctor\Platform;

/**
 * A healthy PHP 8.4 on Linux with every extension, full ICU data and generous limits; tests change one fact at a
 * time through the public properties.
 */
final class FakePlatform implements Platform
{
    public string $version = '8.4.12';

    /** @var list<string> */
    public array $missingExtensions = [];

    /** @var array<string, string> */
    public array $ini = [
        'memory_limit' => '256M',
        'upload_max_filesize' => '100M',
        'post_max_size' => '101M',
        'allow_url_fopen' => '1',
    ];

    /** @var array<string, mixed> */
    public array $gd = [
        'JPEG Support' => true,
        'PNG Support' => true,
        'WebP Support' => true,
        'GIF Read Support' => true,
        'FreeType Support' => true,
        'AVIF Support' => true,
    ];

    /** @var array<string, string> January's stand-alone name by language; a language not listed has no ICU data */
    public array $months = ['en' => 'January', 'ru' => 'январь', 'he' => 'ינואר', 'de' => 'Januar'];

    public ?int $uid = 1000;

    /** @var array<int, string> */
    public array $users = [0 => 'root', 33 => 'www-data', 1000 => 'site'];

    /** @var array<string, int> owner by path; others belong to $defaultOwner */
    public array $owners = [];

    public int $defaultOwner = 1000;

    /** @var array<string, int> */
    public array $modes = [];

    /** @var list<string> */
    public array $readOnly = [];

    public function phpVersion(): string
    {
        return $this->version;
    }

    public function extensionLoaded(string $name): bool
    {
        return !in_array($name, $this->missingExtensions, true);
    }

    public function ini(string $name): ?string
    {
        return $this->ini[$name] ?? null;
    }

    public function gdInfo(): array
    {
        return $this->gd;
    }

    public function januaryName(string $locale): ?string
    {
        if (!$this->extensionLoaded('intl')) {
            return null;
        }
        $language = strtolower((string) preg_replace('/[-_].*$/', '', $locale));

        return $this->months[$language] ?? 'M01';
    }

    public function effectiveUid(): ?int
    {
        return $this->uid;
    }

    public function userName(int $uid): ?string
    {
        return $this->users[$uid] ?? null;
    }

    public function fileOwner(string $path): ?int
    {
        return $this->uid === null ? null : ($this->owners[$path] ?? $this->defaultOwner);
    }

    public function fileMode(string $path): ?int
    {
        return $this->uid === null ? null : ($this->modes[$path] ?? (is_dir($path) ? 0o755 : 0o600));
    }

    public function isWritable(string $path): bool
    {
        return !in_array($path, $this->readOnly, true) && is_writable($path);
    }
}
