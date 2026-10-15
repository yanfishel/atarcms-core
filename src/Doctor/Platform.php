<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

/**
 * Everything the doctor learns from the machine it runs on, behind one interface so the checks can be tested with a
 * fake. NativePlatform asks PHP itself.
 */
interface Platform
{
    public function phpVersion(): string;

    public function extensionLoaded(string $name): bool;

    /** The php.ini value, null when PHP does not know the setting. */
    public function ini(string $name): ?string;

    /**
     * @return array<string, mixed> gd_info(), empty without GD
     */
    public function gdInfo(): array;

    /** January's stand-alone month name in the locale ("январь"), null without intl. ICU without data says "M01". */
    public function januaryName(string $locale): ?string;

    /** The effective user id of this process, null where PHP has no POSIX functions (Windows). */
    public function effectiveUid(): ?int;

    public function userName(int $uid): ?string;

    /** The owner's user id, null without POSIX or when the file does not exist. */
    public function fileOwner(string $path): ?int;

    /** The permission bits (0o644), null without POSIX or when the file does not exist. */
    public function fileMode(string $path): ?int;

    public function isWritable(string $path): bool;
}
