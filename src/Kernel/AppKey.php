<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use RuntimeException;

/**
 * The site's secret: 32 random bytes kept as hex in storage/app.key, created on first use (readable only by the PHP
 * user). It signs the tokens of public forms (PublicFormToken) and keys the hashes of visitors' IP addresses, so no
 * plain address is stored. Deleting the file makes a new key: open forms then ask to be sent again.
 */
final class AppKey
{
    private ?string $key = null;

    public function __construct(private readonly string $file) {}

    /**
     * The 32 bytes of the key.
     *
     * @throws RuntimeException when the file cannot be created or holds something else than a key
     */
    public function value(): string
    {
        return $this->key ??= $this->load();
    }

    private function load(): string
    {
        if (!is_file($this->file)) {
            $this->create();
        }
        $hex = trim((string) @file_get_contents($this->file));
        if (preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new RuntimeException(sprintf('%s does not hold a key (64 hexadecimal characters); delete it to make a new one', $this->file));
        }

        return (string) hex2bin($hex);
    }

    /**
     * Writes the key under a temporary name and renames it into place, so a reader never sees half a key. Two first
     * requests at the same moment may each write one; the last rename wins and both read the file afterwards.
     */
    private function create(): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s for the site key', $directory));
        }
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        // Created empty and private first: the key is never readable by others, not even for a moment.
        $previous = umask(0o077);
        try {
            $handle = @fopen($temporary, 'x');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot create the site key file %s', $temporary));
        }
        chmod($temporary, 0o600);
        $written = fwrite($handle, bin2hex(random_bytes(32)));
        fclose($handle);
        if ($written !== 64) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Cannot write the site key to %s', $temporary));
        }
        if (!@rename($temporary, $this->file)) {
            @unlink($temporary);
            throw new RuntimeException(sprintf('Cannot move the site key to %s', $this->file));
        }
    }
}
