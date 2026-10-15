<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Google;

use AtarCms\Auth\Google\LeagueGoogleIdentityProvider;
use AtarCms\Auth\Google\PkceGoogleProvider;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LeagueGoogleIdentityProviderTest extends TestCase
{
    public function testAuthorizationUrlUsesStateAndS256Pkce(): void
    {
        $provider = new LeagueGoogleIdentityProvider('client-1', 'secret', 'https://example.test/admin/login/google/callback');

        $authorization = $provider->authorization();
        parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

        self::assertTrue($provider->isConfigured());
        self::assertSame('accounts.google.com', parse_url($authorization->url, PHP_URL_HOST));
        self::assertSame('client-1', $query['client_id']);
        self::assertSame('https://example.test/admin/login/google/callback', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame($authorization->state, $query['state']);
        self::assertSame('select_account', $query['prompt']);
        self::assertIsString($query['scope']);
        self::assertStringStartsWith('openid', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertGreaterThanOrEqual(43, strlen($authorization->codeVerifier));
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $authorization->codeVerifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
        self::assertNotSame($authorization->state, $provider->authorization()->state);
    }

    public function testIsNotConfiguredWithoutCredentials(): void
    {
        self::assertFalse((new LeagueGoogleIdentityProvider('', '', 'https://example.test/cb'))->isConfigured());
        self::assertFalse((new LeagueGoogleIdentityProvider('client-1', '', 'https://example.test/cb'))->isConfigured());
    }

    public function testTokenExchangeHasATimeoutSoAWorkerCannotHangForever(): void
    {
        $provider = new LeagueGoogleIdentityProvider('client-1', 'secret', 'https://example.test/cb');
        $inner = (new ReflectionMethod($provider, 'provider'))->invoke($provider);
        self::assertInstanceOf(PkceGoogleProvider::class, $inner);
        $client = $inner->getHttpClient();
        self::assertInstanceOf(Client::class, $client);

        self::assertSame(10, $client->getConfig('timeout'));
    }
}
