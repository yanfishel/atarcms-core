<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Session\Session;

/**
 * One-shot messages for the next page (e.g. "Changes saved." after a redirect); values are translation keys.
 */
final class Flash
{
    public const string SESSION_KEY = '_flash';

    /**
     * @param array<string, int|float|string> $params
     */
    public static function success(Session $session, string $key, array $params = []): void
    {
        self::add($session, 'success', $key, $params);
    }

    /**
     * @param array<string, int|float|string> $params
     */
    public static function error(Session $session, string $key, array $params = []): void
    {
        self::add($session, 'error', $key, $params);
    }

    /**
     * A refusal as an error toast: its first message, with that message's ICU parameters (nothing for no error).
     */
    public static function refusal(Session $session, ValidationFailed $refusal): void
    {
        $field = array_key_first($refusal->errors);
        if ($field !== null) {
            self::error($session, $refusal->errors[$field], $refusal->params[$field] ?? []);
        }
    }

    /**
     * @return list<FlashMessage> the stored messages, which are removed from the session
     */
    public static function pull(Session $session): array
    {
        $stored = $session->pull(self::SESSION_KEY, []);
        $messages = [];
        foreach (is_array($stored) ? $stored : [] as $item) {
            if (
                !is_array($item)
                || !in_array($item['type'] ?? null, ['success', 'error'], true)
                || !is_string($item['key'] ?? null)
                || !is_array($item['params'] ?? null)
            ) {
                continue;
            }
            /** @var 'success'|'error' $type */
            $type = $item['type'];
            /** @var array<string, int|float|string> $params */
            $params = array_filter($item['params'], static fn(mixed $value): bool => is_int($value) || is_float($value) || is_string($value));
            $messages[] = new FlashMessage($type, $item['key'], $params);
        }

        return $messages;
    }

    /**
     * @param 'success'|'error' $type
     * @param array<string, int|float|string> $params
     */
    private static function add(Session $session, string $type, string $key, array $params): void
    {
        $stored = $session->get(self::SESSION_KEY, []);
        $stored = is_array($stored) ? array_values($stored) : [];
        $stored[] = ['type' => $type, 'key' => $key, 'params' => $params];
        $session->set(self::SESSION_KEY, $stored);
    }
}
