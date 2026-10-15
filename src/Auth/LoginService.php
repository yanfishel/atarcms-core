<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Http\ClientIp;
use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\Session;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

final class LoginService
{
    public const string SESSION_KEY = '_auth_user';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
    ) {}

    public static function userId(Session $session): ?int
    {
        $id = $session->get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    /**
     * @throws LogicException for a disabled user: every caller rejects those first, this is the last line of defence
     */
    public function logIn(ServerRequestInterface $request, User $user, string $method): void
    {
        if (!$user->isActive()) {
            throw new LogicException(sprintf('User #%d is disabled and cannot log in', $user->id));
        }
        // Database writes first: sessions are saved even when the handler throws, so a failure here must not leave a
        // logged-in session without its audit row.
        $this->users->recordLogin($user->id);
        $this->audit->record('auth.login', $user->id, 'user', $user->id, ['method' => $method], ClientIp::of($request));
        $session = Session::of($request);
        $session->regenerate();
        $session->set(self::SESSION_KEY, $user->id);
        CsrfToken::rotate($session);
    }

    public function logOut(ServerRequestInterface $request): void
    {
        $user = CurrentUser::of($request);
        Session::of($request)->invalidate();
        if ($user !== null) {
            $this->audit->record('auth.logout', $user->id, 'user', $user->id, [], ClientIp::of($request));
        }
    }
}
