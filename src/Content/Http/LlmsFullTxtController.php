<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Llms\LlmsFullTxt;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /llms-full.txt: the full text of the site's entry pages for LLMs; a site hidden from search engines has none.
 */
final class LlmsFullTxtController
{
    public function __construct(
        private readonly LlmsFullTxt $llms,
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
