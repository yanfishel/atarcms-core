<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Kernel\Config;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * doctor --http: the site as visitors reach it. It answers, serves the admin's and the theme's assets, never serves
 * the files outside public/ (the classic misconfiguration: the document root at the site root), uses https with HSTS
 * in production, and its PHP (through the one-time WebProbe) is 8.4 with the extensions and limits, runs as the owner
 * of storage/ and can write it.
 */
final class WebChecks implements Check
{
    /** Paths that answer 404 on a correctly served site; a 200 means the web server serves the site's own files. */
    public const array EXPOSED = [
        '/.env',
        '/composer.json',
        '/composer.lock',
        '/storage/app.key',
        '/storage/logs/',
        '/vendor/autoload.php',
        '/config/app.php',
        '/public/index.php',
        '/bin/atar',
        '/.git/config',
    ];

    /** @var (callable)|null */
    private $handler;

    /**
     * @param callable|null $handler a Guzzle handler (tests answer for the web server)
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler;
    }

    public function run(DoctorContext $context): array
    {
        if (!$context->options->http) {
            return [CheckResult::skip('web', 'skipped: run doctor --http to check what the web server serves')];
        }
        $config = $context->config();
        if ($config === null) {
            return [$context->skipWithoutBoot('web')];
        }
        $url = $config->appUrl();
        $client = $this->client($context, $url);
        $via = $context->options->httpVia;
        // Where requests go: the site's URL, or (without curl to keep the site's host for TLS) the other address.
        $base = $via !== null && $this->handler === null && !defined('CURLOPT_CONNECT_TO') ? rtrim($via, '/') : $url;

        $home = $this->get($client, $base, '/');
        if (!$home instanceof ResponseInterface) {
            return [
                CheckResult::fail('web.home', sprintf('%s: cannot connect: %s', $url, $home), 'check that the web server runs, the domain points at it and the certificate is valid; inside Docker use --http-via=http://nginx (or https://web with --http-ca)'),
                ...array_map(static fn(string $id): CheckResult => CheckResult::skip($id, 'skipped: the site does not answer (see web.home)'), ['web.assets', 'web.exposure', 'web.https', 'web.probe']),
            ];
        }
        $status = $home->getStatusCode();
        $results = [$status >= 200 && $status < 400
            ? CheckResult::ok('web.home', sprintf('%s answers %d', $url, $status))
            : CheckResult::fail('web.home', sprintf('%s answers %d', $url, $status), 'look at storage/logs/ and the web server\'s error log')];
        $results[] = $this->assets($context, $config, $client, $base);
        $results[] = $this->exposure($client, $base, $config->string('app.theme', 'default'));
        $results[] = $this->https($config, $url, $home);

        return [...$results, ...$this->probe($context, $config, $client, $base)];
    }

    private function assets(DoctorContext $context, Config $config, Client $client, string $url): CheckResult
    {
        $paths = ['/admin-assets/admin.css', '/favicon.ico', '/robots.txt'];
        $stylesheet = $this->stylesheet($context, $config);
        if ($stylesheet !== null) {
            $paths[] = '/theme-assets/' . $stylesheet;
        }
        $broken = [];
        foreach ($paths as $path) {
            $response = $this->get($client, $url, $path);
            $status = $response instanceof ResponseInterface ? $response->getStatusCode() : 0;
            if ($status < 200 || $status >= 400) {
                $broken[] = sprintf('%s (%s)', $path, $status === 0 ? 'no answer' : $status);
            }
        }

        return $broken === []
            ? CheckResult::ok('web.assets', 'the admin and theme assets, the icon and robots.txt are served')
            : CheckResult::fail('web.assets', 'Not served: ' . implode(', ', $broken), 'compare the web server config with deploy/ (nginx, Apache, Caddy); after a theme switch re-render the nginx config with the new ATAR_THEME and reload');
    }

    private function exposure(Client $client, string $url, string $theme): CheckResult
    {
        $exposed = [];
        foreach ([...self::EXPOSED, '/themes/' . rawurlencode($theme) . '/theme.php'] as $path) {
            $response = $this->get($client, $url, $path);
            if ($response instanceof ResponseInterface && $response->getStatusCode() === 200) {
                $exposed[] = $path;
            }
        }

        return $exposed === []
            ? CheckResult::ok('web.exposure', 'nothing outside public/ is served (.env, storage/, vendor/, config/ answer 404)')
            : CheckResult::fail('web.exposure', 'The web server serves files outside public/: ' . implode(', ', $exposed), 'point the document root at the site\'s public/ directory (or, on shared hosting, use deploy/apache/root.htaccess); change the passwords in .env if it was reachable');
    }

    private function https(Config $config, string $url, ResponseInterface $home): CheckResult
    {
        if ($config->environment() !== Config::PRODUCTION) {
            return CheckResult::ok('web.https', 'not production: https is not required');
        }
        if (!str_starts_with($url, 'https://')) {
            return CheckResult::fail('web.https', 'production without https', 'set APP_URL=https://… and put HTTPS in front of the site');
        }

        return $home->hasHeader('Strict-Transport-Security')
            ? CheckResult::ok('web.https', 'https with a valid certificate and HSTS')
            : CheckResult::warn('web.https', 'https works, but the home page has no Strict-Transport-Security header', 'make sure the site\'s own response reaches visitors (a proxy in front must not drop headers)');
    }

    /**
     * @return list<CheckResult>
     */
    private function probe(DoctorContext $context, Config $config, Client $client, string $url): array
    {
        // The token file (and storage/tmp, when missing) must belong to the site's user: one written by root or by
        // another user is the very problem the doctor looks for.
        $uid = $context->platform->effectiveUid();
        $owner = $context->platform->fileOwner($context->paths->storage());
        if ($uid !== null && $owner !== null && ($uid === 0 || ($owner !== 0 && $uid !== $owner))) {
            $ownerName = $owner === 0 ? 'the site user' : ($context->platform->userName($owner) ?? 'uid ' . $owner);

            return [CheckResult::skip('web.probe', sprintf('skipped: the probe writes into storage/, so run doctor --http as %s, the owner of storage/ (Docker: -u www-data)', $ownerName))];
        }
        try {
            $token = WebProbe::issue($context->paths, time());
        } catch (Throwable $e) {
            return [CheckResult::fail('web.probe', 'Cannot write the probe token: ' . $e->getMessage(), 'run bin/atar as the owner of storage/')];
        }
        try {
            $response = $this->get($client, $url, '/_atar/doctor/' . $token);
        } finally {
            @unlink($context->paths->storage(WebProbe::FILE));
        }
        $facts = null;
        if ($response instanceof ResponseInterface && $response->getStatusCode() === 200) {
            try {
                $facts = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $facts = null;
            }
        }
        if (!is_array($facts)) {
            return [CheckResult::fail(
                'web.probe',
                'The web server\'s PHP did not answer the probe: it serves another directory or another copy of the site (then the web.* results above describe that one), or cannot read storage/tmp/',
                'the document root must be this site\'s public/ (disable the web server\'s default site: rm /etc/nginx/sites-enabled/default), and PHP-FPM must run as the owner of storage/',
            )];
        }
        /** @var array<string, mixed> $facts */
        $results = [CheckResult::ok('web.probe', sprintf('the web server runs PHP %s (%s)', is_string($facts['php'] ?? null) ? $facts['php'] : '?', is_string($facts['sapi'] ?? null) ? $facts['sapi'] : '?'))];
        $web = $context->withPlatform(new FactsPlatform($facts));
        foreach ((new PhpChecks(web: true))->run($web) as $result) {
            if ($result->id === 'php.url_fopen' || $result->status === Status::Skip) {
                continue;
            }
            $results[] = $result->status === Status::Ok
                ? CheckResult::ok('web.' . $result->id, 'web server: ' . $result->message)
                : ($result->status === Status::Warn
                    ? CheckResult::warn('web.' . $result->id, 'web server: ' . $result->message, $result->fix)
                    : CheckResult::fail('web.' . $result->id, 'web server: ' . $result->message, $result->fix));
        }
        $opcache = (new FactsPlatform($facts))->ini('opcache.enable') === '1';
        $results[] = ($config->environment() === Config::PRODUCTION && !$opcache)
            ? CheckResult::warn('web.opcache', 'OPcache is off in the web server\'s PHP: every request compiles the code again', 'opcache.enable = 1 (docker/php/production.ini has the sizing)')
            : CheckResult::ok('web.opcache', 'OPcache: ' . ($opcache ? 'on' : 'off (not production)'));
        $results[] = $this->user($context, $facts);
        $results[] = $this->storage($facts);
        if (($facts['session'] ?? true) === false) {
            $results[] = CheckResult::fail('web.session', 'The web server\'s PHP cannot write its sessions (session.save_path): nobody can sign in', 'make session.save_path writable for the PHP-FPM user');
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function user(DoctorContext $context, array $facts): CheckResult
    {
        $uid = is_int($facts['uid'] ?? null) ? $facts['uid'] : null;
        $user = is_string($facts['user'] ?? null) ? $facts['user'] : ($uid === null ? '?' : 'uid ' . $uid);
        $owner = $context->platform->fileOwner($context->paths->storage());
        if ($uid === null || $owner === null) {
            return CheckResult::skip('web.user', 'skipped: user ids are not available');
        }
        if ($owner === 0) {
            return CheckResult::warn('web.user', sprintf('The web server\'s PHP runs as %s; storage/ belongs to root (normal on Docker Desktop only)', $user), sprintf('Linux: chown -R %s: storage', $user));
        }
        if ($uid !== $owner) {
            $ownerName = $context->platform->userName($owner) ?? 'uid ' . $owner;

            return CheckResult::fail(
                'web.user',
                sprintf('The web server\'s PHP runs as %s, but storage/ belongs to %s: one user must run PHP-FPM and bin/atar', $user, $ownerName),
                sprintf('chown -R %s: storage and run bin/atar as %s (or run the PHP-FPM pool as %s: deploy/php-fpm/atarcms.conf)', $user, $user, $ownerName),
            );
        }

        return CheckResult::ok('web.user', sprintf('the web server\'s PHP runs as %s, the owner of storage/', $user));
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function storage(array $facts): CheckResult
    {
        $blocked = [];
        foreach (is_array($facts['storage'] ?? null) ? $facts['storage'] : [] as $directory => $writable) {
            if ($writable !== true) {
                $blocked[] = (string) $directory;
            }
        }
        if (($facts['app_key'] ?? null) === 'unreadable') {
            $blocked[] = 'storage/app.key (unreadable)';
        }

        return $blocked === []
            ? CheckResult::ok('web.storage', 'the web server\'s PHP can write storage/')
            : CheckResult::fail('web.storage', 'The web server\'s PHP cannot write: ' . implode(', ', $blocked), 'chown -R <PHP-FPM user>: storage');
    }

    private function stylesheet(DoctorContext $context, Config $config): ?string
    {
        $assets = $context->paths->theme($config->string('app.theme', 'default')) . '/assets';
        if (is_file($assets . '/css/site.css')) {
            return 'css/site.css';
        }
        $files = array_merge(glob($assets . '/*.css') ?: [], glob($assets . '/*/*.css') ?: []);
        sort($files);

        return $files === [] ? null : substr($files[0], strlen($assets) + 1);
    }

    private function client(DoctorContext $context, string $url): Client
    {
        $options = [
            RequestOptions::TIMEOUT => 10,
            RequestOptions::CONNECT_TIMEOUT => 5,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::VERIFY => $context->options->httpCa ?? true,
            RequestOptions::HEADERS => ['User-Agent' => 'AtarCMS doctor'],
        ];
        $via = $context->options->httpVia;
        if ($via !== null && $this->handler === null) {
            // Connect to another address but keep the site's host for TLS (SNI, certificate) and the Host header.
            $site = parse_url($url);
            $target = parse_url($via);
            if (is_array($site) && is_array($target) && isset($site['host'], $target['host'])) {
                $sitePort = $site['port'] ?? (($site['scheme'] ?? 'https') === 'https' ? 443 : 80);
                $targetPort = $target['port'] ?? (($target['scheme'] ?? 'https') === 'https' ? 443 : 80);
                if (defined('CURLOPT_CONNECT_TO')) {
                    $options['curl'] = [CURLOPT_CONNECT_TO => [sprintf('%s:%d:%s:%d', $site['host'], $sitePort, $target['host'], $targetPort)]];
                } else {
                    // Without curl only plain http can be redirected: request the other address with the site's Host.
                    $options[RequestOptions::HEADERS]['Host'] = $site['host'] . (isset($site['port']) ? ':' . $site['port'] : '');
                }
            }
        }
        if ($this->handler !== null) {
            $options['handler'] = HandlerStack::create($this->handler);
        }

        return new Client($options);
    }

    private function get(Client $client, string $url, string $path): ResponseInterface|string
    {
        try {
            return $client->request('GET', $url . $path);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
