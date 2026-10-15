<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use AtarCms\Content\Field\VideoUrl;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;
use AtarCms\Settings\GoogleMap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Outermost middleware: every response, including error pages, gets the security headers.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const string DEFAULT_PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    /**
     * The two <style> elements of the rich-text editor's colour picker (the hex-color-picker custom element in
     * vendor/ckeditor5-48.5.2.js): the stylesheet its constructor puts into its shadow root (three CSS strings
     * joined) and the focus rule CKEditor's ColorPickerView adds. Their texts are constants of the build;
     * EditorAssetsTest recomputes both hashes from it, so an upgrade fails until they are updated here.
     */
    public const array STYLE_HASHES = [
        'sha256-I/BcSf79PEn5SD9ob8aupUkHyFwnKqY/qf8Co29iLMs=',
        'sha256-nLG3fWB3Qoa5O3UY9xL8RZOTgVvIneH1jRZKc3+2EC4=',
    ];

    /** @var list<string> */
    private readonly array $frameSources;

    /** @var list<string> */
    private readonly array $imageSources;

    /** @var list<string> */
    private readonly array $connectSources;

    private readonly string $permissionsPolicy;

    private readonly bool $production;

    public function __construct(Config $config)
    {
        // The video players of rich text (VideoUrl) and the site map (GoogleMap) are always allowed; the config only adds hosts, each listed once.
        $this->frameSources = array_values(array_unique([...VideoUrl::FRAME_SOURCES, GoogleMap::FRAME_SOURCE, ...self::sources($config, 'security.csp.frame_src')]));
        $this->imageSources = self::sources($config, 'security.csp.img_src');
        $this->connectSources = self::sources($config, 'security.csp.connect_src');
        $this->permissionsPolicy = $config->string('security.permissions_policy', self::DEFAULT_PERMISSIONS_POLICY);
        $this->production = $config->environment() === Config::PRODUCTION;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = CspNonce::generate();
        $sources = new CspSources();
        $response = $handler->handle($request->withAttribute(CspNonce::class, $nonce)->withAttribute(CspSources::class, $sources));

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce, $sources),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => $this->permissionsPolicy,
        ];
        if ($this->production) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(CspNonce $nonce, CspSources $added): string
    {
        return implode('; ', [
            "default-src 'self'",
            self::directive($added, ['script-src', "'self'", sprintf("'nonce-%s'", $nonce->value)]),
            // Stylesheets only from the site, and the two <style> elements of the editor's colour picker by hash.
            implode(' ', ["style-src 'self'", ...array_map(static fn(string $hash): string => "'" . $hash . "'", self::STYLE_HASHES)]),
            // Style attributes apply: the sanitized ones of rich text (StyleAttributeSanitizer) and the editor's own
            // (resize handles, the upload progress bar). They cannot run script; templates and admin scripts write none.
            "style-src-attr 'unsafe-inline'",
            // blob: lets the rich-text editor (CKEditor) preview a dropped image while it uploads; such URLs can only
            // be created by scripts the page already runs.
            self::directive($added, ['img-src', "'self'", 'data:', 'blob:', ...$this->imageSources]),
            "font-src 'self'",
            self::directive($added, ['connect-src', "'self'", ...$this->connectSources]),
            self::directive($added, ['frame-src', "'self'", ...$this->frameSources]),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            self::directive($added, ['frame-ancestors', "'self'"]),
        ]);
    }

    /**
     * A directive with the sources this response allowed (CspSources: the analytics counters' after consentHead()),
     * each source once.
     *
     * @param list<string> $directive the directive's name and its sources
     */
    private static function directive(CspSources $added, array $directive): string
    {
        return implode(' ', array_values(array_unique([...$directive, ...$added->for($directive[0])])));
    }

    /**
     * @return list<string>
     */
    private static function sources(Config $config, string $key): array
    {
        $sources = [];
        foreach ($config->array($key) as $source) {
            if (!is_string($source) || preg_match('~^[A-Za-z0-9*:/._-]+$~', $source) !== 1) {
                throw new ConfigException(sprintf(
                    'Config "%s" must list CSP sources such as https://www.google.com, got %s',
                    $key,
                    is_string($source) ? '"' . $source . '"' : get_debug_type($source),
                ));
            }
            $sources[] = $source;
        }

        return $sources;
    }
}
