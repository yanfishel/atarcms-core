<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use Closure;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body that never ends: every read returns a few bytes and calls $tick (a test advances its clock there),
 * as a server trickling one chunk at a time within every read timeout.
 */
final class TricklingStream implements StreamInterface
{
    /**
     * @param Closure(): void $tick
     */
    public function __construct(private readonly Closure $tick) {}

    public function __toString(): string
    {
        throw new RuntimeException('Never read a trickling stream whole');
    }

    public function close(): void {}

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        ($this->tick)();

        return 'xxxxxxxxxx';
    }

    public function getContents(): string
    {
        throw new RuntimeException('Never read a trickling stream whole');
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
