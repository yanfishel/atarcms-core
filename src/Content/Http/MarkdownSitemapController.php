<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Llms\MarkdownSitemap;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /sitemap.md: the site's public content as a markdown sitemap for agents; a site hidden from search engines has none.
 */
final class MarkdownSitemapController
{
    public function __construct(
        private readonly MarkdownSitemap $sitemap,
        private readonly Responses $responses,
        private readonly SiteSettings $settings,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->settings->forRequest($request)->hideFromSearch) {
            throw new NotFound('The site is hidden from search engines');
        }

        return $this->responses->markdown($this->sitemap->render());
    }
}
