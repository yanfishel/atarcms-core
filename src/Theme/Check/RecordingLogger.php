<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * The logger of the application RenderSmoke renders with: keeps the warnings and worse of the current request
 * (interpolated, with the exception's message and place) and writes nothing.
 */
final class RecordingLogger extends AbstractLogger
{
    private const array LEVELS = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /** @var list<string> */
    private array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            return;
        }
        $text = (string) $message;
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $text = str_replace('{' . $key . '}', (string) $value, $text);
            }
        }
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $text .= sprintf(' (%s:%d)', $exception->getFile(), $exception->getLine());
        }
        $this->records[] = $text;
    }

    /**
     * The records since the last call, which forgets them.
     *
     * @return list<string>
     */
    public function take(): array
    {
        $records = $this->records;
        $this->records = [];

        return $records;
    }
}
