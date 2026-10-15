<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Security;

use AtarCms\Http\Security\PublicFormCheck;
use AtarCms\Http\Security\PublicFormToken;
use AtarCms\Kernel\AppKey;
use AtarCms\Tests\Support\TempDirectory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PublicFormTokenTest extends TestCase
{
    use TempDirectory;

    public function testATokenIsValidFromAFewSecondsToADay(): void
    {
        $tokens = $this->tokens();
        $issued = new DateTimeImmutable('2026-10-04 12:00:00');
        $token = $tokens->issue('contact', $issued);

        self::assertSame(PublicFormCheck::TooFast, $tokens->check('contact', $token, $issued->modify('+2 seconds')));
        self::assertSame(PublicFormCheck::Valid, $tokens->check('contact', $token, $issued->modify('+3 seconds')));
        self::assertSame(PublicFormCheck::Valid, $tokens->check('contact', $token, $issued->modify('+24 hours')));
        self::assertSame(PublicFormCheck::Expired, $tokens->check('contact', $token, $issued->modify('+24 hours +1 second')));
    }

    public function testForgedOrForeignTokensAreInvalid(): void
    {
        $tokens = $this->tokens();
        $now = new DateTimeImmutable('2026-10-04 12:00:00');
        $token = $tokens->issue('contact', $now->modify('-1 minute'));
        [$time, $mac] = explode('.', $token);

        self::assertSame(PublicFormCheck::Invalid, $tokens->check('order', $token, $now), 'another form');
        self::assertSame(PublicFormCheck::Invalid, $tokens->check('contact', ((int) $time - 60) . '.' . $mac, $now), 'another time');
        self::assertSame(PublicFormCheck::Invalid, $tokens->check('contact', $time . '.' . strrev($mac), $now));
        self::assertSame(PublicFormCheck::Invalid, (new PublicFormToken(new AppKey($this->tempDir() . '/other.key')))->check('contact', $token, $now), 'another site');
        foreach ([null, '', 'abc', '123', ['x'], '1.2.3'] as $broken) {
            self::assertSame(PublicFormCheck::Invalid, $tokens->check('contact', $broken, $now));
        }
    }

    private function tokens(): PublicFormToken
    {
        return new PublicFormToken(new AppKey($this->tempDir() . '/app.key'));
    }
}
