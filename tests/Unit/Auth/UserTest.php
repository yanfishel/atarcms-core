<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Auth;

use AtarCms\Auth\User;
use AtarCms\Auth\UserStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The avatar's initials (the top bar and the Profile): the first letters of the first two words of the display name.
 */
final class UserTest extends TestCase
{
    #[DataProvider('names')]
    public function testInitials(string $name, string $email, string $initials): void
    {
        $user = new User(1, $email, $name, 'admin', null, UserStatus::Active, null, null, null, new DateTimeImmutable());

        self::assertSame($initials, $user->initials());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'two words' => ['ann lee', 'ann@example.com', 'AL'];
        yield 'three words' => ['Ann Mary Lee', 'ann@example.com', 'AM'];
        yield 'one word' => ['ann', 'ann@example.com', 'A'];
        yield 'extra spaces' => ['  ann   lee ', 'ann@example.com', 'AL'];
        yield 'only an e-mail' => ['', 'bob@example.com', 'B'];
        yield 'Cyrillic' => ['анна ли', 'ann@example.com', 'АЛ'];
        yield 'Hebrew' => ['דנה כהן', 'dana@example.com', 'דכ'];
    }
}
