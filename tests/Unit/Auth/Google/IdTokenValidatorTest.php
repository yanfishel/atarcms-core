<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Google;

use AtarCms\Auth\Google\GoogleIdentity;
use AtarCms\Auth\Google\GoogleLoginFailed;
use AtarCms\Auth\Google\IdTokenValidator;
use AtarCms\Auth\LoginFailure;
use AtarCms\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenValidatorTest extends TestCase
{
    private const string CLIENT = 'client-1.apps.googleusercontent.com';

    public function testAcceptsValidClaims(): void
    {
        $identity = $this->validate(['email' => 'Ann@Example.COM', 'hd' => 'Example.COM', 'name' => 'Ann']);

        self::assertSame('sub-1', $identity->subject);
        self::assertSame('ann@example.com', $identity->email);
        self::assertSame('example.com', $identity->hostedDomain);
        self::assertSame('Ann', $identity->name);
    }

    public function testThePictureClaimIsKeptWhenItIsANonEmptyString(): void
    {
        self::assertSame('https://lh3.googleusercontent.com/a/x=s96-c', $this->validate(['picture' => 'https://lh3.googleusercontent.com/a/x=s96-c'])->picture);
        self::assertNull($this->validate([])->picture);
        self::assertNull($this->validate(['picture' => ''])->picture);
        self::assertNull($this->validate(['picture' => ['not', 'a', 'string']])->picture);
    }

    public function testAcceptsShortIssuerStringVerifiedFlagAndAudienceListWithAzp(): void
    {
        $identity = $this->validate([
            'iss' => 'accounts.google.com',
            'email_verified' => 'true',
            'aud' => [self::CLIENT, 'other-client'],
            'azp' => self::CLIENT,
        ]);

        self::assertNull($identity->hostedDomain);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, LoginFailure}>
     */
    public static function invalidClaims(): iterable
    {
        $now = (new FrozenClock())->now()->getTimestamp();

        yield 'foreign issuer' => [['iss' => 'https://evil.example'], LoginFailure::InvalidToken];
        yield 'other audience' => [['aud' => 'other-client'], LoginFailure::InvalidToken];
        yield 'audience list without azp' => [['aud' => [self::CLIENT, 'other-client']], LoginFailure::InvalidToken];
        yield 'azp of another client' => [['azp' => 'other-client'], LoginFailure::InvalidToken];
        yield 'expired beyond leeway' => [['exp' => $now - 61], LoginFailure::InvalidToken];
        yield 'issued in the future' => [['iat' => $now + 61], LoginFailure::InvalidToken];
        yield 'missing exp' => [['exp' => null], LoginFailure::InvalidToken];
        yield 'missing subject' => [['sub' => ''], LoginFailure::InvalidToken];
        yield 'missing email' => [['email' => null], LoginFailure::InvalidToken];
        yield 'unverified email' => [['email_verified' => false], LoginFailure::EmailUnverified];
        yield 'unverified email string' => [['email_verified' => 'false'], LoginFailure::EmailUnverified];
        yield 'missing verified flag' => [['email_verified' => null], LoginFailure::EmailUnverified];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidClaims')]
    public function testRejectsInvalidClaims(array $overrides, LoginFailure $reason): void
    {
        try {
            $this->validate($overrides);
            self::fail('Expected GoogleLoginFailed');
        } catch (GoogleLoginFailed $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testToleratesSmallClockSkew(): void
    {
        $now = (new FrozenClock())->now()->getTimestamp();

        self::assertSame('sub-1', $this->validate(['exp' => $now - 30, 'iat' => $now + 30])->subject);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function validate(array $overrides): GoogleIdentity
    {
        $clock = new FrozenClock();
        $now = $clock->now()->getTimestamp();
        $claims = array_filter([
            ...[
                'iss' => 'https://accounts.google.com',
                'aud' => self::CLIENT,
                'sub' => 'sub-1',
                'email' => 'ann@example.com',
                'email_verified' => true,
                'iat' => $now - 10,
                'exp' => $now + 3600,
            ],
            ...$overrides,
        ], static fn(mixed $value): bool => $value !== null);

        return (new IdTokenValidator($clock))->validate($claims, self::CLIENT);
    }
}
