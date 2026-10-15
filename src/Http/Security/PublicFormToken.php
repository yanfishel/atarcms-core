<?php

declare(strict_types=1);

namespace AtarCms\Http\Security;

use AtarCms\Kernel\AppKey;
use DateTimeImmutable;

/**
 * The token of a public form (Route::publicForm()), which has no session: "{unix time}.{HMAC}" of the form's name and
 * the time it was shown, signed with the site key. It proves the form came from this site, and its age tells a bot
 * that posts at once (TooFast) from a visitor who left the page open for days (Expired).
 */
final readonly class PublicFormToken
{
    public const string FIELD = '_form';

    public const int MIN_SECONDS = 3;

    public const int MAX_SECONDS = 86400;

    public function __construct(private AppKey $key) {}

    public function issue(string $form, DateTimeImmutable $now): string
    {
        $time = (string) $now->getTimestamp();

        return $time . '.' . $this->mac($form, $time);
    }

    public function check(string $form, mixed $token, DateTimeImmutable $now): PublicFormCheck
    {
        if (!is_string($token) || preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{43})$/D', $token, $match) !== 1
            || !hash_equals($this->mac($form, $match[1]), $match[2])) {
            return PublicFormCheck::Invalid;
        }
        $age = $now->getTimestamp() - (int) $match[1];

        return match (true) {
            $age < self::MIN_SECONDS => PublicFormCheck::TooFast,
            $age > self::MAX_SECONDS => PublicFormCheck::Expired,
            default => PublicFormCheck::Valid,
        };
    }

    private function mac(string $form, string $time): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'public-form|' . $form . '|' . $time, $this->key->value(), true)), '+/', '-_'), '=');
    }
}
