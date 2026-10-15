<?php

declare(strict_types=1);

namespace AtarCms\Auth\Http;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\Avatar\GoogleAvatar;
use AtarCms\Auth\Google\GoogleIdentity;
use AtarCms\Auth\Google\GoogleIdentityProvider;
use AtarCms\Auth\Google\GoogleLogin;
use AtarCms\Auth\Google\GoogleLoginFailed;
use AtarCms\Auth\Google\IdTokenValidator;
use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\LoginService;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\SafeRedirect;
use AtarCms\Http\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class GoogleLoginController
{
    private const string SESSION_KEY = '_google_login';

    public function __construct(
        private readonly GoogleIdentityProvider $google,
        private readonly IdTokenValidator $validator,
        private readonly GoogleLogin $googleLogin,
        private readonly LoginService $login,
        private readonly AuditLog $audit,
        private readonly Router $router,
        private readonly Responses $responses,
        private readonly LoggerInterface $logger,
        private readonly GoogleAvatar $avatar,
    ) {}

    public function redirect(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->google->isConfigured()) {
            throw new NotFound('Google login is not configured');
        }
        $authorization = $this->google->authorization();
        Session::of($request)->set(self::SESSION_KEY, [
            'state' => $authorization->state,
            'verifier' => $authorization->codeVerifier,
            'redirect' => SafeRedirect::sanitize($request->getQueryParams()['redirect'] ?? null, $this->router->url('admin.dashboard')),
        ]);

        return $this->responses->redirect($authorization->url);
    }

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->google->isConfigured()) {
            throw new NotFound('Google login is not configured');
        }
        $pending = Session::of($request)->pull(self::SESSION_KEY);
        $pending = is_array($pending) ? $pending : [];
        $expectedState = $pending['state'] ?? null;
        $verifier = $pending['verifier'] ?? null;
        $target = SafeRedirect::sanitize($pending['redirect'] ?? null, $this->router->url('admin.dashboard'));
        $query = $request->getQueryParams();
        $state = $query['state'] ?? null;
        $code = $query['code'] ?? null;
        $identity = null;

        try {
            if (!is_string($expectedState) || !is_string($verifier) || !is_string($state) || !hash_equals($expectedState, $state)) {
                throw new GoogleLoginFailed(LoginFailure::StateMismatch, 'OAuth state is missing or does not match');
            }
            if (isset($query['error']) || !is_string($code) || $code === '') {
                throw new GoogleLoginFailed(LoginFailure::ProviderError, 'Google returned no authorization code');
            }
            $identity = $this->validator->validate($this->google->claims($code, $verifier), $this->google->clientId());
            $user = $this->googleLogin->resolveUser($identity);
        } catch (GoogleLoginFailed $failure) {
            return $this->fail($request, $failure, $identity);
        }

        $this->avatar->update($user, $identity->picture);
        $this->login->logIn($request, $user, 'google');

        return $this->responses->redirect($target, 303);
    }

    /**
     * Every failure is logged; only a failure with a validated Google identity (unknown, disabled or foreign-domain
     * account) is audited. A forged state, a provider error or an invalid token can be sent by anyone at no cost, so
     * auditing them would let anonymous visitors fill audit_log.
     */
    private function fail(ServerRequestInterface $request, GoogleLoginFailed $failure, ?GoogleIdentity $identity): ResponseInterface
    {
        $this->logger->notice('Google login failed: {message}', ['message' => $failure->getMessage()]);
        if ($identity !== null) {
            $this->audit->record(
                'auth.login_failed',
                null,
                null,
                null,
                ['method' => 'google', 'reason' => $failure->reason->value, 'email' => $identity->email],
                ClientIp::of($request),
            );
        }

        return $this->responses->redirect($this->router->url('admin.login', ['error' => $failure->reason->value]), 303);
    }
}
