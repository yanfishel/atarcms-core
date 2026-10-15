<?php

declare(strict_types=1);

namespace AtarCms\View;

use InvalidArgumentException;

final class ThemeResolver
{
    /**
     * @param list<string> $directories highest priority first
     */
    public function __construct(private readonly array $directories) {}

    public function find(string $name): ?string
    {
        if (preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$~', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid template name "%s"', $name));
        }
        foreach ($this->directories as $directory) {
            $file = rtrim($directory, '/') . '/' . $name . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    public function resolve(string ...$names): string
    {
        foreach ($names as $name) {
            $file = $this->find($name);
            if ($file !== null) {
                return $file;
            }
        }

        throw new TemplateNotFound(sprintf(
            'None of the templates [%s] exist in [%s]',
            implode(', ', $names),
            implode(', ', $this->directories),
        ));
    }

    /**
     * @return list<string>
     */
    public function directories(): array
    {
        return $this->directories;
    }
}
