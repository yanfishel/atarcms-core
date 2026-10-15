<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use AtarCms\Analytics\Consent;
use AtarCms\Cli\Command\SettingsImportCommand;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PublicContent;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\Module;
use AtarCms\Settings\Http\SiteIconController;
use AtarCms\View\AssetUrl;
use LogicException;

/**
 * The site settings: SiteSettings with its name fallbacks (the theme catalog's "site.name", then app.name), the AtarCMS
 * logo files (the default site icon), the page of the site's map (ContactPage) and the site icon's public routes. A core module registered before ContentModule like every module, so its static routes
 * come before the content's variable ones.
 */
final class SettingsModule extends Module
{
    private ?Container $container = null;

    public function services(Container $container): void
    {
        // routes() only receives the router; the container is kept to check the contact page there.
        $this->container = $container;
        $container->factory(ContactPage::class, static fn(Container $c): ContactPage => ContactPage::fromConfig($c->service(Config::class), $c->service(Collections::class)));
        $container->factory(SiteSettings::class, static fn(Container $c): SiteSettings => new SiteSettings(
            $c->service(SettingsRepository::class),
            $c->service(Translator::class),
            $c->service(Config::class)->string('app.name', 'AtarCMS'),
        ));
        // The cookie banner's loader (consent.js) is an admin asset too, at the same URL prefix.
        $container->factory(Consent::class, static fn(Container $c): Consent => new Consent(
            $c->service(SiteSettings::class),
            $c->service(PublicContent::class),
            $c->service(EntryUrls::class),
            new AssetUrl(dirname(__DIR__, 2) . '/resources/admin/assets', $c->service(Config::class)->string('app.admin_assets_url', '/admin-assets')),
        ));
        // The logo files are admin assets: the same directory and URL prefix as AdminModule's AssetUrl.
        $container->factory(AtarLogo::class, static fn(Container $c): AtarLogo => new AtarLogo(new AssetUrl(
            dirname(__DIR__, 2) . '/resources/admin/assets',
            $c->service(Config::class)->string('app.admin_assets_url', '/admin-assets'),
        )));
    }

    /**
     * Stateless like the media files: no session is read or written.
     */
    public function routes(Router $router): void
    {
        // A mistake in content.contact_page stops the boot, like one in the collections.
        ($this->container ?? throw new LogicException('SettingsModule::services() must run before routes()'))->service(ContactPage::class);
        $router->get('/manifest.webmanifest', [SiteIconController::class, 'manifest'], 'settings.manifest')
            ->middleware(RequireDefaultLocale::class)
            ->stateless();
        $router->get('/favicon.ico', [SiteIconController::class, 'favicon'], 'settings.favicon')
            ->middleware(RequireDefaultLocale::class)
            ->stateless();
    }

    public function commands(): array
    {
        return [SettingsImportCommand::class];
    }
}
