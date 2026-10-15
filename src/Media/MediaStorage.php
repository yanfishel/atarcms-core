<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Kernel\Paths;
use AtarCms\Media\Cleanup\Removal;
use AtarCms\Media\Cleanup\StoredFile;
use AtarCms\Media\Cleanup\StoredFileKind;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * All file system access for media: originals below $uploads (served at /uploads/; the generated share images in its
 * share/ directory), variants below
 * $cache/{variant}/ (served at /media/{variant}/) and staged uploads below $tmp (never served). Every write goes to a
 * temporary dot file in the target directory first and is then renamed into place, so readers (nginx included) never
 * see half-written files. inventory() lists what is on disk for bin/atar media:cleanup.
 */
final class MediaStorage
{
    /** The temporary file of an atomic write (atomicPlace()) of an original, a variant or a share image. */
    private const string TEMPORARY = '~^\.(?:[0-9a-f]{32}\.(?:jpg|png|webp|gif|pdf|mp4|webm)(?:\.webp|\.png|\.avif)?|' . MediaPath::SHARE_PATTERN . ')\.[0-9a-f]{12}\.tmp$~D';

    /** A month directory of originals and variants: "2026/09". */
    private const string MONTH = '~^[0-9]{4}/(?:0[1-9]|1[0-2])$~D';

    private readonly string $uploads;

    private readonly string $cache;

    private readonly string $tmp;

    public function __construct(string $uploads, string $cache, string $tmp)
    {
        $this->uploads = rtrim($uploads, '/');
        $this->cache = rtrim($cache, '/');
        $this->tmp = rtrim($tmp, '/');
    }

    public static function fromPaths(Paths $paths): self
    {
        return new self($paths->storage('uploads'), $paths->storage('cache/media'), $paths->storage('tmp/uploads'));
    }

    public function original(string $path): string
    {
        if (!MediaPath::isValid($path)) {
            throw new InvalidArgumentException(sprintf('Invalid media path "%s"', $path));
        }

        return $this->uploads . '/' . $path;
    }

    /**
     * A generated share image (MediaPath::SHARE_PATTERN) below uploads/share/, served at /uploads/share/.
     */
    public function share(string $name): string
    {
        if (!MediaPath::isShareName($name)) {
            throw new InvalidArgumentException(sprintf('Invalid share image name "%s"', $name));
        }

        return $this->uploads . '/share/' . $name;
    }

    public function writeShare(string $name, string $contents): void
    {
        self::atomicWrite($this->share($name), $contents);
    }

    /**
     * Best effort, like delete().
     */
    public function deleteShare(string $name): void
    {
        self::removeBestEffort($this->share($name));
    }

    /**
     * @param string $requested an image path, optionally followed by ".webp", ".avif" or ".png" (see MediaPath::variantSource())
     */
    public function variant(string $variant, string $requested): string
    {
        if (preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1 || MediaPath::variantSource($requested) === null) {
            throw new InvalidArgumentException(sprintf('Invalid variant file "%s/%s"', $variant, $requested));
        }

        return $this->cache . '/' . $variant . '/' . $requested;
    }

    /**
     * A new random path in the month of $now (UTC), e.g. "2026/09/<32 hex>.jpg".
     */
    public function newPath(DateTimeImmutable $now, string $extension): string
    {
        $path = $now->setTimezone(new DateTimeZone('UTC'))->format('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (!MediaPath::isValid($path)) {
            throw new InvalidArgumentException(sprintf('Unsupported extension "%s"', $extension));
        }

        return $path;
    }

    /**
     * A new file name in the staging directory (which is created); the caller moves the upload there.
     */
    public function stagingFile(): string
    {
        self::ensureDirectory($this->tmp);

        return $this->tmp . '/' . bin2hex(random_bytes(16));
    }

    public function write(string $path, string $contents): void
    {
        self::atomicWrite($this->original($path), $contents);
    }

    /**
     * Copies a staged file (a PDF of up to media.max_size) as a stream, without reading it into memory.
     */
    public function copy(string $source, string $path): void
    {
        self::atomicPlace($this->original($path), static function (string $temporary) use ($source): void {
            $in = @fopen($source, 'rb');
            if ($in === false) {
                throw new RuntimeException(sprintf('Could not read "%s"', $source));
            }
            try {
                $out = @fopen($temporary, 'wb');
                if ($out === false) {
                    throw new RuntimeException(sprintf('Could not write "%s"', $temporary));
                }
                try {
                    if (stream_copy_to_stream($in, $out) === false) {
                        throw new RuntimeException(sprintf('Could not copy "%s"', $source));
                    }
                } finally {
                    fclose($out);
                }
            } finally {
                fclose($in);
            }
        });
    }

    public function writeVariant(string $variant, string $requested, string $contents): void
    {
        self::atomicWrite($this->variant($variant, $requested), $contents);
    }

    /**
     * Writes a generated variant of the original $path, then checks that the original still exists: a delete that
     * ran while the variant was being generated has already removed the variants, so this one is removed again.
     *
     * @return bool false when the original is gone (and the variant was removed)
     */
    public function writeVariantOf(string $path, string $variant, string $requested, string $contents): bool
    {
        $target = $this->variant($variant, $requested);
        self::atomicWrite($target, $contents);
        if (is_file($this->original($path))) {
            return true;
        }
        try {
            if (is_file($target)) {
                unlink($target);
            }
        } catch (\Throwable) {
            // An orphan variant is harmless compared to failing the request.
        }

        return false;
    }

    /**
     * Deletes the original and every cached variant of it (of any variant name, in every format).
     */
    public function delete(string $path): void
    {
        // The original goes first: a variant generated concurrently is renamed into place and then checks the original
        // (see writeVariantOf()), so one that appears after the glob below removes itself.
        self::removeBestEffort($this->original($path));
        foreach ([$path, $path . '.webp', $path . '.avif', $path . '.png'] as $name) {
            foreach (glob($this->cache . '/*/' . $name) ?: [] as $file) {
                self::removeBestEffort($file);
            }
        }
    }

    /**
     * Best effort: a file that cannot be removed (or vanished meanwhile) is left as an orphan and must not stop the
     * others, nor turn an already committed delete into an error.
     */
    private static function removeBestEffort(string $file): void
    {
        try {
            if (is_file($file)) {
                unlink($file);
            }
        } catch (\Throwable) {
            // See above.
        }
    }

    /**
     * Deletes cached variant files (of one variant, or all); they are generated again on the next request.
     *
     * @return int the number of deleted files
     */
    public function clearVariants(?string $variant = null): int
    {
        if ($variant !== null && preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid variant name "%s"', $variant));
        }
        $directory = $variant === null ? $this->cache : $this->cache . '/' . $variant;
        if (!is_dir($directory)) {
            return 0;
        }
        $count = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        // Listed before deleting: some mounted file systems (Docker Desktop's) skip entries of a directory that changes
        // while it is read.
        /** @var SplFileInfo $item */
        foreach (iterator_to_array($items, false) as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every media file below the three directories that has one of the shapes this class writes (originals, variants,
     * share images, staged uploads, the temporary files of atomic writes), sorted by StoredFile::$display. Anything
     * else (dot files such as .gitkeep, other names, directories) is left out; symbolic links are neither listed nor
     * followed. bin/atar media:cleanup.
     *
     * @return list<StoredFile>
     */
    public function inventory(): array
    {
        $found = [];
        foreach (self::listFiles($this->uploads) as $relative => $item) {
            $directory = dirname($relative);
            $name = basename($relative);
            if (MediaPath::isValid($relative)) {
                $found[] = self::stored(StoredFileKind::Original, $item, 'uploads/' . $relative, $relative);
            } elseif ($directory === 'share' && MediaPath::isShareName($name)) {
                $found[] = self::stored(StoredFileKind::Share, $item, 'uploads/' . $relative, $name);
            } elseif (preg_match(self::TEMPORARY, $name) === 1 && ($directory === 'share' || preg_match(self::MONTH, $directory) === 1)) {
                $found[] = self::stored(StoredFileKind::Temporary, $item, 'uploads/' . $relative, null);
            }
        }
        foreach (self::listFiles($this->cache) as $relative => $item) {
            [$variant, $requested] = explode('/', $relative, 2) + [1 => ''];
            if (preg_match(ImageVariant::NAME_PATTERN, $variant) !== 1) {
                continue;
            }
            $source = MediaPath::variantSource($requested);
            if ($source !== null) {
                $found[] = self::stored(StoredFileKind::Variant, $item, 'cache/media/' . $relative, $source[0]);
            } elseif (preg_match(self::TEMPORARY, basename($requested)) === 1 && preg_match(self::MONTH, dirname($requested)) === 1) {
                $found[] = self::stored(StoredFileKind::Temporary, $item, 'cache/media/' . $relative, null);
            }
        }
        foreach (self::listFiles($this->tmp) as $relative => $item) {
            if (preg_match('~^[0-9a-f]{32}$~D', $relative) === 1) {
                $found[] = self::stored(StoredFileKind::Staged, $item, 'tmp/uploads/' . $relative, null);
            }
        }
        usort($found, static fn(StoredFile $a, StoredFile $b): int => strcmp($a->display, $b->display));

        return $found;
    }

    /**
     * Removes a file inventory() found. A file that is gone already counts as removed. One modified since the
     * inventory is kept: a share image's name is deterministic, so an entry reverted to an earlier state writes (and
     * records) the very file being swept. A file that cannot be removed, was replaced by something else (a directory, a
     * link) or does not lie where its display name says (below one of the three directories) is a failure.
     */
    public function removeStored(StoredFile $file): Removal
    {
        if ($file->file !== $this->located($file->display)) {
            return Removal::Failed;
        }
        clearstatcache(true, $file->file);
        if (!file_exists($file->file) && !is_link($file->file)) {
            return Removal::Removed;
        }
        if (is_link($file->file) || !is_file($file->file)) {
            return Removal::Failed;
        }
        if (filemtime($file->file) !== $file->modified) {
            return Removal::Kept;
        }
        try {
            return unlink($file->file) ? Removal::Removed : Removal::Failed;
        } catch (\Throwable) {
            // A warning turned into an exception by PhpErrorHandler: reported by the caller.
            return Removal::Failed;
        }
    }

    /**
     * The absolute file a display name (StoredFile::$display) stands for; null when it names none of the three
     * directories or has empty, "." or ".." segments.
     */
    private function located(string $display): ?string
    {
        foreach (['uploads/' => $this->uploads, 'cache/media/' => $this->cache, 'tmp/uploads/' => $this->tmp] as $prefix => $root) {
            if (!str_starts_with($display, $prefix)) {
                continue;
            }
            $relative = substr($display, strlen($prefix));
            foreach (explode('/', $relative) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\')) {
                    return null;
                }
            }

            return $root . '/' . $relative;
        }

        return null;
    }

    /**
     * @return array<string, SplFileInfo> the regular files below $directory by their name relative to it; symbolic
     *                                    links are left out and never followed
     */
    private static function listFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        $files = [];
        // Listed before anything is deleted: some mounted file systems (Docker Desktop's) skip entries of a directory
        // that changes while it is read.
        /** @var SplFileInfo $item */
        foreach (iterator_to_array($items, false) as $item) {
            if (!$item->isLink() && $item->isFile()) {
                $files[substr($item->getPathname(), strlen($directory) + 1)] = $item;
            }
        }

        return $files;
    }

    private static function stored(StoredFileKind $kind, SplFileInfo $item, string $display, ?string $key): StoredFile
    {
        return new StoredFile($kind, $item->getPathname(), $display, $key, (int) $item->getMTime());
    }

    /**
     * Deletes every uploaded file (originals and generated share images) and every cached variant: bin/atar
     * demo:seed, after the media rows. Dot files such as .gitkeep stay.
     *
     * @return int the number of deleted files
     */
    public function purge(): int
    {
        $count = $this->clearVariants();
        if (!is_dir($this->uploads)) {
            return $count;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploads, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach (iterator_to_array($items, false) as $item) {
            if (str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
                $count++;
            }
        }

        return $count;
    }

    private static function atomicWrite(string $target, string $contents): void
    {
        self::atomicPlace($target, static function (string $temporary) use ($target, $contents): void {
            if (file_put_contents($temporary, $contents) !== strlen($contents)) {
                throw new RuntimeException(sprintf('Could not write "%s"', $target));
            }
        });
    }

    /**
     * $write fills a temporary dot file next to $target, which is then renamed into place.
     *
     * @param Closure(string): void $write
     */
    private static function atomicPlace(string $target, Closure $write): void
    {
        $directory = dirname($target);
        self::ensureDirectory($directory);
        $temporary = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $write($temporary);
            chmod($temporary, 0644);
            if (!rename($temporary, $target)) {
                throw new RuntimeException(sprintf('Could not move "%s" into place', $target));
            }
        } catch (\Throwable $e) {
            // Also runs when PhpErrorHandler turned a warning of the calls above into an exception.
            try {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            } catch (\Throwable) {
                // The original failure matters more than the leftover temporary file.
            }

            throw $e;
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        // Another request may create the directory at the same moment: only fail when it still does not exist.
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create "%s"', $directory));
        }
    }
}
