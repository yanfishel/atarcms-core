<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Kernel\Config;
use AtarCms\Theme\Check\Severity;
use AtarCms\Theme\Check\ThemeChecker;
use Throwable;

/**
 * .env, config/ and the theme: the site boots, production runs without debug output over https, and the active
 * theme passes theme:check's static checks.
 */
final class ConfigChecks implements Check
{
    public function run(DoctorContext $context): array
    {
        return [
            $this->env($context),
            $this->boot($context),
            $this->app($context),
            $this->theme($context),
            // Login links are pruned when used and sessions by PHP's own clean-up: nothing needs cron.
            CheckResult::ok('cron', 'nothing to schedule (only backups, see install.md)'),
        ];
    }

    private function env(DoctorContext $context): CheckResult
    {
        $file = $context->paths->path('.env');
        if ($context->envError() !== null) {
            return CheckResult::fail('config.env', '.env cannot be read: ' . $context->envError(), 'one NAME=value per line; compare with .env.example');
        }
        if (!is_file($file)) {
            return ($context->env()?->get('APP_URL') ?? '') !== ''
                ? CheckResult::ok('config.env', 'no .env: the settings come from the environment')
                : CheckResult::fail('config.env', 'There is no .env file', 'cp .env.example .env, then set APP_URL, APP_ENV and the DB_* values');
        }
        $mode = $context->platform->fileMode($file);
        // 0777 is what Docker Desktop's mounts show for every file; chmod cannot change it there.
        if ($mode !== null && ($mode & 0o004) !== 0 && $mode !== 0o777) {
            return CheckResult::warn('config.env', sprintf('.env (passwords) is readable by every user of this machine (mode %o)', $mode), 'chmod 600 .env (the site user still reads it)');
        }

        return CheckResult::ok('config.env', '.env found');
    }

    private function boot(DoctorContext $context): CheckResult
    {
        $error = $context->bootError();

        return $error === null
            ? CheckResult::ok('config.boot', 'the site boots (.env, config/, modules)')
            : CheckResult::fail('config.boot', 'The site does not boot: ' . $error, 'fix the setting named above in .env or config/ (see .env.example)');
    }

    private function app(DoctorContext $context): CheckResult
    {
        $config = $context->config();
        if ($config === null) {
            return $context->skipWithoutBoot('config.app');
        }
        $environment = $config->environment();
        $url = $config->appUrl();
        $summary = sprintf('APP_ENV=%s, APP_URL=%s, time zone %s', $environment, $url, $config->string('app.timezone', 'UTC'));
        if ($environment === Config::PRODUCTION) {
            if ($config->bool('app.debug', false)) {
                return CheckResult::fail('config.app', 'APP_DEBUG is on in production: error pages show code and paths to visitors', 'set APP_DEBUG=false in .env');
            }
            if (!str_starts_with($url, 'https://')) {
                return CheckResult::fail('config.app', sprintf('APP_URL %s is not https in production: the session cookie needs https', $url), 'set APP_URL=https://<domain> and put HTTPS in front of the site (Caddy, certbot)');
            }
            if ($config->string('app.log_level', 'info') === 'debug') {
                return CheckResult::warn('config.app', $summary . '; LOG_LEVEL=debug fills the log', 'set LOG_LEVEL=info in .env');
            }

            return CheckResult::ok('config.app', $summary);
        }
        if ($environment !== 'local') {
            return CheckResult::warn('config.app', $summary . ': only "production" and "local" are known environments', 'set APP_ENV=production (a server) or APP_ENV=local (your computer)');
        }

        return CheckResult::ok('config.app', $summary);
    }

    private function theme(DoctorContext $context): CheckResult
    {
        $app = $context->app();
        $config = $context->config();
        if ($app === null || $config === null) {
            return $context->skipWithoutBoot('config.theme');
        }
        $name = $config->string('app.theme', 'default');
        $directory = $context->paths->theme($name);
        if (!is_dir($directory)) {
            return CheckResult::fail('config.theme', sprintf('APP_THEME=%s but there is no themes/%s', $name, $name), 'set APP_THEME to a directory of themes/ (default: default)');
        }
        try {
            $report = $app->container()->service(ThemeChecker::class)->check($directory);
        } catch (Throwable $e) {
            return CheckResult::fail('config.theme', sprintf('The theme %s cannot be checked: %s', $name, $e->getMessage()), 'bin/atar theme:check ' . $name);
        }
        $errors = $report->findings(Severity::Error);
        $warnings = $report->findings(Severity::Warning);
        if ($errors !== []) {
            $first = array_map(static fn($finding): string => $finding->format(), array_slice($errors, 0, 3));

            return CheckResult::fail('config.theme', sprintf('The theme %s has %d errors: %s', $name, count($errors), implode('; ', $first)), 'bin/atar theme:check ' . $name);
        }
        if ($warnings !== []) {
            return CheckResult::warn('config.theme', sprintf('The theme %s has %d warnings', $name, count($warnings)), 'bin/atar theme:check ' . $name);
        }

        return CheckResult::ok('config.theme', sprintf('theme %s: no errors (static checks)', $name));
    }
}
