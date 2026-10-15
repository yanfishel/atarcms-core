<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

use AtarCms\Auth\LoginFailure;
use League\OAuth2\Client\Token\AccessToken;
use LogicException;
use Throwable;

final class LeagueGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function authorization(): GoogleAuthorization
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['prompt' => 'select_account']);
        $verifier = $provider->getPkceCode();
        if (!is_string($verifier) || $verifier === '') {
            throw new LogicException('The OAuth provider did not generate a PKCE verifier');
        }

        return new GoogleAuthorization($url, $provider->getState(), $verifier);
    }

    public function claims(string $code, string $codeVerifier): array
    {
        $provider = $this->provider();
        $provider->setPkceCode($codeVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (Throwable $e) {
            throw new GoogleLoginFailed(LoginFailure::ProviderError, 'Token exchange failed: ' . $e->getMessage(), $e);
        }
        $idToken = $token instanceof AccessToken ? ($token->getValues()['id_token'] ?? null) : null;
        if (!is_string($idToken)) {
            throw new GoogleLoginFailed(LoginFailure::InvalidToken, 'The token response has no id_token');
        }

        return IdToken::claims($idToken);
    }

    private function provider(): PkceGoogleProvider
    {
        return new PkceGoogleProvider([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'redirectUri' => $this->redirectUri,
            'timeout' => 10,
        ]);
    }
}
