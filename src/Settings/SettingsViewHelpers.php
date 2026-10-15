<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Container;
use AtarCms\Media\Media;
use AtarCms\Media\MediaRepository;
use AtarCms\View\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme helpers of the site settings, all in the request's language and from the memoised settings
 * (SiteSettings::forRequest()): siteHead() (the icon, manifest, toolbar colour and verification <head> tags, see
 * SiteHead), pageTitle($title) (the <title> by the title pattern; the site name alone without a title), siteName(),
 * siteLogo() (the logo's Media for img(), or null), socialLinks() and messengers() (the filled links as SocialLink, in
 * order; the theme draws their icons and labels), contactMap() (the site's Google map as a MapView, or null without one
 * or without config content.contact_page). siteSettings() (everything else) lives in ContentViewHelpers. They
 * read the database: a theme's 5xx page should not call them.
 */
final class SettingsViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->requestHelper('siteHead', static fn(ServerRequestInterface $request): string => $container->service(SiteHead::class)->tags($request));
        $renderer->requestHelper('pageTitle', static function (ServerRequestInterface $request, ?string $title = null) use ($container): string {
            $settings = $container->service(SiteSettings::class);

            return $settings->pageTitle($title, RequestLocale::of($request)->code, $settings->forRequest($request));
        });
        $renderer->requestHelper('siteName', static function (ServerRequestInterface $request) use ($container): string {
            $settings = $container->service(SiteSettings::class);

            return $settings->siteName(RequestLocale::of($request)->code, $settings->forRequest($request));
        });
        $renderer->requestHelper('siteLogo', static function (ServerRequestInterface $request) use ($container): ?Media {
            $id = $container->service(SiteSettings::class)->forRequest($request)->logoId;
            $logo = $id === null ? null : $container->service(MediaRepository::class)->find($id);

            return $logo !== null && $logo->isImage() ? $logo : null;
        });
        $renderer->requestHelper(
            'socialLinks',
            /** @return list<SocialLink> */
            static fn(ServerRequestInterface $request): array => $container->service(SiteSettings::class)->forRequest($request)->socialLinks,
        );
        $renderer->requestHelper(
            'messengers',
            /** @return list<SocialLink> */
            static fn(ServerRequestInterface $request): array => $container->service(SiteSettings::class)->forRequest($request)->messengers,
        );
        $renderer->requestHelper('contactMap', static function (ServerRequestInterface $request) use ($container): ?MapView {
            $map = $container->service(ContactPage::class)->collection === null ? null : $container->service(SiteSettings::class)->forRequest($request)->map;

            return $map === null ? null : MapView::of($map, RequestLocale::of($request)->code);
        });
    }
}
