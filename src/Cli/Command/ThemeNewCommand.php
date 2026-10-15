<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\I18n\Locales;
use AtarCms\Kernel\Paths;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Starts a theme in themes/<name>: the core's scaffold (resources/themes/scaffold: a layout, every page type, a
 * stylesheet with tokens and logical properties, a catalog per site language) or a copy of a site theme (--from).
 * The scaffold carries en, ru, he, de and fr catalogs; another language gets a copy of the English one to translate.
 */
#[AsCommand('theme:new', 'Start a theme from the scaffold or a copy of another theme: theme:new <name> [--from=<theme>]')]
final class ThemeNewCommand implements Command
{
    /** A new theme's name. */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/D';

    /** Any existing theme directory theme:check and --from accept (themes made by hand may differ from NAME_PATTERN). */
    public const string EXISTING_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/iD';

    private const string SCAFFOLD = __DIR__ . '/../../../resources/themes/scaffold';

    public function __construct(
        private readonly Paths $paths,
        private readonly Locales $locales,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $name = $input->requireArgument(0, 'name');
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new CliException(sprintf('Invalid theme name "%s": lowercase letters, digits and "-", starting with a letter', $name));
        }
        $target = $this->paths->theme($name);
        if (file_exists($target)) {
            throw new CliException(sprintf('themes/%s already exists', $name));
        }
        $from = $input->option('from');
        if (is_string($from)) {
            $source = $this->paths->theme($from);
            if (preg_match(self::EXISTING_PATTERN, $from) !== 1 || !is_dir($source)) {
                throw new CliException(sprintf('No theme directory themes/%s', $from));
            }
            $this->copy($source, $target, static fn(string $relative): bool => true);
            $output->line(sprintf('Created themes/%s as a copy of themes/%s.', $name, $from));
        } else {
            $this->copy(self::SCAFFOLD, $target, static fn(string $relative): bool => !str_starts_with($relative, 'lang/'));
            $untranslated = [];
            foreach ($this->locales->all() as $locale) {
                $catalog = self::SCAFFOLD . '/lang/' . $locale . '.php';
                if (!is_file($catalog)) {
                    $catalog = self::SCAFFOLD . '/lang/en.php';
                    $untranslated[] = $locale;
                }
                $this->write($target . '/lang/' . $locale . '.php', (string) file_get_contents($catalog));
            }
            $output->line(sprintf('Created themes/%s from the scaffold.', $name));
            if ($untranslated !== []) {
                $output->line(sprintf('Translate the English texts of lang/%s.php.', implode('.php, lang/', $untranslated)));
            }
        }
        $output->line(sprintf('Next: edit it, run "bin/atar theme:check %s", then set APP_THEME=%1$s in .env.', $name));

        return 0;
    }

    /**
     * @param \Closure(string): bool $include relative path => whether to copy it
     */
    private function copy(string $source, string $target, \Closure $include): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1);
            if ($include($relative)) {
                $this->write($target . '/' . $relative, (string) file_get_contents($file->getPathname()));
            }
        }
    }

    private function write(string $file, string $contents): void
    {
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new CliException(sprintf('Cannot create %s', $directory));
        }
        if (file_put_contents($file, $contents) === false) {
            throw new CliException(sprintf('Cannot write %s', $file));
        }
    }
}
