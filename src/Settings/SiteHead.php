<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequestLocale;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The <head> tags of the site settings (the siteHead() theme helper): the icon in the browser tab and on a phone's
 * home screen, the web app manifest, the browser's toolbar colour and the search consoles' verification codes. Without
 * a site icon the icon is the AtarCMS logo in the site colour (Settings::accent(); an SVG for modern browsers, PNGs
 * for the others); the rest is printed only when set.
 */
final class SiteHead
{
    public function __construct(
        private readonly SiteSettings $settings,
        private readonly SiteIcon $icon,
        private readonly Router $router,
        private readonly AtarLogo $logo,
    ) {}

    public function tags(ServerRequestInterface $request): string
    {
        $settings = $this->settings->forRequest($request);
        $icon = $this->icon->of($settings);
        $tags = [];
        if ($icon !== null) {
            $tags[] = sprintf('<link rel="icon" type="image/png" sizes="32x32" href="%s">', self::escape($this->icon->url($icon, 'icon_32')));
            $tags[] = sprintf('<link rel="apple-touch-icon" href="%s">', self::escape($this->icon->url($icon, 'icon_180')));
        } else {
            $accent = $settings->accent();
            $tags[] = sprintf('<link rel="icon" type="image/svg+xml" href="%s">', self::escape($this->logo->svg($accent)));
            $tags[] = sprintf('<link rel="icon" type="image/png" sizes="32x32" href="%s">', self::escape($this->logo->png($accent, 32)));
            $tags[] = sprintf('<link rel="apple-touch-icon" href="%s">', self::escape($this->logo->png($accent, 180)));
        }
        $tags[] = sprintf('<link rel="manifest" href="%s">', self::escape($this->router->url('settings.manifest')));
        if ($settings->themeColor !== null) {
            $tags[] = sprintf('<meta name="theme-color" content="%s">', self::escape($settings->themeColor));
        }
        $name = $this->settings->shortName(RequestLocale::of($request)->code, $settings);
        $tags[] = sprintf('<meta name="apple-mobile-web-app-title" content="%s">', self::escape($name));
        if ($settings->yandexVerification !== null) {
            $tags[] = sprintf('<meta name="yandex-verification" content="%s">', self::escape($settings->yandexVerification));
        }
        if ($settings->googleVerification !== null) {
            $tags[] = sprintf('<meta name="google-site-verification" content="%s">', self::escape($settings->googleVerification));
        }

        return implode("\n", $tags);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
