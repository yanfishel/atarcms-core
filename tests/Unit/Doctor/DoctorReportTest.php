<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Doctor;

use AtarCms\Doctor\Check;
use AtarCms\Doctor\CheckResult;
use AtarCms\Doctor\Doctor;
use AtarCms\Doctor\DoctorContext;
use AtarCms\Doctor\DoctorOptions;
use AtarCms\Doctor\DoctorReport;
use AtarCms\Doctor\NativePlatform;
use AtarCms\Kernel\Paths;
use AtarCms\Tests\Support\FakePlatform;
use PHPUnit\Framework\TestCase;

final class DoctorReportTest extends TestCase
{
    public function testTheTextReportHasOneLinePerCheckAFixLineAndASummary(): void
    {
        $report = new DoctorReport([
            CheckResult::ok('php.version', 'PHP 8.4.12'),
            CheckResult::warn('php.memory', 'memory_limit 200M', 'set memory_limit = 256M'),
            CheckResult::fail('db.connect', 'Cannot connect', 'check DB_HOST'),
            CheckResult::skip('db.migrations', 'skipped: no database'),
        ]);

        self::assertSame([
            'ok    php.version     PHP 8.4.12',
            'warn  php.memory      memory_limit 200M',
            '                      fix: set memory_limit = 256M',
            'FAIL  db.connect      Cannot connect',
            '                      fix: check DB_HOST',
            'skip  db.migrations   skipped: no database',
            '',
            'doctor: 1 failure, 1 warning, 1 skipped',
        ], $report->lines());
    }

    public function testExitCodesAndStrictMode(): void
    {
        $warning = new DoctorReport([CheckResult::ok('a', 'fine'), CheckResult::warn('b', 'hm')]);
        $failure = new DoctorReport([CheckResult::fail('a', 'broken')]);

        self::assertSame(0, $warning->exitCode(false));
        self::assertSame(1, $warning->exitCode(true));
        self::assertSame(1, $failure->exitCode(false));
        $lines = $warning->lines();
        $last = array_key_last($lines);
        self::assertNotNull($last);
        self::assertSame('doctor: no problems found, 1 warning', $lines[$last]);
    }

    public function testTheJsonShape(): void
    {
        $report = new DoctorReport([CheckResult::warn('b', 'hm', 'do x')]);

        self::assertSame(['ok' => true, 'checks' => [['id' => 'b', 'status' => 'warn', 'message' => 'hm', 'fix' => 'do x']]], $report->toArray(false));
        self::assertFalse($report->toArray(true)['ok']);
    }

    public function testTheDoctorRunsEveryCheckInOrder(): void
    {
        $first = new class implements Check {
            public function run(DoctorContext $context): array
            {
                return [CheckResult::ok('first', 'one')];
            }
        };
        $second = new class implements Check {
            public function run(DoctorContext $context): array
            {
                return [CheckResult::fail('second', 'two'), CheckResult::ok('third', 'three')];
            }
        };

        $report = (new Doctor([$first, $second]))->run(new DoctorContext(new Paths(__DIR__), new FakePlatform(), new DoctorOptions()));

        self::assertSame(['first', 'second', 'third'], array_map(static fn(CheckResult $r): string => $r->id, $report->results));
    }

    public function testACheckThatThrowsBecomesAFailureAndTheOthersStillRun(): void
    {
        $broken = new class implements Check {
            public function run(DoctorContext $context): array
            {
                throw new \RuntimeException('SQLSTATE[42000]: something broke');
            }
        };
        $fine = new class implements Check {
            public function run(DoctorContext $context): array
            {
                return [CheckResult::ok('fine', 'still checked')];
            }
        };

        $report = (new Doctor([$broken, $fine]))->run(new DoctorContext(new Paths(__DIR__), new FakePlatform(), new DoctorOptions()));

        self::assertSame(['doctor', 'fine'], array_map(static fn(CheckResult $r): string => $r->id, $report->results));
        self::assertStringContainsString('something broke', $report->results[0]->message);
        self::assertSame(1, $report->exitCode(false));
    }

    public function testTheRealIcuHasTheDataOfTheSiteLanguages(): void
    {
        $platform = new NativePlatform();

        self::assertSame('January', $platform->januaryName('en'));
        self::assertSame('январь', $platform->januaryName('ru'));
        self::assertSame('ינואר', $platform->januaryName('he'));
    }
}
