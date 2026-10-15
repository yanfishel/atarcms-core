<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel\Log;

use AtarCms\Kernel\Log\JsonFileLogger;
use AtarCms\Tests\Support\TempDirectory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;

final class JsonFileLoggerTest extends TestCase
{
    use TempDirectory;

    public function testWritesJsonLineWithInterpolatedMessage(): void
    {
        $logger = $this->logger(LogLevel::DEBUG);

        $logger->info('User {name} logged in', ['name' => 'Анна', 'id' => 7]);

        $record = $this->records()[0];
        self::assertSame('2026-09-28T10:00:00.000+00:00', $record['time']);
        self::assertSame('info', $record['level']);
        self::assertSame('User Анна logged in', $record['message']);
        self::assertSame(['name' => 'Анна', 'id' => 7], $record['context']);
    }

    public function testSkipsRecordsBelowMinimumLevel(): void
    {
        $logger = $this->logger(LogLevel::WARNING);

        $logger->info('ignored');
        $logger->error('kept');

        $records = $this->records();
        self::assertCount(1, $records);
        self::assertSame('kept', $records[0]['message']);
    }

    public function testNormalizesExceptions(): void
    {
        $this->logger(LogLevel::DEBUG)->error('Failed', ['exception' => new RuntimeException('Boom', 3)]);

        $context = $this->records()[0]['context'];
        self::assertIsArray($context);
        $exception = $context['exception'];
        self::assertIsArray($exception);
        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertSame('Boom', $exception['message']);
        self::assertSame(3, $exception['code']);
        self::assertArrayHasKey('trace', $exception);
    }

    public function testRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger(LogLevel::DEBUG)->log('verbose', 'x');
    }

    public function testRejectsUnknownMinimumLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonFileLogger($this->tempDir(), 'verbose');
    }

    public function testFallsBackToErrorLogWhenDirectoryIsUnusable(): void
    {
        // Create a regular file at 'blocked' so mkdir fails when trying to create 'blocked/logs'
        touch($this->tempDir() . '/blocked');

        $logger = new JsonFileLogger(
            $this->tempDir() . '/blocked/logs',
            LogLevel::DEBUG,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-28T10:00:00+00:00'),
        );

        $previousErrorLog = ini_get('error_log');
        $testErrorLog = $this->tempDir() . '/php-error.log';
        ini_set('error_log', $testErrorLog);

        try {
            // Log a record; should not throw even though directory creation fails
            $logger->info('Test message', ['key' => 'value']);

            // Verify error_log contains the message text
            $errorLogContent = (string) file_get_contents($testErrorLog);
            self::assertStringContainsString('Test message', $errorLogContent);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }
    }

    public function testFallsBackToErrorLogWhenJsonEncodingFails(): void
    {
        $logger = $this->logger(LogLevel::DEBUG);

        $previousErrorLog = ini_get('error_log');
        $testErrorLog = $this->tempDir() . '/php-error.log';
        ini_set('error_log', $testErrorLog);

        try {
            // NAN cannot be represented in JSON; json_encode() throws JsonException here.
            $logger->warning('Unencodable value', ['value' => NAN]);

            $errorLogContent = (string) file_get_contents($testErrorLog);
            self::assertStringContainsString('Unencodable value', $errorLogContent);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }
    }

    private function logger(string $minLevel): JsonFileLogger
    {
        return new JsonFileLogger(
            $this->tempDir() . '/logs',
            $minLevel,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-28T10:00:00+00:00'),
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function records(): array
    {
        $lines = file($this->tempDir() . '/logs/app-2026-09-28.log', FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(static function (string $line): array {
            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($record);

            return $record;
        }, $lines);
    }
}
