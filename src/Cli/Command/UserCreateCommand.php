<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Auth\UserManager;
use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;

#[AsCommand('user:create', 'Create a user: user:create <email> --role=<role> [--name=<name>]')]
final class UserCreateCommand implements Command
{
    public function __construct(
        private readonly UserManager $users,
        private readonly Translator $translator,
        private readonly Locales $locales,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $email = $input->requireArgument(0, 'email');
        $role = $input->option('role');
        if (!is_string($role)) {
            throw new CliException('Missing option --role=<role>');
        }
        $name = $input->option('name');

        try {
            $user = $this->users->create($email, $role, is_string($name) ? $name : '', null, null);
        } catch (ValidationFailed $e) {
            foreach ($e->errors as $field => $message) {
                $output->error($field . ': ' . $this->translator->translate($this->locales->default(), $message));
            }

            return 1;
        }
        // Without a saved choice the admin follows the browser's Accept-Language, which on a single-language site
        // reads as a half-translated admin. The site's default language is the sane choice when the admin speaks
        // it; the Profile can change it.
        if (InterfaceLanguages::isSupported($this->locales->default())) {
            $this->users->setLocale($user->id, $this->locales->default());
        }
        $output->line(sprintf('Created user #%d %s (%s).', $user->id, $user->email, $user->role));

        return 0;
    }
}
