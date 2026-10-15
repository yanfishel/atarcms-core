<?php

declare(strict_types=1);

namespace AtarCms\Messages;

use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\SiteTime;
use AtarCms\Messages\Form\FormDefinition;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Form\FormField;
use AtarCms\Messages\Form\FormLabels;
use AtarCms\Settings\SiteSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * E-mails a form's message to the form's recipient (config/forms.php), else the site's e-mail (the contact page's
 * Contacts tab, read in the default language), in the site's default language, as plain text: the form's subject and
 * its fields with the theme's labels (FormLabels). The sender is the site (MailTransport::from()), never the visitor,
 * whose address (when given) is the Reply-To; the visitor's name is cut and stripped of control characters before it
 * reaches the subject. Without mail set up or without an address nothing is sent; a failure is logged, and the
 * message stays in the admin either way.
 */
final readonly class MessageMailer
{
    private const int SUBJECT_NAME = 60;

    /** The core labels of the reserved fields: the contact form's, and any form's when the theme has none. */
    private const array CORE_LABELS = ['name' => 'mail.message.name', 'email' => 'mail.message.email', 'phone' => 'mail.message.phone'];

    public function __construct(
        private MailTransport $mail,
        private SiteSettings $settings,
        private Locales $locales,
        private Translator $translator,
        private SiteTime $time,
        private LoggerInterface $logger,
        private FormDefinitions $forms,
        private FormLabels $labels,
    ) {}

    /**
     * @return bool whether the message was handed to the mail server
     */
    public function send(Message $message): bool
    {
        if (!$this->mail->isEnabled()) {
            return false;
        }
        $locale = $this->locales->default();
        $settings = $this->settings->of($locale);
        $form = $this->forms->find($message->form);
        $to = $form->recipient ?? $settings->email;
        if ($to === null) {
            $this->logger->info('A form message was not e-mailed: the site settings have no e-mail address', ['message' => $message->id, 'form' => $message->form]);

            return false;
        }
        try {
            $site = $this->settings->siteName($locale, $settings);
            $name = mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $message->sender())), 0, self::SUBJECT_NAME);
            $email = new Email()
                ->from(new Address($this->mail->from(), $site))
                ->to($to)
                ->subject($this->subject($locale, $message->form, $name, $site))
                ->text(implode("\n", $this->body($locale, $message, $form, $name, $site)));
            if ($message->email !== null) {
                $email->replyTo(new Address($message->email, $name));
            }
            $transport = $this->mail->transport() ?? throw new \LogicException('Mail is enabled without a transport');
            $transport->send($email);

            return true;
        } catch (Throwable $error) {
            $this->logger->warning('A form message could not be e-mailed: ' . $error->getMessage(), ['message' => $message->id, 'form' => $message->form, 'exception' => $error::class]);

            return false;
        }
    }

    /**
     * The theme's forms.{key}.subject ({name}: the sender, {site}), else the core's "New message from {name} on {site}".
     */
    private function subject(string $locale, string $form, string $name, string $site): string
    {
        $key = 'forms.' . $form . '.subject';

        return $this->t($locale, $this->translator->canTranslate($locale, $key) ? $key : 'mail.message.subject', ['name' => $name, 'site' => $site]);
    }

    /**
     * The intro, a "label: value" line per field in the form's order (fields no longer configured after them, under
     * their names), the page and the time, then the message as a block.
     *
     * @return list<string>
     */
    private function body(string $locale, Message $message, ?FormDefinition $form, string $name, string $site): array
    {
        $contact = $message->form === FormDefinitions::CONTACT;
        $lines = [
            $contact
                ? $this->t($locale, 'mail.message.intro', ['site' => $site])
                : $this->t($locale, 'mail.message.form_intro', ['form' => $this->labels->title($locale, $message->form), 'site' => $site]),
            '',
        ];
        $values = ['name' => $message->name, 'email' => $message->email, 'phone' => $message->phone, ...$message->fields];
        $names = [...array_map(static fn(FormField $field): string => $field->name, $form->fields ?? []), ...array_keys($values)];
        foreach (array_unique($names) as $field) {
            $value = $values[$field] ?? null;
            if ($value === null || $field === 'message') {
                continue;
            }
            $core = isset(self::CORE_LABELS[$field]) ? [self::CORE_LABELS[$field]] : [];
            $label = $this->labels->field($locale, $message->form, $field, $contact ? $core : [], $contact ? [] : $core);
            $lines[] = $label . ': ' . $this->labels->value($locale, $message->form, $form?->field($field), $value, 'mail.message.yes');
        }
        $lines[] = $this->t($locale, 'mail.message.page') . ': ' . $message->pageUrl;
        $lines[] = $this->t($locale, 'mail.message.time') . ': ' . $this->time->readable($message->createdAt, $locale);
        if ($message->body !== null) {
            $lines[] = '';
            $lines[] = $message->body;
        }
        if ($message->email !== null) {
            $lines[] = '';
            $lines[] = '-- ';
            $lines[] = $this->t($locale, 'mail.message.reply', ['name' => $name]);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function t(string $locale, string $key, array $params = []): string
    {
        return $this->translator->translate($locale, $key, $params);
    }

    /**
     * Sends a test mail to $to (bin/atar mail:test); throws what the transport throws.
     */
    public function test(string $to): void
    {
        $transport = $this->mail->transport() ?? throw new \RuntimeException('Mail is not set up: set MAIL_DSN (config mail.dsn).');
        $locale = $this->locales->default();
        $site = $this->settings->siteName($locale);
        $transport->send(new Email()
            ->from(new Address($this->mail->from(), $site))
            ->to($to)
            ->subject($this->translator->translate($locale, 'mail.test.subject', ['site' => $site]))
            ->text($this->translator->translate($locale, 'mail.test.body', ['site' => $site])));
    }
}
