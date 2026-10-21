<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Llms\LlmsTxt;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /llms.txt: the site's content as a markdown map for LLMs; a site hidden from search engines has none.
 */
final class LlmsTxtController
{
    public function __construct(
        private readonly LlmsTxt $llms,
        private readonly Responses $responses,
        private readonly SiteSettings $settings,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->settings->forRequest($request)->hideFromSearch) {
            throw new NotFound('The site is hidden from search engines');
        }

        return $this->responses->text($this->llms->render());
    }
}
