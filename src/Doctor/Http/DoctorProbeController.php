<?php

declare(strict_types=1);

namespace AtarCms\Doctor\Http;

use AtarCms\Doctor\NativePlatform;
use AtarCms\Doctor\WebProbe;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Paths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /_atar/doctor/{token}: the web server's PHP describes itself to `bin/atar doctor --http`, once, for the token
 * the doctor wrote a moment ago (WebProbe). Any other request is the site's normal 404.
 */
final class DoctorProbeController
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Locales $locales,
        private readonly Clock $clock,
        private readonly Responses $responses,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute('token');
        if (!is_string($token) || !WebProbe::consume($this->paths, $token, $this->clock->now()->getTimestamp())) {
            throw new NotFound('No such page');
        }

        return $this->responses->json(WebProbe::facts(new NativePlatform(), $this->paths, $this->locales->all()))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
