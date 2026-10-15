<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\UserRepository;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One-click sign-in as any active user, for local development only: outside the "local" environment the route is a 404.
 * Rejections are neither logged nor audited (only a developer can reach it).
 */
final class DevLoginController
{
    public function __construct(
        private readonly Config $config,
        private readonly UserRepository $users,
        private readonly LoginService $login,
        private readonly Router $router,
        private readonly Responses $responses,
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->environment() === Config::LOCAL;
    }

    public function logIn(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isEnabled()) {
            throw new NotFound('Dev login is available in the local environment only');
        }
        $body = $request->getParsedBody();
        $id = is_array($body) ? ($body['user'] ?? null) : null;
        $user = is_string($id) && ctype_digit($id) ? $this->users->find((int) $id) : null;
        if ($user === null || !$user->isActive()) {
            $failure = $user === null ? LoginFailure::UnknownUser : LoginFailure::Disabled;

            return $this->responses->redirect($this->router->url('admin.login', ['error' => $failure->value]), 303);
        }

        $this->login->logIn($request, $user, 'dev');

        return $this->responses->redirect($this->router->url('admin.dashboard'), 303);
    }
}
