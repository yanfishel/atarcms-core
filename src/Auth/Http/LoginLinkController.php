<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\LoginLinks;
use AtarCms\Auth\LoginService;
use AtarCms\Auth\UserRepository;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class LoginLinkController
{
    public function __construct(
        private readonly LoginLinks $links,
        private readonly UserRepository $users,
        private readonly LoginService $login,
        private readonly AuditLog $audit,
        private readonly Router $router,
        private readonly Responses $responses,
        private readonly LoggerInterface $logger,
    ) {}

    public function consume(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute('token');
        $userId = is_string($token) ? $this->links->consume($token) : null;
        $user = $userId === null ? null : $this->users->find($userId);

        if ($user === null) {
            // Anyone can request a garbage link: logged, not audited, so it cannot fill audit_log.
            $this->logger->notice('Invalid login link used', ['ip' => ClientIp::of($request)]);

            return $this->responses->redirect($this->router->url('admin.login', ['error' => LoginFailure::LinkInvalid->value]), 303);
        }
        if (!$user->isActive()) {
            $this->audit->record(
                'auth.login_failed',
                $user->id,
                'user',
                $user->id,
                ['method' => 'login_link', 'reason' => LoginFailure::Disabled->value],
                ClientIp::of($request),
            );

            return $this->responses->redirect($this->router->url('admin.login', ['error' => LoginFailure::Disabled->value]), 303);
        }

        $this->login->logIn($request, $user, 'login_link');

        return $this->responses->redirect($this->router->url('admin.dashboard'), 303);
    }
}
