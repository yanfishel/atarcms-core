<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Paths;
use AtarCms\Theme\Check\RenderSmoke;
use AtarCms\Theme\Check\Severity;
use AtarCms\Theme\Check\ThemeChecker;
use Throwable;

/**
 * Checks a theme (the active one by default) against the site: ThemeChecker's static checks, then RenderSmoke's
 * pages through an application booted with that theme, so the running site is never switched. Exit 0 without
 * errors, 1 with errors, 2 when the theme directory is missing.
 */
#[AsCommand('theme:check', 'Check a theme against the site (templates, CSP, catalogs, CSS, every page rendered): theme:check [<theme>] [--json] [--no-render]')]
final class ThemeCheckCommand implements Command
{
    public function __construct(
        private readonly ThemeChecker $checker,
        private readonly Paths $paths,
        private readonly Config $config,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0) ?? $this->config->string('app.theme');
        $directory = $this->paths->theme($name);
        if (preg_match(ThemeNewCommand::EXISTING_PATTERN, $name) !== 1 || !is_dir($directory)) {
            $output->error(sprintf('No theme directory themes/%s', $name));

            return 2;
        }
        $report = $this->checker->check($directory);
        if ($input->option('no-render') !== true) {
            try {
                $app = Application::boot($this->paths, ['app.theme' => $name]);
            } catch (Throwable $e) {
                $app = null;
                $report->error('render', null, null, 'The site does not boot with this theme: ' . $e->getMessage());
            }
            if ($app !== null) {
                (new RenderSmoke())->run($app, $report);
            }
        }

        if ($input->option('json') === true) {
            $output->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($report->findings() as $finding) {
                $output->line($finding->format());
            }
            foreach ($report->pages() as $page) {
                $output->line($page['status'] . ' ' . $page['url']);
            }
            $output->line(sprintf(
                'theme:check %s: %d errors, %d warnings',
                $name,
                count($report->findings(Severity::Error)),
                count($report->findings(Severity::Warning)),
            ));
        }

        return $report->ok() ? 0 : 1;
    }
}
