<?php

declare(strict_types=1);

namespace AtarCms\I18n;

use AtarCms\Kernel\Config;
use AtarCms\Kernel\ConfigException;

final readonly class Locales
{
    private const string CODE = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/';

    /**
     * @param list<string> $locales
     */
    public function __construct(
        private string $default,
        private array $locales,
    ) {
        if ($locales === []) {
            throw new ConfigException('Config "locales.locales" must list at least one locale');
        }
        foreach ($locales as $locale) {
            if (preg_match(self::CODE, $locale) !== 1) {
                throw new ConfigException(sprintf('Invalid locale code "%s"; use lowercase codes such as "en" or "pt-br"', $locale));
            }
        }
        if (count(array_unique($locales)) !== count($locales)) {
            throw new ConfigException('Config "locales.locales" lists a locale twice');
        }
        if (!in_array($default, $locales, true)) {
            throw new ConfigException(sprintf('Default locale "%s" is not listed in "locales.locales"', $default));
        }
    }

    public static function fromConfig(Config $config): self
    {
        $default = $config->string('locales.default', 'en');
        $list = [];
        foreach ($config->array('locales.locales') as $locale) {
            if (!is_string($locale)) {
                throw new ConfigException(sprintf('Config "locales.locales" must be a list of strings, got %s', get_debug_type($locale)));
            }
            $list[] = $locale;
        }

        return new self($default, $list === [] ? [$default] : $list);
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->locales;
    }

    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }
}
