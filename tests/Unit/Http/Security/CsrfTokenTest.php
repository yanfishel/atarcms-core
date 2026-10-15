<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Security;

use AtarCms\Http\Security\CsrfToken;
use AtarCms\Http\Session\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    public function testTokenIsStableAcrossRequests(): void
    {
        $first = new Session();
        $token = CsrfToken::get($first);

        $next = new Session(str_repeat('a', 64), $first->all());

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, CsrfToken::get($first));
        self::assertSame($token, CsrfToken::get($next));
        self::assertFalse($next->isDirty(), 'reading an existing token must not rewrite the session');
    }

    public function testRotateIssuesANewToken(): void
    {
        $session = new Session();
        $old = CsrfToken::get($session);

        self::assertNotSame($old, CsrfToken::rotate($session));
        self::assertFalse(CsrfToken::matches($session, $old));
    }

    public function testMatchesRequiresAnExistingTokenAndAnExactString(): void
    {
        $session = new Session();

        self::assertFalse(CsrfToken::matches($session, ''));
        self::assertFalse($session->isDirty(), 'checking must not create a token');

        $token = CsrfToken::get($session);
        self::assertTrue(CsrfToken::matches($session, $token));
        self::assertFalse(CsrfToken::matches($session, strtoupper($token)));
        self::assertFalse(CsrfToken::matches($session, [$token]));
        self::assertFalse(CsrfToken::matches($session, null));
    }
}
