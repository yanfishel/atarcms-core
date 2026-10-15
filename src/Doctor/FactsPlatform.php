<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * The web server's PHP as its probe described it (WebProbe::facts()), so the PHP checks can judge it like the CLI's.
 * File questions are not part of the facts: they answer null (not checked).
 */
final class FactsPlatform implements Platform
{
    /**
     * @param array<string, mixed> $facts
     */
    public function __construct(private readonly array $facts) {}

    public function phpVersion(): string
    {
        return is_string($this->facts['php'] ?? null) ? $this->facts['php'] : '0.0.0';
    }

    public function extensionLoaded(string $name): bool
    {
        $extensions = $this->facts['extensions'] ?? null;

        return is_array($extensions) && ($extensions[$name] ?? false) === true;
    }

    public function ini(string $name): ?string
    {
        $ini = $this->facts['ini'] ?? null;
        $value = is_array($ini) ? ($ini[$name] ?? null) : null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function gdInfo(): array
    {
        $gd = $this->facts['gd'] ?? null;
        $info = [];
        if (is_array($gd)) {
            foreach ($gd as $key => $value) {
                $info[(string) $key] = $value;
            }
        }

        return $info;
    }

    public function januaryName(string $locale): ?string
    {
        $months = $this->facts['months'] ?? null;
        $name = is_array($months) ? ($months[$locale] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    public function effectiveUid(): ?int
    {
        return is_int($this->facts['uid'] ?? null) ? $this->facts['uid'] : null;
    }

    public function userName(int $uid): ?string
    {
        return $uid === $this->effectiveUid() && is_string($this->facts['user'] ?? null) ? $this->facts['user'] : null;
    }

    public function fileOwner(string $path): ?int
    {
        return null;
    }

    public function fileMode(string $path): ?int
    {
        return null;
    }

    public function isWritable(string $path): bool
    {
        return false;
    }
}
