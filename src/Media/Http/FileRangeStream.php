<?php

declare(strict_types=1);

namespace AtarCms\Media\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only stream of one byte range of a file (the body of a 206 answer): position 0 is the range's first byte,
 * and reading stops after its last, so a large video is sent in chunks without being read into memory.
 */
final class FileRangeStream implements StreamInterface
{
    /** @var resource|null */
    private $handle;

    private int $position = 0;

    public function __construct(string $file, private readonly int $start, private readonly int $length)
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the file');
        }
        $this->handle = $handle;
        $this->seek(0);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function detach()
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->handle === null || $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default => $offset,
        };
        if ($target < 0 || $target > $this->length || fseek($this->open(), $this->start + $target) !== 0) {
            throw new RuntimeException('Cannot seek there');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is read-only');
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function read(int $length): string
    {
        $wanted = min($length, $this->length - $this->position);
        if ($wanted < 1) {
            return '';
        }
        $bytes = fread($this->open(), $wanted);
        if ($bytes === false) {
            throw new RuntimeException('Cannot read the file');
        }
        $this->position += strlen($bytes);
        if ($bytes === '') {
            // The file is shorter than it was: end the range rather than loop.
            $this->position = $this->length;
        }

        return $bytes;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * @return resource
     */
    private function open()
    {
        return $this->handle ?? throw new RuntimeException('The stream is closed');
    }
}
