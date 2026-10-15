<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel\ErrorHandling;

use AtarCms\Http\Error\PlainErrorPage;
use AtarCms\Kernel\ErrorHandling\PhpErrorHandler;
use AtarCms\Tests\Support\MemoryLogger;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class PhpErrorHandlerTest extends TestCase
{
    private int $previousReporting;

    protected function setUp(): void
    {
        $this->previousReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->previousReporting);
    }

    public function testConvertsWarningsToExceptions(): void
    {
        $handler = new PhpErrorHandler(new MemoryLogger());

        try {
            $handler->handle(E_WARNING, 'Undefined array key "x"', '/app/file.php', 12);
            self::fail('Expected ErrorException');
        } catch (ErrorException $e) {
            self::assertSame(E_WARNING, $e->getSeverity());
            self::assertSame('/app/file.php', $e->getFile());
            self::assertSame(12, $e->getLine());
        }
    }

    public function testLogsDeprecationsInsteadOfThrowing(): void
    {
        $logger = new MemoryLogger();

        self::assertTrue((new PhpErrorHandler($logger))->handle(E_DEPRECATED, 'Old API', '/vendor/x.php', 5));
        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame('Old API', $logger->records[0]['context']['message']);
    }

    public function testIgnoresErrorsExcludedByErrorReporting(): void
    {
        error_reporting(E_ALL & ~E_NOTICE);

        self::assertFalse((new PhpErrorHandler(new MemoryLogger()))->handle(E_NOTICE, 'ignored'));
    }

    public function testShutdownReplacesPartialOutputWithPlainErrorPage(): void
    {
        $logger = new MemoryLogger();
        $level = ob_get_level();
        ob_start();
        echo '<html><body>half a page';

        (new PhpErrorHandler($logger))->handleShutdown(
            ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/app/x.php', 'line' => 3],
            true,
            $level,
        );

        $this->expectOutputString(PlainErrorPage::render(500));
        self::assertSame('critical', $logger->records[0]['level']);
        self::assertSame('Allowed memory size exhausted', $logger->records[0]['context']['message']);
    }

    public function testShutdownOnlyLogsWhenPageRenderingIsOff(): void
    {
        $logger = new MemoryLogger();
        ob_start();
        echo 'cli output';

        (new PhpErrorHandler($logger))->handleShutdown(
            ['type' => E_COMPILE_ERROR, 'message' => 'Cannot redeclare f()', 'file' => '/app/x.php', 'line' => 9],
            false,
            0,
        );

        self::assertSame('cli output', ob_get_clean());
        self::assertSame('critical', $logger->records[0]['level']);
    }

    public function testShutdownIgnoresNonFatalErrorsAndCleanExits(): void
    {
        $logger = new MemoryLogger();
        $handler = new PhpErrorHandler($logger);

        $handler->handleShutdown(null, true, ob_get_level());
        $handler->handleShutdown(['type' => E_WARNING, 'message' => 'x', 'file' => 'f', 'line' => 1], true, ob_get_level());

        self::assertSame([], $logger->records);
    }
}
