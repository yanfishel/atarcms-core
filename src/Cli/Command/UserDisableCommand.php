<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Auth\UserManager;
use AtarCms\Auth\UserRepository;
use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;

#[AsCommand('user:disable', 'Disable a user: user:disable <email>')]
final class UserDisableCommand implements Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $manager,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $email = $input->requireArgument(0, 'email');
        $user = $this->users->findByEmail($email) ?? throw new CliException(sprintf('No user with email %s', $email));
        $this->manager->disable($user, null, null);
        $output->line(sprintf('Disabled user %s.', $user->email));

        return 0;
    }
}
