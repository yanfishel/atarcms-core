<?php

declare(strict_types=1);

namespace AtarCms\Media;

use AtarCms\Cli\Command\MediaCleanupCommand;
use AtarCms\Cli\Command\MediaReferencesPruneCommand;
use AtarCms\Cli\Command\MediaVariantsClearCommand;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\Module;
use AtarCms\Kernel\Paths;
use AtarCms\Media\Http\MediaFileController;
use AtarCms\View\ThemeConfig;

/**
 * Media: uploads, the media library's storage, image variants and their public routes.
 */
final class MediaModule extends Module
{
    public function services(Container $container): void
    {
        $container->factory(MediaConfig::class, static fn(Container $c): MediaConfig => MediaConfig::fromConfig($c->service(Config::class)));
        $container->factory(MediaStorage::class, static fn(Container $c): MediaStorage => MediaStorage::fromPaths($c->service(Paths::class)));
        $container->factory(ImageFormats::class, static fn(Container $c): ImageFormats => ImageFormats::fromConfig($c->service(Config::class)));
        $container->factory(ImageVariants::class, static fn(Container $c): ImageVariants => ImageVariants::fromTheme($c->service(ThemeConfig::class)));
    }

    /**
     * Public files are stateless routes: no session is read or written, so an immutable response never carries a
     * Set-Cookie and serving a file costs no session or user lookup.
     */
    public function routes(Router $router): void
    {
        $router->get('/uploads/{path:' . MediaPath::PATTERN . '}', [MediaFileController::class, 'original'], 'media.original')
            ->middleware(RequireDefaultLocale::class)
            ->stateless();
        $router->get('/uploads/share/{name:' . MediaPath::SHARE_PATTERN . '}', [MediaFileController::class, 'share'], 'media.share')
            ->middleware(RequireDefaultLocale::class)
            ->stateless();
        $router->get('/media/{variant:[a-z][a-z0-9_]{0,31}}/{path:' . MediaPath::VARIANT_PATTERN . '}', [MediaFileController::class, 'variant'], 'media.variant')
            ->middleware(RequireDefaultLocale::class)
            ->stateless();
    }

    public function commands(): array
    {
        return [MediaVariantsClearCommand::class, MediaReferencesPruneCommand::class, MediaCleanupCommand::class];
    }
}
