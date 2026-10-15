<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\DatabaseChecks;
use AtarCms\Doctor\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Status, string}>
     */
    public static function versions(): iterable
    {
        yield 'MariaDB 11.8 of Debian 13' => ['11.8.3-MariaDB-0+deb13u1 from Debian', Status::Ok, 'MariaDB 11.8.3'];
        yield 'MariaDB 10.11 LTS' => ['10.11.13-MariaDB-0ubuntu0.24.04.1', Status::Ok, 'MariaDB 10.11.13'];
        yield 'MariaDB 10.6 LTS' => ['10.6.21-MariaDB', Status::Warn, 'MariaDB 10.6.21'];
        yield 'an old replication prefix' => ['5.5.5-10.6.21-MariaDB-log', Status::Warn, 'MariaDB 10.6.21'];
        yield 'MariaDB 10.5' => ['10.5.27-MariaDB', Status::Fail, 'MariaDB 10.5.27'];
        yield 'MySQL 8.0' => ['8.0.36', Status::Warn, 'MySQL 8.0.36'];
        yield 'MySQL 8.4 of a distribution' => ['8.4.5-0ubuntu0.2', Status::Warn, 'MySQL 8.4.5'];
        yield 'MySQL 8.0.20' => ['8.0.20', Status::Fail, 'MySQL 8.0.20'];
        yield 'MySQL 5.7' => ['5.7.44-log', Status::Fail, 'MySQL 5.7.44'];
    }

    #[DataProvider('versions')]
    public function testServerVersions(string $raw, Status $status, string $name): void
    {
        $result = DatabaseChecks::server($raw);

        self::assertSame($status, $result->status, $result->message);
        self::assertStringContainsString($name, $result->message);
    }
}
