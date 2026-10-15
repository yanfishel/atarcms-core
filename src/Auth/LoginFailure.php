<?php

declare(strict_types=1);

namespace AtarCms\Auth;

/**
 * Why a login attempt was rejected. The value travels in ?error= on the login page.
 */
enum LoginFailure: string
{
    case UnknownUser = 'unknown_user';
    case Disabled = 'disabled';
    case EmailUnverified = 'email_unverified';
    case DomainNotAllowed = 'domain_not_allowed';
    case StateMismatch = 'state_mismatch';
    case ProviderError = 'provider_error';
    case InvalidToken = 'invalid_token';
    case LinkInvalid = 'link_invalid';

    public function translationKey(): string
    {
        return 'auth.failure.' . $this->value;
    }
}
