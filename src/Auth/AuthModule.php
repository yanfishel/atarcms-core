<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Admin\AdminLocale;
use AtarCms\Auth\Avatar\AvatarDownloader;
use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Auth\Google\GoogleIdentityProvider;
use AtarCms\Auth\Google\LeagueGoogleIdentityProvider;
use AtarCms\Auth\Http\AvatarController;
use AtarCms\Auth\Http\DevLoginController;
use AtarCms\Auth\Http\GoogleLoginController;
use AtarCms\Auth\Http\LoginController;
use AtarCms\Auth\Http\LoginLinkController;
use AtarCms\Auth\Http\RequireAuthentication;
use AtarCms\Cli\Command\UserCreateCommand;
use AtarCms\Cli\Command\UserDisableCommand;
use AtarCms\Cli\Command\UserLoginLinkCommand;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\RequireDefaultLocale;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Container;
use AtarCms\Kernel\Module;
use AtarCms\Kernel\Paths;
use AtarCms\Media\ImageProcessor;
use AtarCms\Media\MediaConfig;
use Nyholm\Psr7\Factory\Psr17Factory;

final class AuthModule extends Module
{
    public function services(Container $container): void
    {
        $container->factory(Roles::class, static fn(Container $c): Roles => Roles::fromConfig($c->service(Config::class)));
        $container->factory(GoogleIdentityProvider::class, static function (Container $c): GoogleIdentityProvider {
            $config = $c->service(Config::class);

            return new LeagueGoogleIdentityProvider(
                $config->string('auth.google.client_id', ''),
                $config->string('auth.google.client_secret', ''),
                $config->appUrl() . $c->service(Router::class)->url('admin.login.google.callback'),
            );
        });
        $container->factory(Avatars::class, static fn(Container $c): Avatars => new Avatars(
            $c->service(Paths::class)->storage('avatars'),
            $c->service(MediaConfig::class),
            $c->service(ImageProcessor::class),
        ));
        // Profile photos come from Google's CDN: AvatarDownloader::client() has the timeouts, limits and redirect rules.
        $container->factory(AvatarDownloader::class, static fn(): AvatarDownloader => new AvatarDownloader(
            AvatarDownloader::client(),
            new Psr17Factory(),
        ));
    }

    public function routes(Router $router): void
    {
        $router->group('/admin', static function (Router $admin): void {
            // The login page speaks the guest's language in the linked mode (AdminLocale; the separate mode leaves it).
            $admin->get('/login', [LoginController::class, 'show'], 'admin.login')->middleware(AdminLocale::class);
            $admin->post('/login/dev', [DevLoginController::class, 'logIn'], 'admin.login.dev');
            $admin->post('/logout', [LoginController::class, 'logout'], 'admin.logout');
            $admin->get('/login/link/{token}', [LoginLinkController::class, 'consume'], 'admin.login.link');
            $admin->get('/login/google', [GoogleLoginController::class, 'redirect'], 'admin.login.google');
            $admin->get('/login/google/callback', [GoogleLoginController::class, 'callback'], 'admin.login.google.callback');
            $admin->get('/avatar/{id:[1-9][0-9]{0,9}}', [AvatarController::class, 'show'], 'admin.avatar')
                ->middleware(RequireAuthentication::class);
        }, RequireDefaultLocale::class);
    }

    public function commands(): array
    {
        return [UserCreateCommand::class, UserDisableCommand::class, UserLoginLinkCommand::class];
    }
}
