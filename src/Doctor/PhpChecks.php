<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Kernel\ConfigException;
use AtarCms\Media\MediaConfig;

/**
 * The PHP running bin/atar: version, extensions, ICU data for every site language, GD's formats (AVIF optional) and
 * the memory and upload limits the media settings need. The web server's PHP can differ (another php.ini, on shared
 * hosting even another version): `doctor --http` asks it too.
 */
final class PhpChecks implements Check
{
    /** @var array<string, string> required extension => its Debian/Ubuntu package */
    public const array EXTENSIONS = [
        'pdo_mysql' => 'php8.4-mysql',
        'intl' => 'php8.4-intl',
        'mbstring' => 'php8.4-mbstring',
        'fileinfo' => 'php8.4-common',
        'gd' => 'php8.4-gd',
        'exif' => 'php8.4-common',
        'dom' => 'php8.4-xml',
        'json' => 'php8.4-common',
        'ctype' => 'php8.4-common',
        'iconv' => 'php8.4-common',
    ];

    /** Bytes of memory per pixel decoding an image takes (config/media.php), plus room for the rest of the request. */
    public const int BYTES_PER_PIXEL = 5;

    public const int BASE_MEMORY = 64 * 1024 * 1024;

    public const int RECOMMENDED_MEMORY = 256 * 1024 * 1024;

    private const int DEFAULT_MAX_PIXELS = 25_000_000;

    private const int DEFAULT_MAX_SIZE = 10 * 1024 * 1024;

    /**
     * @param bool $web the PHP judged is the web server's (WebChecks): its upload limits are the ones uploads meet,
     *                  so they fail; the command line's only warn (Debian's CLI php.ini keeps 2M beside a 20M pool)
     */
    public function __construct(private readonly bool $web = false) {}

    public function run(DoctorContext $context): array
    {
        $platform = $context->platform;

        return [
            $this->version($platform),
            $this->extensions($platform),
            $this->openssl($context),
            $platform->ini('allow_url_fopen') === '1'
                ? CheckResult::ok('php.url_fopen', 'allow_url_fopen is on')
                : CheckResult::warn('php.url_fopen', 'allow_url_fopen is off: Google profile photos are not downloaded', 'set allow_url_fopen = On in php.ini'),
            ...$this->icu($context),
            $this->gd($platform),
            $this->avif($context),
            $this->memory($context),
            $this->uploads($context),
        ];
    }

    private function version(Platform $platform): CheckResult
    {
        $version = $platform->phpVersion();

        return version_compare($version, '8.4.0', '>=')
            ? CheckResult::ok('php.version', 'PHP ' . $version)
            : CheckResult::fail('php.version', sprintf('PHP %s is too old: AtarCMS needs PHP 8.4 or newer', $version), 'install PHP 8.4 (Debian 13: apt install php8.4-cli php8.4-fpm; Ubuntu: the ppa:ondrej/php packages)');
    }

    private function extensions(Platform $platform): CheckResult
    {
        $missing = array_values(array_filter(array_keys(self::EXTENSIONS), static fn(string $name): bool => !$platform->extensionLoaded($name)));
        if ($missing === []) {
            return CheckResult::ok('php.extensions', 'PHP extensions: ' . implode(', ', array_keys(self::EXTENSIONS)));
        }
        $packages = array_values(array_unique(array_map(static fn(string $name): string => self::EXTENSIONS[$name], $missing)));

        return CheckResult::fail(
            'php.extensions',
            'Missing PHP extensions: ' . implode(', ', $missing),
            sprintf('Debian/Ubuntu: apt install %s; Docker: rebuild the site\'s image (docker compose build php)', implode(' ', $packages)),
        );
    }

    private function openssl(DoctorContext $context): CheckResult
    {
        if ($context->platform->extensionLoaded('openssl')) {
            return CheckResult::ok('php.openssl', 'openssl is available (HTTPS requests, encrypted mail)');
        }
        $env = $context->env();
        $needed = $env !== null && (($env->get('MAIL_DSN') ?? '') !== '' || ($env->get('ATAR_GOOGLE_CLIENT_ID') ?? '') !== '');

        return $needed
            ? CheckResult::fail('php.openssl', 'The openssl extension is missing: mail and Google sign-in need it', 'install or enable PHP\'s openssl extension')
            : CheckResult::warn('php.openssl', 'The openssl extension is missing: mail over TLS and Google sign-in will not work', 'install or enable PHP\'s openssl extension');
    }

    /**
     * @return list<CheckResult>
     */
    private function icu(DoctorContext $context): array
    {
        $platform = $context->platform;
        if (!$platform->extensionLoaded('intl')) {
            return [CheckResult::skip('intl.icu', 'skipped: the intl extension is missing')];
        }
        $locales = $context->config()?->get('locales.locales');
        $locales = is_array($locales) && $locales !== [] ? array_values(array_filter($locales, is_string(...))) : ['en'];
        $english = $platform->januaryName('en');
        $results = [];
        foreach ($locales as $locale) {
            $name = $platform->januaryName($locale);
            $language = strtolower((string) preg_replace('/[-_].*$/', '', $locale));
            if ($name === null || $name === '' || $name === 'M01' || ($language !== 'en' && $name === $english)) {
                $results[] = CheckResult::fail(
                    'intl.icu',
                    sprintf('ICU has no data for "%s": dates and plural forms print in English or as codes', $locale),
                    'install the full ICU data (Alpine/Docker: apk add icu-data-full and rebuild the image; Debian/Ubuntu packages carry it: check that php8.4-intl is installed)',
                );
            } else {
                $results[] = CheckResult::ok('intl.icu', sprintf('ICU data for "%s" (January: %s)', $locale, $name));
            }
        }

        return $results;
    }

    private function gd(Platform $platform): CheckResult
    {
        $info = $platform->gdInfo();
        if ($info === []) {
            return CheckResult::skip('gd.formats', 'skipped: the gd extension is missing');
        }
        $missing = [];
        foreach (['JPEG Support' => 'JPEG', 'PNG Support' => 'PNG', 'WebP Support' => 'WebP', 'GIF Read Support' => 'GIF'] as $key => $format) {
            if (($info[$key] ?? false) !== true) {
                $missing[] = $format;
            }
        }
        if ($missing !== []) {
            return CheckResult::fail('gd.formats', 'GD cannot read or write ' . implode(', ', $missing) . ': uploads of these images fail', 'install a GD built with JPEG, PNG and WebP (Debian: php8.4-gd; Docker: docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype)');
        }
        if (($info['FreeType Support'] ?? false) !== true) {
            return CheckResult::warn('gd.formats', 'GD has no FreeType: no share images are drawn (the main photo is shared instead)', 'install a GD built with FreeType (Debian: php8.4-gd has it; Docker: --with-freetype)');
        }

        return CheckResult::ok('gd.formats', 'GD: JPEG, PNG, WebP, GIF, FreeType');
    }

    /**
     * AVIF is optional (ImageFormats): without it img() offers WebP and the original format only, so it only warns.
     */
    private function avif(DoctorContext $context): CheckResult
    {
        $info = $context->platform->gdInfo();
        if ($info === []) {
            return CheckResult::skip('gd.avif', 'skipped: the gd extension is missing');
        }
        if ($context->config()?->get('media.avif') === false) {
            return CheckResult::ok('gd.avif', 'AVIF images are turned off (media.avif in config/media.php)');
        }
        if (($info['AVIF Support'] ?? false) === true && ($info['AVIF Encodes'] ?? true) === false) {
            return CheckResult::warn(
                'gd.avif',
                'GD is built with AVIF but cannot encode it (libavif without an encoder): pictures are served as WebP and in their original format only',
                'install a libavif with an encoder (aom, rav1e or SVT-AV1), or set \'avif\' => false in config/media.php',
            );
        }
        if (($info['AVIF Support'] ?? false) !== true) {
            return CheckResult::warn(
                'gd.avif',
                'GD has no AVIF: pictures are served as WebP and in their original format only',
                'install a GD built with AVIF (Debian/Ubuntu: a php8.4-gd whose libgd has AVIF; Docker: apk add libavif libavif-dev and docker-php-ext-configure gd --with-avif), or set \'avif\' => false in config/media.php',
            );
        }

        return CheckResult::ok('gd.avif', 'GD: AVIF (img() adds AVIF sources)');
    }

    private function memory(DoctorContext $context): CheckResult
    {
        $limit = IniSize::bytes($context->platform->ini('memory_limit'));
        $pixels = $context->config()?->int('media.max_pixels', self::DEFAULT_MAX_PIXELS) ?? self::DEFAULT_MAX_PIXELS;
        $needed = $pixels * self::BYTES_PER_PIXEL + self::BASE_MEMORY;
        $shown = IniSize::format($limit);
        if ($limit === -1 || ($limit >= $needed && $limit >= self::RECOMMENDED_MEMORY)) {
            return CheckResult::ok('php.memory', 'memory_limit ' . $shown);
        }
        $fix = 'set memory_limit = 256M in the php.ini of PHP-FPM and the CLI (or lower media.max_pixels in config/media.php)';
        if ($limit >= $needed) {
            return CheckResult::warn('php.memory', sprintf('memory_limit %s is enough for %s MP images, but 256M is recommended', $shown, round($pixels / 1_000_000, 1)), $fix);
        }

        return CheckResult::fail('php.memory', sprintf('memory_limit %s is too low to decode images of %s MP (media.max_pixels); they need about %s', $shown, round($pixels / 1_000_000, 1), IniSize::format($needed)), $fix);
    }

    private function uploads(DoctorContext $context): CheckResult
    {
        $maxSize = $context->config()?->int('media.max_size', self::DEFAULT_MAX_SIZE) ?? self::DEFAULT_MAX_SIZE;
        $videoSize = $this->videoLimit($context);
        $upload = IniSize::bytes($context->platform->ini('upload_max_filesize'));
        $post = IniSize::bytes($context->platform->ini('post_max_size'));
        $wanted = IniSize::format($maxSize);
        $problem = null;
        // A gap below the video limit only costs large videos, so it warns even on the web server's PHP.
        $videosOnly = false;
        if ($upload !== -1 && $upload < $maxSize) {
            $problem = [sprintf('upload_max_filesize %s is below the media limit %s: larger uploads fail', IniSize::format($upload), $wanted), sprintf('set upload_max_filesize = %s and post_max_size above it in php.ini (or lower media.max_size)', $wanted)];
        } elseif ($post !== -1 && $post !== 0 && $upload !== -1 && $post <= $upload) {
            $problem = [sprintf('post_max_size %s is not above upload_max_filesize %s: a full-size upload with its form fails', IniSize::format($post), IniSize::format($upload)), 'set post_max_size a little above upload_max_filesize in php.ini (e.g. 21M for 20M)'];
        } elseif ($videoSize !== null && $upload !== -1 && $upload < $videoSize) {
            $videosOnly = true;
            $video = IniSize::format($videoSize);
            $problem = [
                sprintf('upload_max_filesize %s is below the video limit %s: larger videos fail', IniSize::format($upload), $video),
                sprintf('set upload_max_filesize = %s and post_max_size above it in php.ini, and the web server\'s body limit (nginx client_max_body_size, Caddy request_body max_size, Apache LimitRequestBody) above it too (or lower media.max_video_size)', $video),
            ];
        }
        if ($problem !== null) {
            return $this->web && !$videosOnly
                ? CheckResult::fail('php.uploads', $problem[0], $problem[1])
                : CheckResult::warn('php.uploads', ($this->web ? '' : 'command line: ') . $problem[0], ($this->web ? '' : 'uploads go through the web server\'s PHP, which may have other limits: check it with bin/atar doctor --http; ') . $problem[1]);
        }

        return CheckResult::ok('php.uploads', sprintf('upload_max_filesize %s, post_max_size %s (media limit %s%s)', IniSize::format($upload), IniSize::format($post), $wanted, $videoSize === null ? '' : ', videos ' . IniSize::format($videoSize)));
    }

    /**
     * The video limit in bytes (media.max_video_size) when the site allows videos; null when it does not, or when
     * config/media.php is broken (the boot check reports that).
     */
    private function videoLimit(DoctorContext $context): ?int
    {
        try {
            $config = $context->config();
            $media = $config === null ? new MediaConfig() : MediaConfig::fromConfig($config);
        } catch (ConfigException) {
            return null;
        }

        return $media->videoTypes() === [] ? null : $media->maxVideoSize;
    }
}
