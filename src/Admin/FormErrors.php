<?php

declare(strict_types=1);

namespace AtarCms\Admin;

/**
 * Splits a refused form's errors into those of inputs the form shows (they stay at their fields, counted by the
 * summary alert) and the rest, which have no field of their own (a stale version, a gone item, an unsupported language,
 * an input the form does not show): those are error toasts (the layout's "toasts").
 */
final class FormErrors
{
    /**
     * @param array<string, string> $errors field => translation key
     * @param array<string, array<string, int|float|string>> $params field => ICU parameters of its message
     * @param list<string> $placed the error keys of the inputs the form shows
     *
     * @return array{0: array<string, string>, 1: list<FlashMessage>} the placed errors, and the others as error
     *                                                                messages (in their order, with their parameters)
     */
    public static function split(array $errors, array $params, array $placed): array
    {
        $fields = [];
        $fieldless = [];
        foreach ($errors as $key => $message) {
            if (in_array($key, $placed, true)) {
                $fields[$key] = $message;
            } else {
                $fieldless[$key] = $message;
            }
        }

        return [$fields, FlashMessage::errors($fieldless, $params)];
    }
}
