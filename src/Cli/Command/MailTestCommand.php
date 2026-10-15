<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Messages\MessageMailer;
use Throwable;

/**
 * Sends a test mail through the site's mail settings (MAIL_DSN, MAIL_FROM) and prints the mail server's error if any.
 */
#[AsCommand('mail:test', 'Send a test mail through the mail settings (MAIL_DSN): mail:test <email>')]
final class MailTestCommand implements Command
{
    public function __construct(private readonly MessageMailer $mailer) {}

    public function run(Input $input, Output $output): int
    {
        $to = $input->requireArgument(0, 'email');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $output->error(sprintf('"%s" is not an e-mail address.', $to));

            return 1;
        }
        try {
            $this->mailer->test($to);
        } catch (Throwable $error) {
            $output->error('The mail was not sent: ' . $error->getMessage());

            return 1;
        }
        $output->line(sprintf('Sent a test mail to %s.', $to));

        return 0;
    }
}
