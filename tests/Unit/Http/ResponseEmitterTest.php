<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http;

use AtarCms\Http\ResponseEmitter;
use AtarCms\Http\Responses;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ResponseEmitterTest extends TestCase
{
    public function testEmitsBody(): void
    {
        $response = (new Responses(new Psr17Factory()))->html(str_repeat('x', 20000));

        ob_start();
        (new ResponseEmitter())->emit($response);
        $output = (string) ob_get_clean();

        self::assertSame(20000, strlen($output));
    }

    public function testOmitsBodyWhenRequested(): void
    {
        $response = (new Responses(new Psr17Factory()))->html('body');

        ob_start();
        (new ResponseEmitter())->emit($response, false);
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }
}
