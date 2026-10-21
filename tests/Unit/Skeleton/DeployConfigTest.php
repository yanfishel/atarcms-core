<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Skeleton;

use AtarCms\Media\Http\MediaFileController;
use AtarCms\Tests\Support\Regex;
use AtarCms\View\Http\AssetFileController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web server configs shipped in skeleton/deploy behave like the PHP fallbacks they replace: the same headers on
 * uploads, image variants and assets, PDFs downloaded, hidden files refused, nothing but public/index.php run as PHP.
 * (They are linted and compared request by request in the install dry runs; this pins the shared strings.)
 */
final class DeployConfigTest extends TestCase
{
    private const string SKELETON = __DIR__ . '/../../../skeleton';

    /**
     * @return iterable<string, array{string}>
     */
    public static function serverConfigs(): iterable
    {
        yield 'nginx' => ['deploy/nginx/atarcms.conf.template'];
        yield 'Caddy' => ['deploy/caddy/Caddyfile'];
        yield 'Apache' => ['deploy/apache/atarcms.conf'];
    }

    #[DataProvider('serverConfigs')]
    public function testMediaAndAssetsGetTheHeadersOfThePhpFallback(string $file): void
    {
        $config = $this->read($file);

        self::assertStringContainsString(MediaFileController::CACHE, $config);
        self::assertStringContainsString(MediaFileController::SANDBOX, $config);
        self::assertStringContainsString(AssetFileController::CACHE, $config);
        self::assertStringContainsString('nosniff', $config);
        self::assertMatchesRegularExpression('/Content-Disposition"?\s+"?attachment/', $config, 'PDFs are downloads');
        foreach (['/theme-assets', '/admin-assets', '/uploads', '/media'] as $prefix) {
            self::assertStringContainsString($prefix, $config);
        }
        self::assertStringContainsString('vendor/yanfishel/atarcms-core/resources/admin/assets', $config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pdfRules(): iterable
    {
        yield 'nginx' => ['deploy/nginx/atarcms.conf.template', '/location ~\* \\\\\.pdf\$ \{[^}]*add_header Content-Disposition "attachment" always;/'];
        yield 'Caddy' => ['deploy/caddy/Caddyfile', '/@pdf path \*\.pdf\s+header @pdf Content-Disposition "attachment"/'];
        yield 'Apache' => ['deploy/apache/atarcms.conf', '/<FilesMatch "\\\\\.pdf\$">\s*Header always set Content-Disposition "attachment"\s*<\/FilesMatch>/'];
    }

    /**
     * Only PDFs are downloads: videos play inline from /uploads (MediaFileController spares them too), and every
     * server answers byte ranges by itself, as the PHP fallback does.
     */
    #[DataProvider('pdfRules')]
    public function testOnlyPdfsAreDownloadsSoVideosPlayInline(string $file, string $rule): void
    {
        $config = $this->read($file);

        self::assertMatchesRegularExpression($rule, $config);
        self::assertSame(1, substr_count($config, 'Content-Disposition'), 'no other disposition rule (a blanket one would turn videos into downloads)');
        self::assertStringContainsString('videos play inline', $config);
    }

    public function testNginxRunsOnlyTheFrontController(): void
    {
        $config = $this->read('deploy/nginx/atarcms.conf.template');

        self::assertStringContainsString('location = /index.php', $config);
        self::assertMatchesRegularExpression('/location ~ \\\\\.php\$ \{\s*return 404;/', $config);
        self::assertStringContainsString('fastcgi_pass ${PHP_FASTCGI};', $config);
        self::assertStringContainsString('server_name ${SERVER_NAME};', $config);
        self::assertStringContainsString('./deploy/nginx/atarcms.conf.template:/etc/nginx/templates/default.conf.template', $this->read('compose.yaml'));
    }

    public function testNginxNamesTheCharsetOfStylesAndScripts(): void
    {
        $config = $this->read('deploy/nginx/atarcms.conf.template');

        self::assertStringContainsString('charset utf-8;', $config, 'as AssetFileController: text/css; charset=utf-8');
        self::assertStringContainsString('charset_types text/css text/javascript application/javascript;', $config);
    }

    public function testCaddyRunsOnlyTheFrontController(): void
    {
        $config = $this->read('deploy/caddy/Caddyfile');

        self::assertStringContainsString('{$SITE_ADDRESS}', $config);
        self::assertStringContainsString('php_fastcgi {$PHP_FASTCGI', $config);
        self::assertMatchesRegularExpression('/@php\s+\{\s*path \*\.php\s+not path \/index\.php\s*\}/', $config);
    }

    public function testApacheRunsOnlyTheFrontControllerAndHtaccessRoutesToIt(): void
    {
        $vhost = $this->read('deploy/apache/atarcms.conf');
        self::assertStringContainsString('DocumentRoot ${ATAR_SITE_ROOT}/public', $vhost);
        self::assertStringContainsString('<Files "index.php">', $vhost);
        self::assertStringNotContainsString('FilesMatch \.php', $vhost);

        $htaccess = $this->read('public/.htaccess');
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('Options -Indexes', $htaccess);

        $root = $this->read('deploy/apache/root.htaccess');
        self::assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L]', $root);
        self::assertStringContainsString('<IfModule !mod_rewrite.c>', $root);
        self::assertStringContainsString('Require all denied', $root);
    }

    public function testThePhpFpmPoolRunsAsTheSiteUser(): void
    {
        $pool = $this->read('deploy/php-fpm/atarcms.conf');

        self::assertStringContainsString('user = ', $pool);
        self::assertStringContainsString('listen.owner = www-data', $pool);
        self::assertStringContainsString('php_admin_value[upload_max_filesize] = 100M', $pool);
        self::assertStringContainsString('php_admin_value[memory_limit] = 256M', $pool);
    }

    /**
     * A page with every analytics counter and advertising pixel brings the response headers to about 4.3 KB (Plan 41):
     * nginx's default FastCGI buffer (4 KB) answered 502. Apache (8 KB per header line) and Caddy take it.
     */
    public function testNginxTakesTheLongestContentSecurityPolicy(): void
    {
        $config = $this->read('deploy/nginx/atarcms.conf.template');

        self::assertStringContainsString("    fastcgi_buffer_size 16k;\n    fastcgi_buffers 8 16k;\n", str_replace("\r\n", "\n", $config));
    }

    public function testEveryLayerLetsA100MegabyteVideoThrough(): void
    {
        // config/media.php: max_video_size 100 MB; the form around it needs a little more.
        self::assertStringContainsString("'max_video_size' => 100 * 1024 * 1024", $this->read('config/media.php'));
        self::assertStringContainsString('client_max_body_size 101m;', $this->read('deploy/nginx/atarcms.conf.template'));
        self::assertStringContainsString('max_size 101MB', $this->read('deploy/caddy/Caddyfile'));
        self::assertStringContainsString('LimitRequestBody ' . 101 * 1024 * 1024, $this->read('deploy/apache/atarcms.conf'));
        self::assertStringContainsString('php_admin_value[post_max_size] = 101M', $this->read('deploy/php-fpm/atarcms.conf'));
        $ini = $this->read('docker/php/php.ini');
        self::assertStringContainsString('upload_max_filesize = 100M', $ini);
        self::assertStringContainsString('post_max_size = 101M', $ini);
    }

    /** Text responses worth compressing; images, video and woff2 are compressed already. */
    private const array COMPRESSED_TYPES = [
        'text/css', 'application/javascript', 'text/javascript', 'application/json', 'application/manifest+json',
        'image/svg+xml', 'application/xml', 'text/xml', 'application/rss+xml', 'application/atom+xml', 'text/plain',
        'text/markdown',
    ];

    /** Already compressed media: compressing them again only costs CPU (and would get in the way of byte ranges). */
    private const array UNCOMPRESSED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'video/', 'font/woff2'];

    public function testNginxCompressesTextResponsesButNotTheAdmin(): void
    {
        $config = $this->read('deploy/nginx/atarcms.conf.template');

        foreach (['gzip on;', 'gzip_vary on;', 'gzip_comp_level 5;', 'gzip_min_length 1024;', 'gzip_proxied any;'] as $directive) {
            self::assertStringContainsString($directive, $config);
        }
        $types = Regex::match('/^\s*gzip_types ([^;]+);/m', $config);
        $listed = preg_split('/\s+/', trim($types[1]));
        self::assertEqualsCanonicalizing(self::COMPRESSED_TYPES, $listed, 'text/html is always compressed');
        // BREACH: the admin's pages carry the session's CSRF token next to reflected input.
        self::assertStringContainsString(implode("\n", [
            'location ~ ^/admin(/|$) {',
            '        gzip off;',
            '        fastcgi_pass ${PHP_FASTCGI};',
            '        include fastcgi_params;',
            '        fastcgi_param SCRIPT_FILENAME $document_root/index.php;',
            '        fastcgi_param SCRIPT_NAME /index.php;',
            '        fastcgi_param DOCUMENT_ROOT $document_root;',
            '    }',
        ]), $config);
        self::assertGreaterThan(strpos($config, 'location ~ /\. {'), strpos($config, 'location ~ ^/admin(/|$)'), 'hidden files and PHP files of /admin still answer 404 first');
    }

    public function testApacheCompressesTextResponsesButNotTheAdmin(): void
    {
        $config = $this->read('deploy/apache/atarcms.conf');

        $block = Regex::match('~<IfModule mod_deflate\.c>(.*?)</IfModule>~s', $config);
        $types = Regex::match('/AddOutputFilterByType DEFLATE ([^\n]+)/', $block[1]);
        self::assertEqualsCanonicalizing(['text/html', ...self::COMPRESSED_TYPES], preg_split('/\s+/', trim($types[1])));
        self::assertStringContainsString(implode("\n", [
            '        <If "%{THE_REQUEST} =~ m#^\S+ /admin(/|\?|\s)#">',
            '            SetEnv no-gzip 1',
            '        </If>',
        ]), $block[1], 'BREACH: the admin stays uncompressed (the request line survives the rewrite to index.php)');
        self::assertStringContainsString('a2enmod rewrite headers proxy_fcgi alias deflate', $config);
    }

    public function testCaddyCompressesTextResponsesButNotTheAdmin(): void
    {
        $config = $this->read('deploy/caddy/Caddyfile');

        self::assertMatchesRegularExpression('~@compressible \{\s*not path /admin /admin/\*\s*\}\s*encode @compressible zstd gzip~', $config);
    }

    /**
     * AVIF variants (ImageFormats) are served by the web server once generated: nginx releases and Apache hosts whose
     * mime.types lack .avif would send them as application/octet-stream, so both configs name the type. Caddy's own
     * table has it.
     */
    public function testAvifVariantsAreServedAsImageAvif(): void
    {
        $nginx = $this->read('deploy/nginx/atarcms.conf.template');
        $media = Regex::match('#location \^~ /media/ \{(.*?)\n    \}#s', $nginx);
        self::assertMatchesRegularExpression('#\n        types \{\s*image/jpeg\s+jpg;\s*image/png\s+png;\s*image/webp\s+webp;\s*image/gif\s+gif;\s*image/avif\s+avif;\s*\}#', $media[1], 'the whole map of the variants\' types: a types block replaces the inherited one');
        self::assertStringContainsString('try_files $uri /index.php$is_args$args;', $media[1], 'the first request still goes to PHP');

        $apache = $this->read('deploy/apache/atarcms.conf');
        $directory = Regex::match('~<Directory \$\{ATAR_SITE_ROOT\}/storage/cache/media>(.*?)</Directory>~s', $apache);
        self::assertStringContainsString('AddType image/avif .avif', $directory[1]);
        self::assertStringContainsString('FallbackResource /index.php', $directory[1]);
    }

    #[DataProvider('serverConfigs')]
    public function testCompressedMediaIsNeverCompressedAgain(string $file): void
    {
        // Naming a type (nginx "types", Apache AddType) is not compressing it.
        $config = (string) preg_replace(['~\n\s*types \{[^}]*\}~', '~\n\s*AddType [^\n]*~'], '', $this->read($file));

        foreach (self::UNCOMPRESSED_TYPES as $type) {
            self::assertStringNotContainsString($type, $config);
        }
    }

    private function read(string $file): string
    {
        $path = self::SKELETON . '/' . $file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
