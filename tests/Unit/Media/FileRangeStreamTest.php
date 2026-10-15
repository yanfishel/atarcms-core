<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Media;

use AtarCms\Http\ResponseEmitter;
use AtarCms\Media\Http\FileRangeStream;
use AtarCms\Tests\Support\TempDirectory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class FileRangeStreamTest extends TestCase
{
    use TempDirectory;

    public function testReadsOnlyItsRangeAndRewindsToItsStart(): void
    {
        $file = $this->tempDir() . '/file.bin';
        file_put_contents($file, '0123456789');
        $stream = new FileRangeStream($file, 3, 4);

        self::assertSame(4, $stream->getSize());
        self::assertSame('34', $stream->read(2));
        self::assertSame(2, $stream->tell());
        self::assertSame('56', $stream->read(100));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));
        $stream->rewind();
        self::assertSame('3456', $stream->getContents());
        self::assertSame('3456', (string) $stream);
        $stream->seek(-1, SEEK_END);
        self::assertSame('6', $stream->read(5));

        // The emitter rewinds a seekable body: it must start at the range, not at the file's first byte.
        ob_start();
        new ResponseEmitter()->emit(new Response(206, [], $stream));
        self::assertSame('3456', ob_get_clean());
        $stream->close();
        self::assertTrue($stream->eof());
    }
}
