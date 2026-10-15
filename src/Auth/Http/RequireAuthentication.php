<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\CurrentUser;
use AtarCms\Http\Exception\Forbidden;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\RequiresSession;
use AtarCms\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequireAuthentication implements MiddlewareInterface, RequiresSession
{
    public function __construct(
        private readonly Router $router,
        private readonly Responses $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (CurrentUser::of($request) !== null) {
            return $handler->handle($request);
        }
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            throw new Forbidden('Authentication required');
        }

        $uri = $request->getUri();
        $target = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
        $login = $this->router->url('admin.login', ['redirect' => $target]);

        if ($request->hasHeader('HX-Request')) {
            return $this->responses->text('')->withHeader('HX-Redirect', $login);
        }

        return $this->responses->redirect($login);
    }
}
