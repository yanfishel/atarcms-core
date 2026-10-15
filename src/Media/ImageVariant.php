<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Kernel\ConfigException;

/**
 * A named image size from theme.php's "images", e.g. 'thumb' => ['width' => 320, 'height' => 320, 'fit' => 'crop'].
 * "contain" (the default) scales an image down to fit the box; "crop" covers the box and crops the centre. Neither
 * ever enlarges an image.
 */
final readonly class ImageVariant
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    public const int MAX_SIZE = 4096;

    public const string CONTAIN = 'contain';

    public const string CROP = 'crop';

    private const array KEYS = ['width', 'height', 'fit', 'quality'];

    public function __construct(
        public string $name,
        public int $width,
        public ?int $height = null,
        public string $fit = self::CONTAIN,
        public int $quality = MediaConfig::DEFAULT_QUALITY,
    ) {
        $fail = static fn(string $message): ConfigException => new ConfigException(sprintf('Image variant "%s" in theme.php: %s', $name, $message));
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid image variant name "%s" in theme.php; use lowercase letters, digits and "_", starting with a letter (at most 32 characters)',
                $name,
            ));
        }
        if ($width < 1 || $width > self::MAX_SIZE) {
            throw $fail(sprintf('"width" must be an integer between 1 and %d', self::MAX_SIZE));
        }
        if ($height !== null && ($height < 1 || $height > self::MAX_SIZE)) {
            throw $fail(sprintf('"height" must be an integer between 1 and %d', self::MAX_SIZE));
        }
        if ($fit !== self::CONTAIN && $fit !== self::CROP) {
            throw $fail('"fit" must be "contain" or "crop"');
        }
        if ($fit === self::CROP && $height === null) {
            throw $fail('"crop" needs a height');
        }
        if ($quality < 1 || $quality > 100) {
            throw $fail('"quality" must be an integer between 1 and 100');
        }
    }

    public static function fromArray(string $name, mixed $spec): self
    {
        if (!is_array($spec)) {
            throw new ConfigException(sprintf('Image variant "%s" in theme.php must be an array such as [\'width\' => 800]', $name));
        }
        foreach (array_keys($spec) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new ConfigException(sprintf('Image variant "%s" in theme.php: unknown key "%s"; known keys: %s', $name, $key, implode(', ', self::KEYS)));
            }
        }
        $width = $spec['width'] ?? null;
        $height = $spec['height'] ?? null;
        $fit = $spec['fit'] ?? self::CONTAIN;
        $quality = $spec['quality'] ?? MediaConfig::DEFAULT_QUALITY;
        $fail = static fn(string $message): ConfigException => new ConfigException(sprintf('Image variant "%s" in theme.php: %s', $name, $message));

        return new self(
            $name,
            is_int($width) ? $width : throw $fail(sprintf('"width" must be an integer between 1 and %d', self::MAX_SIZE)),
            $height === null || is_int($height) ? $height : throw $fail(sprintf('"height" must be an integer between 1 and %d', self::MAX_SIZE)),
            is_string($fit) ? $fit : throw $fail('"fit" must be "contain" or "crop"'),
            is_int($quality) ? $quality : throw $fail('"quality" must be an integer between 1 and 100'),
        );
    }

    /**
     * A short hash of the settings; variant URLs carry it as "?v=", so a changed size gets new URLs.
     */
    public function version(): string
    {
        return substr(hash('xxh128', sprintf('%d:%s:%s:%d', $this->width, $this->height ?? '', $this->fit, $this->quality)), 0, 8);
    }

    /**
     * The size of this variant of an image of $width × $height pixels (never larger than the image).
     *
     * @return array{int, int}
     */
    public function dimensions(int $width, int $height): array
    {
        if ($this->fit === self::CROP) {
            $boxHeight = (int) $this->height;
            if ($width * $boxHeight > $this->width * $height) {
                $croppedHeight = min($height, $boxHeight);

                return [max(1, (int) round($croppedHeight * $this->width / $boxHeight)), $croppedHeight];
            }
            $croppedWidth = min($width, $this->width);

            return [$croppedWidth, max(1, (int) round($croppedWidth * $boxHeight / $this->width))];
        }
        $scale = min(1.0, $this->width / $width, $this->height === null ? 1.0 : $this->height / $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
