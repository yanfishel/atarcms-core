<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use Closure;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Outgoing mail and Google sign-in. The DSN is shown as scheme, host and port only; with --mail the doctor connects
 * and authenticates (no mail is sent). Messages are cleaned of the DSN's credentials.
 */
final class MailChecks implements Check
{
    /** @var Closure(string): void */
    private readonly Closure $connect;

    /**
     * @param (Closure(string): void)|null $connect connects to the DSN's server and authenticates, or throws
     */
    public function __construct(?Closure $connect = null)
    {
        $this->connect = $connect ?? static function (string $dsn): void {
            $transport = Transport::fromDsn($dsn);
            if ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }
        };
    }

    public function run(DoctorContext $context): array
    {
        return [...$this->mail($context), $this->google($context)];
    }

    /**
     * @return list<CheckResult>
     */
    private function mail(DoctorContext $context): array
    {
        $value = $this->setting($context, 'mail.dsn', 'MAIL_DSN');
        if ($value === '') {
            return [
                CheckResult::warn('mail.config', 'No MAIL_DSN: the site sends no mail, contact form messages stay in the admin (Messages)', 'MAIL_DSN=smtp://user:password@smtp.example.com:587 and MAIL_FROM=site@<domain> in .env'),
                CheckResult::skip('mail.connect', 'skipped: no MAIL_DSN'),
            ];
        }
        try {
            $dsn = Dsn::fromString($value);
        } catch (Throwable) {
            return [
                CheckResult::fail('mail.config', 'MAIL_DSN is not a mail DSN', 'smtp://user:password@smtp.example.com:587 (STARTTLS) or smtps://user:password@smtp.example.com:465; URL-encode special characters of the password'),
                CheckResult::skip('mail.connect', 'skipped: MAIL_DSN does not parse'),
            ];
        }
        $shown = sprintf('%s://%s%s', $dsn->getScheme(), $dsn->getHost(), $dsn->getPort() === null ? '' : ':' . $dsn->getPort());
        $results = [CheckResult::ok('mail.config', 'mail goes through ' . $shown)];
        if (!$context->options->mail) {
            $results[] = CheckResult::skip('mail.connect', 'skipped: run doctor --mail to connect to the mail server');

            return $results;
        }
        try {
            ($this->connect)($value);
            $results[] = CheckResult::ok('mail.connect', 'connected and signed in to ' . $shown);
        } catch (Throwable $e) {
            $message = str_replace(array_filter([$dsn->getPassword(), $dsn->getUser(), $value], static fn(?string $secret): bool => $secret !== null && $secret !== ''), '***', $e->getMessage());
            $results[] = CheckResult::fail('mail.connect', sprintf('Cannot use the mail server %s: %s', $shown, $message), 'check the host, port, user and password of MAIL_DSN; then bin/atar mail:test you@example.com');
        }

        return $results;
    }

    private function google(DoctorContext $context): CheckResult
    {
        $id = $this->setting($context, 'auth.google.client_id', 'ATAR_GOOGLE_CLIENT_ID');
        $secret = $this->setting($context, 'auth.google.client_secret', 'ATAR_GOOGLE_CLIENT_SECRET');
        if (($id === '') !== ($secret === '')) {
            return CheckResult::fail('google.config', 'Only one of ATAR_GOOGLE_CLIENT_ID and ATAR_GOOGLE_CLIENT_SECRET is set: Google sign-in fails', 'set both (Google Cloud console, OAuth client "Web application") or neither');
        }

        return CheckResult::ok('google.config', $id === '' ? 'Google sign-in is off (login links only)' : 'Google sign-in is configured');
    }

    private function setting(DoctorContext $context, string $key, string $variable): string
    {
        $config = $context->config();
        if ($config !== null && $config->has($key)) {
            return trim($config->string($key, ''));
        }

        return trim($context->env()?->get($variable) ?? '');
    }
}
