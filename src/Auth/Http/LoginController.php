<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Admin\AdminView;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\Google\GoogleIdentityProvider;
use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\SafeRedirect;
use AtarCms\Kernel\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LoginController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly LoginService $login,
        private readonly GoogleIdentityProvider $google,
        private readonly DevLoginController $devLogin,
        private readonly UserRepository $users,
        private readonly Roles $roles,
        private readonly Config $config,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if (CurrentUser::of($request) !== null) {
            return $this->responses->redirect($this->router->url('admin.dashboard'));
        }
        $query = $request->getQueryParams();
        $error = $query['error'] ?? null;
        $failure = is_string($error) ? LoginFailure::tryFrom($error) : null;
        $target = SafeRedirect::sanitize($query['redirect'] ?? null, '');
        $googleUrl = $this->google->isConfigured()
            ? $this->router->url('admin.login.google', $target === '' ? [] : ['redirect' => $target])
            : null;

        return $this->responses->html($this->view->render('login', $request, [
            'errorKey' => $failure?->translationKey(),
            'googleUrl' => $googleUrl,
            'devUsers' => $this->devLogin->isEnabled()
                ? array_values(array_filter($this->users->all(), static fn(User $user): bool => $user->isActive()))
                : [],
            'roles' => $this->roles->labels(),
            // Visitors of a production site are not told the console commands.
            'loginLinkHint' => $this->config->environment() !== Config::PRODUCTION,
        ]));
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        $this->login->logOut($request);

        return $this->responses->redirect($this->router->url('admin.login'), 303);
    }
}
