<?php

declare(strict_types=1);

namespace AtarCms\Admin\Controller;

use AtarCms\Admin\AiGuide;
use AtarCms\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The AI agent guide: the admin described as one plain-text markdown document for the signed-in user.
 */
final class AiGuideController
{
    public function __construct(
        private readonly AiGuide $guide,
        private readonly Responses $responses,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->text($this->guide->render($request));
    }
}
