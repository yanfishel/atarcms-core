<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

final class Env
{
    /**
     * @param array<string, string> $values
     */
    private function __construct(
        private readonly array $values,
        private readonly bool $readProcessEnv,
    ) {}

    public static function load(string $file): self
    {
        $values = is_file($file) ? self::parse((string) file_get_contents($file)) : [];

        return new self($values, true);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values, false);
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
                // Never the line itself: it may hold a password ("export DB_PASSWORD=…").
                $name = preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) === 1 ? $match[1] : null;
                throw new ConfigException(sprintf(
                    'Invalid .env line %d%s: write NAME=value with NAME in capitals, digits and _ (no "export")',
                    $index + 1,
                    $name === null ? '' : ' (' . $name . ')',
                ));
            }
            $values[$matches[1]] = self::unquote($matches[2]);
        }

        return $values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if ($this->readProcessEnv) {
            $process = getenv($key);
            if ($process !== false) {
                return $process;
            }
        }

        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new ConfigException(sprintf('Env %s must be a boolean, got "%s"', $key, $value)),
        };
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new ConfigException(sprintf('Env %s must be an integer, got "%s"', $key, $value));
        }

        return (int) $value;
    }

    public function required(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new ConfigException(sprintf('Missing required env %s', $key));
        }

        return $value;
    }

    private static function unquote(string $value): string
    {
        $quote = $value[0] ?? '';
        if ($quote === '"' || $quote === "'") {
            $closing = strpos($value, $quote, 1);
            if ($closing !== false) {
                $trailing = ltrim(substr($value, $closing + 1));
                if ($trailing === '' || str_starts_with($trailing, '#')) {
                    return substr($value, 1, $closing - 1);
                }
            }
        }

        $comment = strpos($value, ' #');

        return $comment === false ? $value : rtrim(substr($value, 0, $comment));
    }
}
