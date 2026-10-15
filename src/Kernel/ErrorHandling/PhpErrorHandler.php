<?php

declare(strict_types=1);

namespace AtarCms\Kernel\ErrorHandling;

use AtarCms\Http\Error\PlainErrorPage;
use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

final class PhpErrorHandler
{
    private const int FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private bool $shutdownRegistered = false;

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param bool $renderFatalPage replace the buffered output of a request that died with a fatal error
     *                              by a plain 500 page (web requests only)
     */
    public function register(bool $renderFatalPage = false): void
    {
        error_reporting(E_ALL);
        set_error_handler($this->handle(...));
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        $bufferLevel = ob_get_level();
        register_shutdown_function(function () use ($renderFatalPage, $bufferLevel): void {
            $this->handleShutdown(error_get_last(), $renderFatalPage, $bufferLevel);
        });
    }

    public function handle(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $level) === 0) {
            return false;
        }
        if ($level === E_DEPRECATED || $level === E_USER_DEPRECATED) {
            $this->logger->notice('Deprecated: {message}', ['message' => $message, 'file' => $file, 'line' => $line]);

            return true;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $error result of error_get_last()
     */
    public function handleShutdown(?array $error, bool $renderPage, int $bufferLevel): void
    {
        if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0) {
            return;
        }

        try {
            $this->logger->critical('Fatal error: {message}', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        } catch (Throwable) {
            error_log(sprintf('AtarCMS fatal error: %s in %s:%d', $error['message'], $error['file'], $error['line']));
        }

        if (!$renderPage) {
            return;
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('HTTP/1.1 500 ' . PlainErrorPage::reason(500));
            header('Content-Type: text/html; charset=utf-8');
        }
        echo PlainErrorPage::render(500);
    }
}
