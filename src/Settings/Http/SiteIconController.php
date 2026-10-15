<?php

declare(strict_types=1);

namespace AtarCms\Settings\Http;

use AtarCms\Http\Responses;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Config;
use AtarCms\Settings\AtarLogo;
use AtarCms\Settings\SiteIcon;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /manifest.webmanifest and /favicon.ico from the site icon of the settings, else from the AtarCMS logo in the admin
 * colour. Both answer differently after the icon, the colour or the name changes, so they are cached for an hour, not
 * like the immutable files they name.
 */
final class SiteIconController
{
    private const string CACHE = 'public, max-age=3600';

    public function __construct(
        private readonly SiteSettings $settings,
        private readonly SiteIcon $icon,
        private readonly Locales $locales,
        private readonly Config $config,
        private readonly Responses $responses,
        private readonly AtarLogo $logo,
    ) {}

    public function manifest(ServerRequestInterface $request): ResponseInterface
    {
        $locale = $this->locales->default();
        $settings = $this->settings->of($locale);
        $icon = $this->icon->of($settings);
        $manifest = [
            'name' => $this->settings->siteName($locale, $settings),
            'short_name' => $this->settings->shortName($locale, $settings),
            'start_url' => $this->config->appUrl() . '/',
            'display' => 'browser',
        ];
        if ($settings->themeColor !== null) {
            $manifest['theme_color'] = $settings->themeColor;
            $manifest['background_color'] = $settings->themeColor;
        }
        $manifest['icons'] = [
            ['src' => $icon === null ? $this->logo->png($settings->accent(), 192) : $this->icon->url($icon, 'icon_192'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $icon === null ? $this->logo->png($settings->accent(), 512) : $this->icon->url($icon, 'icon_512'), 'sizes' => '512x512', 'type' => 'image/png'],
        ];

        return $this->responses->json($manifest)
            ->withHeader('Content-Type', 'application/manifest+json')
            ->withHeader('Cache-Control', self::CACHE);
    }

    public function favicon(ServerRequestInterface $request): ResponseInterface
    {
        $settings = $this->settings->of($this->locales->default());
        $icon = $this->icon->of($settings);
        $url = $icon === null ? $this->logo->png($settings->accent(), 32) : $this->icon->url($icon, 'icon_32');

        return $this->responses->redirect($url)->withHeader('Cache-Control', self::CACHE);
    }
}
