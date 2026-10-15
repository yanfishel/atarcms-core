<?php

declare(strict_types=1);

namespace AtarCms\Messages\Form;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A refused form on its way back to its page (a request attribute): which form, what the visitor typed and why it
 * was refused, so the page shows the form again without a session.
 */
final readonly class FormState
{
    /**
     * @param array<string, string> $values field => the typed text
     * @param array<string, string> $errors field (or FormService::FORM_ERROR) => translation key
     * @param array<string, array<string, int|float|string>> $params field => the key's ICU parameters
     */
    public function __construct(
        public string $key,
        public array $values,
        public array $errors,
        public array $params = [],
    ) {}

    /**
     * The state of form $key in $request, if it was refused.
     */
    public static function of(ServerRequestInterface $request, string $key): ?self
    {
        $state = $request->getAttribute(self::class);

        return $state instanceof self && $state->key === $key ? $state : null;
    }
}
