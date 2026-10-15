<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\Kernel\ConfigException;
use DateTimeInterface;
use MessageFormatter;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Translates dot keys with ICU MessageFormat. Catalogs are PHP files ({directory}/{locale}.php)
 * returning nested arrays; later directories (the theme) override earlier ones (the core).
 */
final class Translator
{
    /** The core catalogs are written in English: the last fallback before the key itself. */
    public const string SOURCE_LOCALE = 'en';

    private const string CODE = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/';

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @param list<string> $directories catalog directories, lowest priority first
     */
    public function __construct(
        private readonly Locales $locales,
        private readonly array $directories,
        private readonly LoggerInterface $logger,
        private readonly bool $reportMisses = false,
    ) {}

    /**
     * @param array<string, mixed> $params ICU named arguments
     */
    public function translate(string $locale, string $key, array $params = []): string
    {
        foreach (array_unique([$locale, $this->locales->default(), self::SOURCE_LOCALE]) as $candidate) {
            $pattern = $this->catalog($candidate)[$key] ?? null;
            if ($pattern !== null) {
                if ($candidate !== $locale) {
                    $this->reportMiss($locale, $key);
                }

                return $this->format($candidate, $key, $pattern, $params);
            }
        }
        $this->reportMiss($locale, $key);

        return $key;
    }

    public function has(string $locale, string $key): bool
    {
        return isset($this->catalog($locale)[$key]);
    }

    /**
     * Whether translate() finds a message for $key: in $locale, the default locale or English.
     */
    public function canTranslate(string $locale, string $key): bool
    {
        foreach (array_unique([$locale, $this->locales->default(), self::SOURCE_LOCALE]) as $candidate) {
            if (isset($this->catalog($candidate)[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A label from the configuration ("site.taxonomies.sections" or plain text): its translation when translate()
     * finds one, else the label itself (the label() template helper).
     */
    public function label(string $locale, string $label): string
    {
        return $this->canTranslate($locale, $label) ? $this->translate($locale, $label) : $label;
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }
        $catalog = [];
        if (preg_match(self::CODE, $locale) === 1) {
            foreach ($this->directories as $directory) {
                $file = rtrim($directory, '/') . '/' . $locale . '.php';
                if (!is_file($file)) {
                    continue;
                }
                $messages = (static fn(string $__file): mixed => require $__file)($file);
                if (!is_array($messages)) {
                    throw new ConfigException(sprintf('Translation file %s must return an array', $file));
                }
                $catalog = array_replace($catalog, self::flatten($messages, '', $file));
            }
        }

        return $this->catalogs[$locale] = $catalog;
    }

    /**
     * @param array<mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix, string $file): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value)) {
                $flat = array_replace($flat, self::flatten($value, $name . '.', $file));
            } elseif (is_string($value)) {
                $flat[$name] = $value;
            } else {
                throw new ConfigException(sprintf(
                    'Translation "%s" in %s must be a string or an array, got %s',
                    $name,
                    $file,
                    get_debug_type($value),
                ));
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function format(string $locale, string $key, string $pattern, array $params): string
    {
        if (!str_contains($pattern, '{')) {
            return $pattern;
        }
        $arguments = [];
        $isolate = TextDirection::of($locale) === 'rtl';
        foreach ($params as $name => $value) {
            $value = $this->sanitizeParam($key, (string) $name, $value);
            // In a right-to-left message, text put in a plain "{name}" keeps its own direction (a Latin title ending
            // in "!", a Russian language name): FIRST STRONG ISOLATE … POP DIRECTIONAL ISOLATE. Numbers and
            // arguments of a plural or number format are left as they are.
            if ($isolate && is_string($value) && !is_numeric($value) && str_contains($pattern, '{' . $name . '}')) {
                $value = "\u{2068}" . $value . "\u{2069}";
            }
            $arguments[$name] = $value;
        }
        $formatted = MessageFormatter::formatMessage($locale, $pattern, $arguments);
        if ($formatted === false) {
            $this->logger->warning('Invalid translation "{key}" for locale {locale}: {error}', [
                'key' => $key,
                'locale' => $locale,
                'error' => intl_get_error_message(),
            ]);

            return $pattern;
        }

        return $formatted;
    }

    /**
     * Keeps values MessageFormatter can safely handle; anything else becomes its type name
     * (get_debug_type) so a bad translation parameter never causes a 500.
     */
    private function sanitizeParam(string $key, string $param, mixed $value): mixed
    {
        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value)
            || $value instanceof DateTimeInterface
        ) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        $this->logger->warning('Invalid translation parameter "{param}" for "{key}"', ['param' => $param, 'key' => $key]);

        return get_debug_type($value);
    }

    private function reportMiss(string $locale, string $key): void
    {
        if (!$this->reportMisses || isset($this->reported[$locale . '|' . $key])) {
            return;
        }
        $this->reported[$locale . '|' . $key] = true;
        $this->logger->warning('Missing translation "{key}" for locale {locale}', ['locale' => $locale, 'key' => $key]);
    }
}
