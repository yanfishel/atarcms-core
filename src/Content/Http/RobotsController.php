<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Http\Responses;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /robots.txt: everything disallowed while the site settings hide the site from search engines; else config
 * "content.robots" when set (verbatim), otherwise a default that hides /admin, names the sitemap and states the
 * site's Content Signals (contentsignals.org): search and AI answers may use the content, training may not.
 */
final class RobotsController
{
    public const string CONTENT_SIGNAL = 'search=yes, ai-input=yes, ai-train=no';

    public function __construct(
        private readonly Config $config,
        private readonly Responses $responses,
        private readonly SiteSettings $settings,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->settings->forRequest($request)->hideFromSearch) {
            return $this->responses->text("User-agent: *\nDisallow: /\n");
        }
        if ($this->config->has('content.robots')) {
            return $this->responses->text($this->config->string('content.robots'));
        }
        return $this->responses->text("User-agent: *\nContent-Signal: " . self::CONTENT_SIGNAL . "\nDisallow: /admin\n\nSitemap: " . $this->config->appUrl() . "/sitemap.xml\n");
    }
}
