<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\Kernel\Config;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The site's outgoing mail (config mail.dsn, from MAIL_DSN: "smtp://user:pass@host:587", "smtps://…:465"; empty =
 * no mail) and its sender (mail.from, from MAIL_FROM; default "noreply@" + the host of app.url). The transport is
 * built on first use, so a mistake in the DSN is reported where mail is sent (and by bin/atar mail:test), never at
 * boot. SMTP waits at most TIMEOUT seconds per read.
 */
final class MailTransport
{
    public const int TIMEOUT = 5;

    private ?TransportInterface $transport;

    private function __construct(
        private readonly string $dsn,
        private readonly string $from,
        ?TransportInterface $transport,
    ) {
        $this->transport = $transport;
    }

    public static function fromConfig(Config $config): self
    {
        $from = trim($config->string('mail.from', ''));
        if ($from === '') {
            $from = 'noreply@' . (string) parse_url($config->appUrl(), PHP_URL_HOST);
        }

        return new self(trim($config->string('mail.dsn', '')), $from, null);
    }

    /**
     * A transport given directly (tests, or a site module with its own).
     */
    public static function using(TransportInterface $transport, string $from): self
    {
        return new self('', $from, $transport);
    }

    public function isEnabled(): bool
    {
        return $this->transport !== null || $this->dsn !== '';
    }

    public function from(): string
    {
        return $this->from;
    }

    /**
     * The transport, or null without mail.dsn.
     */
    public function transport(): ?TransportInterface
    {
        if ($this->transport === null && $this->dsn !== '') {
            $transport = Transport::fromDsn($this->dsn);
            if ($transport instanceof EsmtpTransport && ($stream = $transport->getStream()) instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT);
            }
            $this->transport = $transport;
        }

        return $this->transport;
    }
}
