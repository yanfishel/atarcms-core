<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Analytics\AnalyticsViewHelpers;
use AtarCms\Cli\Command\DoctorCommand;
use AtarCms\Cli\Command\MigrateCommand;
use AtarCms\Cli\Command\MigrateStatusCommand;
use AtarCms\Content\ContentViewHelpers;
use AtarCms\Database\Connection;
use AtarCms\Database\Migrator;
use AtarCms\Doctor\Http\DoctorProbeController;
use AtarCms\Doctor\WebProbe;
use AtarCms\Http\Error\DebugErrorRenderer;
use AtarCms\Http\Error\ErrorRenderer;
use AtarCms\Http\Error\ThemeErrorRenderer;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\NativeSessionStorage;
use AtarCms\Http\Session\SessionStorage;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Log\JsonFileLogger;
use AtarCms\Media\MediaViewHelpers;
use AtarCms\Messages\MessagesViewHelpers;
use AtarCms\Settings\SettingsViewHelpers;
use AtarCms\View\AssetUrl;
use AtarCms\View\Http\AssetFileController;
use AtarCms\View\Renderer;
use AtarCms\View\ThemeConfig;
use AtarCms\View\ThemeResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class CoreModule extends Module
{
    public function services(Container $container): void
    {
        $container->bind(Clock::class, SystemClock::class);

        $container->factory(SessionStorage::class, static fn(Container $c): SessionStorage => new NativeSessionStorage(
            $c->service(Config::class)->int('session.absolute_timeout', 43200),
        ));

        $container->factory(LoggerInterface::class, static fn(Container $c): LoggerInterface => new JsonFileLogger(
            $c->service(Paths::class)->storage('logs'),
            $c->service(Config::class)->string('app.log_level', LogLevel::INFO),
        ));

        $container->factory(Connection::class, static function (Container $c): Connection {
            $config = $c->service(Config::class);

            return Connection::fromConfig([
                'host' => $config->string('database.host'),
                'port' => $config->int('database.port', 3306),
                'database' => $config->string('database.database'),
                'username' => $config->string('database.username'),
                'password' => $config->string('database.password', ''),
            ]);
        });

        $container->factory(ThemeResolver::class, static fn(Container $c): ThemeResolver => new ThemeResolver([
            self::themeDirectory($c),
        ]));

        $container->factory(AssetUrl::class, static fn(Container $c): AssetUrl => new AssetUrl(
            self::themeDirectory($c) . '/assets',
            $c->service(Config::class)->string('app.assets_url', '/theme-assets'),
        ));

        $container->factory(ThemeConfig::class, static fn(Container $c): ThemeConfig => ThemeConfig::load(self::themeDirectory($c)));

        $container->factory(Renderer::class, static function (Container $c): Renderer {
            $renderer = new Renderer($c->service(ThemeResolver::class));
            CoreViewHelpers::register($renderer, $c);
            ContentViewHelpers::register($renderer, $c);
            MediaViewHelpers::register($renderer, $c);
            SettingsViewHelpers::register($renderer, $c);
            MessagesViewHelpers::register($renderer, $c);
            AnalyticsViewHelpers::register($renderer, $c);
            $renderer->helper(
                'asset',
                static fn(string $path): string => $c->service(AssetUrl::class)->url($path),
            );

            return $renderer;
        });

        $container->factory(ErrorRenderer::class, static fn(Container $c): ErrorRenderer => $c->service(Config::class)->bool('app.debug', false)
            ? new DebugErrorRenderer()
            : $c->service(ThemeErrorRenderer::class));

        $container->factory(Migrator::class, static fn(Container $c): Migrator => new Migrator(
            $c->service(Connection::class),
            [...$c->service(ModuleList::class)->migrationPaths(), $c->service(Paths::class)->migrations()],
        ));

        $container->factory(Locales::class, static fn(Container $c): Locales => Locales::fromConfig($c->service(Config::class)));

        $container->factory(AppKey::class, static fn(Container $c): AppKey => new AppKey($c->service(Paths::class)->storage('app.key')));

        $container->factory(Translator::class, static fn(Container $c): Translator => new Translator(
            $c->service(Locales::class),
            [dirname(__DIR__, 2) . '/resources/lang', self::themeDirectory($c) . '/lang'],
            $c->service(LoggerInterface::class),
            $c->service(Config::class)->bool('app.debug', false),
        ));

        $container->factory(LocalizedUrls::class, static fn(Container $c): LocalizedUrls => new LocalizedUrls(
            $c->service(Router::class),
            $c->service(Locales::class),
            $c->service(Config::class),
        ));
    }

    /**
     * The PHP fallback of the theme and admin assets at their default prefixes (app.assets_url and
     * app.admin_assets_url pointing elsewhere, e.g. a CDN, are served there); web servers with the deploy configs
     * answer these paths themselves.
     */
    public function routes(Router $router): void
    {
        $path = '/{path:' . AssetFileController::PATH_PATTERN . '}';
        $router->get('/theme-assets' . $path, [AssetFileController::class, 'theme'], 'assets.theme')->stateless();
        $router->get('/admin-assets' . $path, [AssetFileController::class, 'admin'], 'assets.admin')->stateless();
        // bin/atar doctor --http asks the web server's PHP about itself, once per token it wrote (WebProbe).
        $router->get('/_atar/doctor/{token:' . WebProbe::TOKEN_PATTERN . '}', [DoctorProbeController::class, 'show'], 'doctor.probe')->stateless();
    }

    public function migrationPaths(): array
    {
        return [dirname(__DIR__, 2) . '/resources/migrations'];
    }

    public function commands(): array
    {
        return [DoctorCommand::class, MigrateCommand::class, MigrateStatusCommand::class];
    }

    private static function themeDirectory(Container $c): string
    {
        return $c->service(Paths::class)->theme($c->service(Config::class)->string('app.theme'));
    }
}
