<?php

declare(strict_types=1);

namespace AtarCms\Analytics;

use AtarCms\Kernel\Container;
use AtarCms\View\Renderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Theme helpers of the analytics counters and their cookie banner (Consent): consentHead() (the loader's <script> for
 * the <head>; '' without a counter or for a signed-in user) and consent() (the banner's data as a ConsentView; null
 * then). They read the settings: a theme's 5xx page should not call them.
 */
final class AnalyticsViewHelpers
{
    public static function register(Renderer $renderer, Container $container): void
    {
        $renderer->requestHelper('consentHead', static fn(ServerRequestInterface $request): string => $container->service(Consent::class)->head($request));
        $renderer->requestHelper('consent', static fn(ServerRequestInterface $request): ?ConsentView => $container->service(Consent::class)->view($request));
    }
}
