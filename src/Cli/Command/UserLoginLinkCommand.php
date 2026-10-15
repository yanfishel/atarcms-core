<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\LoginLinks;
use AtarCms\Auth\UserRepository;
use AtarCms\Cli\AsCommand;
use AtarCms\Cli\CliException;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Http\Routing\Router;
use AtarCms\Kernel\Config;

#[AsCommand('user:login-link', 'Print a one-time login URL valid for 10 minutes: user:login-link <email>')]
final class UserLoginLinkCommand implements Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginLinks $links,
        private readonly AuditLog $audit,
        private readonly Router $router,
        private readonly Config $config,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $email = $input->requireArgument(0, 'email');
        $user = $this->users->findByEmail($email) ?? throw new CliException(sprintf('No user with email %s', $email));
        if (!$user->isActive()) {
            throw new CliException(sprintf('User %s is disabled', $user->email));
        }

        $base = $this->config->appUrl();
        $token = $this->links->create($user);
        $this->audit->record('auth.login_link_created', null, 'user', $user->id, ['email' => $user->email]);

        $output->line($base . $this->router->url('admin.login.link', ['token' => $token]));
        $output->line(sprintf('The link works once and expires in %d minutes.', intdiv(LoginLinks::TTL_SECONDS, 60)));

        return 0;
    }
}
