<?php

declare(strict_types=1);

namespace AtarCms\Tests\Fixtures;

use AtarCms\Auth\CurrentUser;
use AtarCms\Http\Responses;
use AtarCms\Tests\Support\Cast;
use AtarCms\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class PageController
{
    public function __construct(
        private readonly Renderer $view,
        private readonly Responses $responses,
    ) {}

    public function home(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->view->render('home', ['title' => 'Home <b>'], $request));
    }

    public function news(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->view->render('news', ['slug' => Cast::string($request->getAttribute('slug'))], $request));
    }

    public function boom(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('Kaboom <script>');
    }

    public function whoami(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->text(CurrentUser::require($request)->email);
    }

    public function echoQuery(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->text(Cast::string($request->getQueryParams()['q'] ?? ''));
    }

    public function menus(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->view->render('menus', ['repeat' => isset($request->getQueryParams()['repeat'])], $request));
    }
}
