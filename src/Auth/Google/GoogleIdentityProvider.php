<?php

declare(strict_types=1);

namespace AtarCms\Auth\Google;

interface GoogleIdentityProvider
{
    public function isConfigured(): bool;

    public function clientId(): string;

    /**
     * Starts a login: a fresh state and PKCE verifier plus the Google URL to send the browser to.
     */
    public function authorization(): GoogleAuthorization;

    /**
     * Exchanges the authorization code and returns the claims of the ID token from the token endpoint.
     *
     * @return array<string, mixed>
     *
     * @throws GoogleLoginFailed
     */
    public function claims(string $code, string $codeVerifier): array;
}
