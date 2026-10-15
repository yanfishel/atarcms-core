<?php

declare(strict_types=1);

namespace AtarCms\Media\Http;

use AtarCms\Http\Exception\NotFound;
use AtarCms\Media\ImageFailed;
use AtarCms\Media\ImageFormats;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\ImageVariant;
use AtarCms\Media\ImageVariants;
use AtarCms\Media\MediaPath;
use AtarCms\Media\MediaStorage;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The PHP side of /uploads/{path}, /uploads/share/{name} and /media/{variant}/{path}. In production nginx serves both directories itself
 * (with the same headers) and only a variant's first request reaches PHP, which generates and caches it; stacks
 * without the nginx rules (php -S, the tests) are served entirely from here. Files are immutable (random names; a
 * changed variant setting changes the "?v=" of its URLs), and every response is sandboxed: an opened file can run no
 * script with the site's origin. Originals answer single byte ranges (Accept-Ranges, 206, 416) like nginx: Safari plays a
 * video only from a server that does.
 */
final class MediaFileController
{
    public const string CACHE = 'public, max-age=31536000, immutable';

    public const string SANDBOX = "default-src 'none'; sandbox";

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly ImageVariants $variants,
        private readonly ImageProcessor $images,
        private readonly Psr17Factory $factory,
        private readonly LoggerInterface $logger,
        private readonly ImageFormats $formats = new ImageFormats(),
    ) {}

    public function original(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');
        if (!is_string($path) || !MediaPath::isValid($path)) {
            throw new NotFound('No such file');
        }
        $file = $this->storage->original($path);
        if (!is_file($file)) {
            throw new NotFound('No such file');
        }
        $response = $this->ranged($this->file($file, MediaPath::mime($path)), $file, $request->getHeaderLine('Range'))
            ->withHeader('Accept-Ranges', 'bytes');

        // Images and videos show on the page (a video needs ranges to play in Safari); PDFs are downloads.
        return MediaPath::isImage($path) || MediaPath::isVideo($path) ? $response : $response->withHeader('Content-Disposition', 'attachment');
    }

    /**
     * One byte range, as nginx answers it: "bytes=first-last", "bytes=first-" or "bytes=-suffix" gives 206 with
     * Content-Range (the last byte clamped to the file's end), a range starting past the end 416; a header that is
     * not one valid range (other units, several ranges, last before first) is ignored and the whole file sent.
     */
    private function ranged(ResponseInterface $response, string $file, string $range): ResponseInterface
    {
        if (preg_match('/^bytes=(?:(\d{1,18})-(\d{0,18})|-(\d{1,18}))$/D', trim($range), $match) !== 1) {
            return $response;
        }
        $size = (int) filesize($file);
        if (isset($match[3])) {
            $suffix = (int) $match[3];
            [$first, $last] = [max(0, $size - $suffix), $size - 1];
            $satisfiable = $suffix > 0 && $size > 0;
        } else {
            $first = (int) $match[1];
            $last = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
            if ($match[2] !== '' && (int) $match[2] < $first) {
                return $response;
            }
            $satisfiable = $first < $size;
        }
        if (!$satisfiable) {
            return $this->factory->createResponse(416)
                ->withHeader('Content-Range', 'bytes */' . $size)
                ->withHeader('Cache-Control', self::CACHE)
                ->withHeader('Content-Security-Policy', self::SANDBOX);
        }

        return $response->withStatus(206)
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $first, $last, $size))
            ->withHeader('Content-Length', (string) ($last - $first + 1))
            ->withBody(new FileRangeStream($file, $first, $last - $first + 1));
    }

    /**
     * A generated share image, /uploads/share/{entry id}-{fingerprint}.jpg (its name changes with its content).
     */
    public function share(ServerRequestInterface $request): ResponseInterface
    {
        $name = $request->getAttribute('name');
        if (!is_string($name) || !MediaPath::isShareName($name)) {
            throw new NotFound('No such file');
        }
        $file = $this->storage->share($name);
        if (!is_file($file)) {
            throw new NotFound('No such file');
        }

        return $this->file($file, 'image/jpeg');
    }

    public function variant(ServerRequestInterface $request): ResponseInterface
    {
        $name = $request->getAttribute('variant');
        $requested = $request->getAttribute('path');
        $variant = is_string($name) ? $this->variants->get($name) : null;
        $source = is_string($requested) ? MediaPath::variantSource($requested) : null;
        if ($variant === null || $source === null) {
            throw new NotFound('No such image variant');
        }
        [$path, $format] = $source;
        $mime = $format ?? MediaPath::mime($path);
        // The site icon's variants exist only as PNG, and only they are made PNG from another format.
        if (ImageVariants::isIcon($variant->name) ? $mime !== 'image/png' : $format === 'image/png') {
            throw new NotFound('No such image variant');
        }
        // AVIF twins exist only where GD writes AVIF and config/media.php does not turn it off (ImageFormats).
        if ($format === 'image/avif' && !$this->formats->avif()) {
            throw new NotFound('No such image variant');
        }
        $target = $this->storage->variant($variant->name, $requested);
        if (!is_file($target)) {
            $original = $this->storage->original($path);
            if (!is_file($original)) {
                throw new NotFound('No such image');
            }
            // Concurrent first requests may each generate the variant; the atomic write keeps the file whole.
            $this->generate($path, $original, $variant, $requested, $mime);
        }

        return $this->file($target, $mime);
    }

    private function generate(string $path, string $original, ImageVariant $variant, string $requested, string $mime): void
    {
        try {
            $bytes = $this->images->variant($original, $variant, $mime);
        } catch (ImageFailed $e) {
            $this->logger->warning('Image variant {variant} of {path} failed: {message}', ['variant' => $variant->name, 'path' => $path, 'message' => $e->getMessage()]);

            throw new NotFound('No such image');
        }
        if (!$this->storage->writeVariantOf($path, $variant->name, $requested, $bytes)) {
            throw new NotFound('No such image');
        }
    }

    private function file(string $file, string $mime): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($file))
            ->withHeader('Cache-Control', self::CACHE)
            ->withHeader('Content-Security-Policy', self::SANDBOX)
            ->withBody($this->factory->createStreamFromFile($file));
    }
}
