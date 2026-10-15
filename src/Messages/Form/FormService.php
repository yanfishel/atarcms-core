<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use AtarCms\Content\ValidationErrors;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Security\PublicFormCheck;
use AtarCms\Http\Security\PublicFormToken;
use AtarCms\Kernel\AppKey;
use AtarCms\Kernel\Clock;
use AtarCms\Messages\MessageMailer;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\SubmitResult;
use Psr\Log\LoggerInterface;

/**
 * Receives the site's forms: submit() checks a visitor's form, stores it as a message (anonymous: no audit row) and
 * e-mails it. Spam is kept out by a honeypot field, the form's signed token (issued for that form; sent within seconds
 * of loading: a bot) and a per-address limit shared by all forms, counted by an HMAC of the address. Only the
 * configured fields are stored: the reserved ones in their columns, the others as "fields" in the form's order.
 */
final readonly class FormService
{
    /** The honeypot: a field the theme hides from people. */
    public const string HONEYPOT = 'website';

    /** The field of refusals that belong to no field (an expired form, too many messages). */
    public const string FORM_ERROR = 'form';

    /** At most this many messages per sender (address) in RATE_WINDOW seconds, and DAILY_LIMIT in a day, all forms together. */
    public const int RATE_LIMIT = 3;

    public const int RATE_WINDOW = 600;

    public const int DAILY_LIMIT = 20;

    private const array COLUMNS = ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'message' => 'body'];

    public function __construct(
        private MessageRepository $messages,
        private PublicFormToken $tokens,
        private FieldValidator $validator,
        private AppKey $key,
        private Clock $clock,
        private LoggerInterface $logger,
        private MessageMailer $mailer,
    ) {}

    /**
     * Stores a visitor's form and e-mails it (MessageMailer: when mail is set up; a failure is logged and the message
     * kept).
     *
     * @param string $pageUrl the absolute URL of the page the form was sent from
     *
     * @throws ValidationFailed field errors by field name, or FORM_ERROR: "validation.message.expired" (an old, broken
     *                          or another form's token; the text is kept) and "validation.message.too_many" (the
     *                          limit; HTTP 429)
     */
    public function submit(FormDefinition $form, FormInput $input, string $locale, string $pageUrl, ?string $ip): SubmitResult
    {
        $now = $this->clock->now();
        $check = $this->tokens->check($form->key, $input->token, $now);
        if (trim($input->website) !== '' || $check === PublicFormCheck::TooFast) {
            $this->logger->debug('A form was discarded as spam', ['form' => $form->key, 'honeypot' => trim($input->website) !== '', 'too_fast' => $check === PublicFormCheck::TooFast]);

            return SubmitResult::discarded();
        }

        $errors = new ValidationErrors();
        if ($check !== PublicFormCheck::Valid) {
            $errors->add(self::FORM_ERROR, 'validation.message.expired');
        }
        $values = $this->validator->validate($form, $input, $locale, $errors);
        $errors->throwIfAny();

        $ipHash = hash_hmac('sha256', 'ip|' . ($ip ?? 'unknown'), $this->key->value());
        if ($this->messages->countFrom($ipHash, $now->modify(sprintf('-%d seconds', self::RATE_WINDOW))) >= self::RATE_LIMIT
            || $this->messages->countFrom($ipHash, $now->modify('-1 day')) >= self::DAILY_LIMIT) {
            throw new ValidationFailed([self::FORM_ERROR => 'validation.message.too_many']);
        }

        $row = ['name' => null, 'email' => null, 'phone' => null, 'body' => null];
        $fields = [];
        foreach ($values as $name => $value) {
            if (isset(self::COLUMNS[$name])) {
                $row[self::COLUMNS[$name]] = $value;
            } else {
                $fields[$name] = $value;
            }
        }
        $id = $this->messages->insert([
            'form' => $form->key,
            'locale' => $locale,
            ...$row,
            'fields' => $fields,
            'page_url' => mb_substr($pageUrl, 0, 500),
            'ip_hash' => $ipHash,
            'created_at' => $now,
        ]);
        $message = $this->messages->find($id) ?? throw new \LogicException('The stored message is missing');
        if ($this->mailer->send($message)) {
            $this->messages->setMailed($message->id, $this->clock->now());
            $message = $this->messages->find($id) ?? $message;
        }

        return SubmitResult::stored($message);
    }
}
