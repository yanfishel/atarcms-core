<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Exception;

use AtarCms\Http\Exception\CsrfTokenMismatch;
use AtarCms\Http\Exception\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class ValidationFailedTest extends TestCase
{
    public function testCarriesFieldErrorsAndStatus(): void
    {
        $e = new ValidationFailed(['email' => 'Enter a valid email address.', 'role' => 'Choose a role.']);

        self::assertSame(422, $e->statusCode());
        self::assertSame(['email' => 'Enter a valid email address.', 'role' => 'Choose a role.'], $e->errors);
        self::assertSame('Validation failed: email: Enter a valid email address.; role: Choose a role.', $e->getMessage());
        self::assertSame(419, (new CsrfTokenMismatch())->statusCode());
    }
}
