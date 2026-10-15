<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Admin\AdminModule;
use AtarCms\Admin\LanguageMode;
use AtarCms\Auth\AuthModule;
use AtarCms\Auth\Http\AuthenticationMiddleware;
use AtarCms\Cli\Console;
use AtarCms\Content\ContentModule;
use AtarCms\Http\Error\ErrorMiddleware;
use AtarCms\Http\Error\PlainErrorPage;
use AtarCms\Http\MiddlewarePipeline;
use AtarCms\Http\RequestMemo;
use AtarCms\Http\RequestPath;
use AtarCms\Http\ResponseEmitter;
use AtarCms\Http\Routing\RouteDispatcher;
use AtarCms\Http\Routing\RouteMatcher;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\SearchVisibilityMiddleware;
use AtarCms\Http\Security\CsrfMiddleware;
use AtarCms\Http\Security\SecurityHeadersMiddleware;
use AtarCms\Http\Session\SessionMiddleware;
use AtarCms\I18n\LocaleMiddleware;
use AtarCms\Kernel\ErrorHandling\PhpErrorHandler;
use AtarCms\Media\MediaModule;
use AtarCms\Messages\MessagesModule;
use AtarCms\Search\SearchModule;
use AtarCms\Settings\SettingsModule;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class Application
{
    /**
     * Global middleware, outermost first. SecurityHeaders and Locale wrap ErrorMiddleware so error pages
     * get the headers and render in the visitor's language. SearchVisibility marks the public responses of a site hidden
     * from search engines. RouteMatcher matches before SessionMiddleware, which skips the session for stateless routes.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array MIDDLEWARE = [
        SecurityHeadersMiddleware::class,
        LocaleMiddleware::class,
        ErrorMiddleware::class,
        SearchVisibilityMiddleware::class,
        RouteMatcher::class,
        SessionMiddleware::class,
        CsrfMiddleware::class,
        AuthenticationMiddleware::class,
    ];

    private function __construct(private readonly Container $container) {}

    /**
     * The entry point of public/index.php and bin/atar.
     */
    public static function fromSite(string $basePath): self
    {
        return self::boot(new Paths($basePath));
    }

    /**
     * Loads .env and config/*.php, then runs every module's services() and routes() hooks. Every environment boots
     * the same way; production relies on OPcache.
     *
     * @param array<string, mixed> $overrides dotted config keys set after loading (theme:check boots another theme)
     */
    public static function boot(Paths $paths, array $overrides = []): self
    {
        $env = Env::load($paths->path('.env'));
        $config = Config::fromDirectory($paths->config(), $env);
        if ($overrides !== []) {
            $config = $config->with($overrides);
        }
        // Read once here, so an invalid app.url, app.timezone or locales.admin stops the web and bin/atar at boot, not
        // on the first request needing it.
        $config->appUrl();
        $siteTime = SiteTime::fromConfig($config);
        $languageMode = LanguageMode::fromConfig($config);

        $container = new Container();
        $container->set(Paths::class, $paths);
        $container->set(Env::class, $env);
        $container->set(Config::class, $config);
        $container->set(SiteTime::class, $siteTime);
        $container->set(LanguageMode::class, $languageMode);

        $modules = [new CoreModule(), new AuthModule(), new MediaModule(), new SettingsModule(), new SearchModule(), new MessagesModule(), new AdminModule()];
        foreach ($config->array('app.modules') as $class) {
            if (!is_string($class) || !is_subclass_of($class, Module::class)) {
                throw new ConfigException(sprintf(
                    'app.modules entries must be %s subclasses, got %s',
                    Module::class,
                    is_string($class) ? $class : get_debug_type($class),
                ));
            }
            $modules[] = new $class();
        }
        // Last: collection routes may include a "/{slug}" catch-all that must not shadow module routes.
        $modules[] = new ContentModule();
        $container->set(ModuleList::class, new ModuleList($modules));

        foreach ($modules as $module) {
            $module->services($container);
        }
        $router = $container->service(Router::class);
        foreach ($modules as $module) {
            $module->routes($router);
        }

        return new self($container);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $pipeline = $this->pipeline();
        } catch (Throwable $e) {
            error_log('AtarCMS request failed: ' . $e);

            return self::plainServerErrorResponse();
        }

        return $pipeline->handle(
            $request->withAttribute(RequestPath::class, RequestPath::of($request))->withAttribute(RequestMemo::class, new RequestMemo()),
        );
    }

    public function run(): void
    {
        $factory = $this->container->service(Psr17Factory::class);
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        $withBody = $request->getMethod() !== 'HEAD';

        try {
            $this->container->service(PhpErrorHandler::class)->register(renderFatalPage: true);

            ob_start();
            $response = $this->handle($request);
            $output = ob_get_clean();
            if (is_string($output) && $output !== '') {
                $this->logStrayOutput($output);
            }

            $this->container->service(ResponseEmitter::class)->emit($response, $withBody);
        } catch (Throwable $e) {
            error_log('AtarCMS request failed: ' . $e);
            (new ResponseEmitter())->emit(self::plainServerErrorResponse(), $withBody);
        }
    }

    public function console(): Console
    {
        return new Console($this->container, $this->container->service(ModuleList::class)->commands());
    }

    public function registerErrorHandler(): void
    {
        $this->container->service(PhpErrorHandler::class)->register();
    }

    private function pipeline(): MiddlewarePipeline
    {
        $middleware = array_map(
            fn(string $class): MiddlewareInterface => $this->container->service($class),
            self::MIDDLEWARE,
        );

        return new MiddlewarePipeline($middleware, $this->container->service(RouteDispatcher::class));
    }

    private function logStrayOutput(string $output): void
    {
        $message = sprintf('Unexpected output before the response was emitted (%d bytes)', strlen($output));

        try {
            $this->container->service(LoggerInterface::class)->warning($message);
        } catch (Throwable) {
            error_log('AtarCMS: ' . $message);
        }
    }

    private static function plainServerErrorResponse(): ResponseInterface
    {
        return new Response(500, ['Content-Type' => 'text/html; charset=utf-8'], PlainErrorPage::render(500));
    }
}
