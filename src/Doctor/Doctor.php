<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Cli\Output;
use ReflectionClass;
use Throwable;

/**
 * bin/atar doctor: checks the machine, the configuration, the storage, the database and, on request, mail and what
 * the web server serves, and explains every problem in plain words with its fix. It changes nothing (the --http
 * probe's one-time token file aside) and works on a site that does not boot.
 */
final class Doctor
{
    /**
     * @param list<Check> $checks
     */
    public function __construct(private readonly array $checks) {}

    public static function standard(): self
    {
        return new self([new PhpChecks(), new ConfigChecks(), new StorageChecks(), new DatabaseChecks(), new MailChecks(), new WebChecks()]);
    }

    public function run(DoctorContext $context): DoctorReport
    {
        $results = [];
        foreach ($this->checks as $check) {
            try {
                array_push($results, ...$check->run($context));
            } catch (Throwable $e) {
                // One broken check must not hide the others behind a stack trace.
                $results[] = CheckResult::fail('doctor', sprintf('%s stopped: %s', (new ReflectionClass($check))->getShortName(), $this->clean($context, $e->getMessage())), 'fix the problem named above, then run the doctor again');
            }
        }

        return new DoctorReport($results);
    }

    /**
     * An exception's message without the database or mail passwords of the site.
     */
    private function clean(DoctorContext $context, string $message): string
    {
        $env = $context->env();
        $secrets = [];
        foreach (['DB_PASSWORD', 'MAIL_DSN', 'ATAR_GOOGLE_CLIENT_SECRET'] as $name) {
            $value = $env?->get($name);
            if ($value !== null && strlen($value) >= 4) {
                $secrets[] = $value;
            }
        }

        return str_replace($secrets, '***', $message);
    }

    /**
     * Runs the checks and prints the report; the exit code of bin/atar doctor.
     */
    public function report(DoctorContext $context, Output $output): int
    {
        $report = $this->run($context);
        $strict = $context->options->strict;
        if ($context->options->json) {
            $output->line((string) json_encode($report->toArray($strict), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->line('AtarCMS doctor: ' . $context->paths->base);
            foreach ($report->lines() as $line) {
                $output->line($line);
            }
        }

        return $report->exitCode($strict);
    }
}
