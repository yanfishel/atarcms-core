<?php

declare(strict_types=1);

namespace AtarCms\Tests\Support;

use AtarCms\Auth\Google\GoogleAuthorization;
use AtarCms\Auth\Google\GoogleIdentityProvider;

/**
 * Stands in for Google: returns the ID-token claims chosen by the test.
 */
final class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    /** @var array<string, mixed> */
    public array $claims = [];

    public ?string $lastCode = null;

    public ?string $lastVerifier = null;

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function claimsFor(string $email, string $sub = 'google-sub-1', array $overrides = []): array
    {
        return [
            ...[
                'iss' => 'https://accounts.google.com',
                'aud' => 'test-client-id',
                'sub' => $sub,
                'email' => $email,
                'email_verified' => true,
                'name' => 'Test User',
                'iat' => time(),
                'exp' => time() + 3600,
            ],
            ...$overrides,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function clientId(): string
    {
        return 'test-client-id';
    }

    public function authorization(): GoogleAuthorization
    {
        return new GoogleAuthorization('https://accounts.google.test/auth?state=fake-state', 'fake-state', 'fake-verifier');
    }

    public function claims(string $code, string $codeVerifier): array
    {
        $this->lastCode = $code;
        $this->lastVerifier = $codeVerifier;

        return $this->claims;
    }
}
