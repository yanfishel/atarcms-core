<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Http\Error;

use AtarCms\Http\Error\DebugErrorRenderer;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DebugErrorRendererTest extends TestCase
{
    public function testShowsEscapedDetailsAndPreviousExceptions(): void
    {
        $error = new RuntimeException('Outer <script>', 0, new LogicException('Inner cause'));

        $html = (new DebugErrorRenderer())->render($error, 500, (new Psr17Factory())->createServerRequest('GET', '/boom?x=<b>'));

        self::assertStringContainsString('Outer &lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString(LogicException::class, $html);
        self::assertStringContainsString('Inner cause', $html);
        self::assertStringContainsString('500 Server Error', $html);
    }
}
