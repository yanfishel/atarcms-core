<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\ClientIp;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testReadsValidRemoteAddress(): void
    {
        $factory = new Psr17Factory();

        self::assertSame('203.0.113.7', ClientIp::of($factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '203.0.113.7'])));
        self::assertSame('2001:db8::1', ClientIp::of($factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '2001:db8::1'])));
        self::assertNull(ClientIp::of($factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => 'not-an-ip'])));
        self::assertNull(ClientIp::of($factory->createServerRequest('GET', '/')));
    }
}
