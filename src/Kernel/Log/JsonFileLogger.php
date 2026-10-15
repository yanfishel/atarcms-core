<?php

declare(strict_types=1);

namespace AtarCms\Kernel\Log;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

final class JsonFileLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly string $directory,
        private readonly string $minLevel = LogLevel::DEBUG,
        ?Closure $clock = null,
    ) {
        if (!isset(self::LEVELS[$minLevel])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s"', $minLevel));
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s"', get_debug_type($level)));
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }

        $now = ($this->clock)();
        $record = [
            'time' => $now->format(DATE_RFC3339_EXTENDED),
            'level' => $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $this->normalize($context),
        ];

        try {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException) {
            error_log(sprintf('[%s] %s', $level, $record['message']));

            return;
        }
        $filePath = $this->directory . '/app-' . $now->format('Y-m-d') . '.log';

        @mkdir($this->directory, 0775, true);

        $written = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
        if ($written === false || !is_dir($this->directory)) {
            error_log(rtrim($line));
        }
    }

    /**
     * @param array<mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_float($value) && !is_finite($value)) {
                continue;
            }
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Throwable => [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                'trace' => $value->getTraceAsString(),
                'previous' => $value->getPrevious() === null ? null : $this->normalize($value->getPrevious()),
            ],
            is_array($value) => array_map($this->normalize(...), $value),
            $value instanceof Stringable => (string) $value,
            is_object($value) => $value::class,
            is_resource($value) => 'resource',
            default => $value,
        };
    }
}
