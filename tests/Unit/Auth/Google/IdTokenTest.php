<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth\Google;

use AtarCms\Auth\Google\GoogleLoginFailed;
use AtarCms\Auth\Google\IdToken;
use AtarCms\Auth\LoginFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
    public function testDecodesBase64UrlPayload(): void
    {
        $jwt = self::segment(['alg' => 'RS256']) . '.' . self::segment(['sub' => 'a?b>c', 'email' => 'ann@example.com']) . '.signature';

        self::assertSame(['sub' => 'a?b>c', 'email' => 'ann@example.com'], IdToken::claims($jwt));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'not a jwt' => ['abc'];
        yield 'two parts' => ['a.b'];
        yield 'bad base64' => ['a.!!!.c'];
        yield 'not json' => ['a.' . rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=') . '.c'];
        yield 'json scalar' => ['a.' . rtrim(strtr(base64_encode('"x"'), '+/', '-_'), '=') . '.c'];
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedTokens(string $jwt): void
    {
        try {
            IdToken::claims($jwt);
            self::fail('Expected GoogleLoginFailed');
        } catch (GoogleLoginFailed $e) {
            self::assertSame(LoginFailure::InvalidToken, $e->reason);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function segment(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
