<?php

declare(strict_types=1);

namespace AtarCms\Admin;

final readonly class FlashMessage
{
    /**
     * @param 'success'|'error'|'info' $type an "info" toast is a page's own offer (never stored in the session); like an
     *                                       error it stays until closed
     * @param array<string, int|float|string> $params ICU parameters of the message
     * @param ToastForm|null $form a POST form under the text (page toasts only)
     */
    public function __construct(
        public string $type,
        public string $key,
        public array $params = [],
        public ?ToastForm $form = null,
    ) {}

    /**
     * A refused form's errors as error messages for the page's toast stack (the layout's "toasts"): the order
     * screens show a refused tree this way, since a tree has no field to put them at.
     *
     * @param array<string, string> $errors field => translation key
     * @param array<string, array<string, int|float|string>> $params field => ICU parameters of its message
     *
     * @return list<self>
     */
    public static function errors(array $errors, array $params): array
    {
        $messages = [];
        foreach ($errors as $field => $key) {
            $messages[] = new self('error', $key, $params[$field] ?? []);
        }

        return $messages;
    }
}
