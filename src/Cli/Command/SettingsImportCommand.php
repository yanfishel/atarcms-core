<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Paths;
use AtarCms\Settings\Settings;
use AtarCms\Settings\SettingsImport;
use AtarCms\Settings\SettingsImportException;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use JsonException;

/**
 * Writes the site settings from a JSON file (SettingsImport has the shape; --example prints the current settings in
 * it), validated like the Settings screen, all languages in one transaction, audited as a settings save. The actor is
 * --user, else the first active administrator, else nobody.
 */
#[AsCommand('settings:import', 'Write the site settings from JSON (names, contacts, social links, map…): settings:import <file.json> [--dry-run] [--user=<email>] | settings:import --example')]
final class SettingsImportCommand implements Command
{
    public function __construct(
        private readonly SettingsService $service,
        private readonly SiteSettings $settings,
        private readonly Locales $locales,
        private readonly UserRepository $users,
        private readonly Translator $translator,
        private readonly Paths $paths,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $current = fn(string $locale): Settings => $this->settings->of($locale);
        if ($input->option('example') === true) {
            $output->line((string) json_encode(SettingsImport::example($this->locales->all(), $current), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }
        $file = $input->requireArgument(0, 'file.json');
        $path = is_file($file) ? $file : $this->paths->path($file);
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new CliException(sprintf('Cannot read %s', $file));
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new SettingsImportException(['The file must hold a JSON object']);
            }
            $inputs = SettingsImport::inputs($data, $this->locales->all(), $this->locales->default(), $current);
        } catch (JsonException $e) {
            return $this->refuse($output, ['Invalid JSON: ' . $e->getMessage()]);
        } catch (SettingsImportException $e) {
            return $this->refuse($output, $e->problems);
        }
        if ($inputs === []) {
            // An empty file is a mistake more often than a wish to change nothing.
            return $this->refuse($output, ['The file names no settings: give "site" values or a language under "locales"']);
        }
        $dryRun = $input->option('dry-run') === true;
        try {
            $this->service->import($inputs, $dryRun ? null : $this->actor($input->option('user')), null, !$dryRun);
        } catch (ValidationFailed $failed) {
            $problems = [];
            foreach ($failed->errors as $field => $key) {
                [$locale, $name] = explode('.', $field, 2);
                $path = SettingsImport::path($locale, $name);
                $problems[$path] = sprintf('%s: %s (%s)', $path, $key, $this->translator->translate('en', $key, $failed->params[$field] ?? []));
            }

            return $this->refuse($output, array_values($problems));
        }
        $locales = implode(', ', array_keys($inputs));
        $output->line($dryRun ? sprintf('Dry run: the settings are valid (%s). Nothing was saved.', $locales) : sprintf('Saved the site settings: %s.', $locales));

        return 0;
    }

    /**
     * @param list<string> $problems
     */
    private function refuse(Output $output, array $problems): int
    {
        $output->error('Nothing was saved:');
        foreach ($problems as $problem) {
            $output->error('- ' . $problem);
        }

        return 1;
    }

    private function actor(string|true|null $email): ?User
    {
        if (is_string($email)) {
            return $this->users->findByEmail($email) ?? throw new CliException(sprintf('No user with the email %s.', $email));
        }
        foreach ($this->users->all() as $user) {
            if ($user->role === 'admin' && $user->isActive()) {
                return $user;
            }
        }

        return null;
    }
}
