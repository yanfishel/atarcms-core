<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use AtarCms\Auth\LoginFailure;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Kernel\Config;

/**
 * Maps a validated Google identity to a pre-created user. Never creates users.
 */
final class GoogleLogin
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Config $config,
    ) {}

    /**
     * @throws GoogleLoginFailed
     */
    public function resolveUser(GoogleIdentity $identity): User
    {
        $allowed = $this->allowedDomains();
        if ($allowed !== [] && !in_array($identity->hostedDomain, $allowed, true)) {
            throw new GoogleLoginFailed(LoginFailure::DomainNotAllowed, sprintf('Google domain "%s" is not allowed', $identity->hostedDomain ?? ''));
        }

        $user = $this->users->findByGoogleSub($identity->subject);
        if ($user === null) {
            $candidate = $this->users->findByEmail($identity->email);
            $user = $candidate !== null && $candidate->email === $identity->email && $candidate->googleSub === null ? $candidate : null;
        }
        if ($user === null) {
            throw new GoogleLoginFailed(LoginFailure::UnknownUser, sprintf('No user for Google account %s', $identity->email));
        }
        if (!$user->isActive()) {
            throw new GoogleLoginFailed(LoginFailure::Disabled, sprintf('User #%d is disabled', $user->id));
        }
        if ($user->googleSub === null) {
            if (!$this->users->bindGoogleSub($user->id, $identity->subject)) {
                // Lost a race: another request bound a different Google account to this user
                // between our read and this write. Reject rather than trust the stale in-memory user.
                throw new GoogleLoginFailed(LoginFailure::UnknownUser, sprintf('User #%d was bound to a different Google account concurrently', $user->id));
            }
            $user = $this->users->find($user->id) ?? $user;
        }

        return $user;
    }

    /**
     * @return list<string>
     */
    private function allowedDomains(): array
    {
        $domains = [];
        foreach ($this->config->array('auth.google.allowed_domains') as $domain) {
            if (is_string($domain) && trim($domain) !== '') {
                $domains[] = strtolower(trim($domain));
            }
        }

        return $domains;
    }
}
