<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\LoginService;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Http\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the user fresh on every request, so disabling or re-roling someone applies immediately.
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = Session::of($request);
        $id = LoginService::userId($session);
        if ($id !== null) {
            $user = $this->users->find($id);
            if ($user !== null && $user->isActive()) {
                $request = $request->withAttribute(User::class, $user);
            } else {
                $session->invalidate();
            }
        }

        return $handler->handle($request);
    }
}
