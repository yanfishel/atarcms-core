<?php

declare(strict_types=1);

namespace AtarCms\Cli\Command;

use AtarCms\Auth\AuditLog;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Cli\AsCommand;
use AtarCms\Cli\Command;
use AtarCms\Cli\Input;
use AtarCms\Cli\Output;
use AtarCms\Cli\Prompt;
use AtarCms\Demo\DemoSeeder;
use AtarCms\Demo\DemoWiper;
use AtarCms\Kernel\Config;
use Throwable;

/**
 * Replaces the site's content with demo content (DemoWiper, then DemoSeeder): destructive by design, for a new site
 * or a development copy. It first checks that the site can be seeded (nothing is deleted otherwise), then asks for
 * "yes" unless --force is given; without a terminal it needs --force. In production it always prints a warning and
 * what it will delete.
 */
#[AsCommand('demo:seed', 'DELETE all content and fill the site with demo content: demo:seed [--force] [--dry-run] [--user=<email>]')]
final class DemoSeedCommand implements Command
{
    public function __construct(
        private readonly DemoWiper $wiper,
        private readonly DemoSeeder $seeder,
        private readonly Prompt $prompt,
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {}

    public function run(Input $input, Output $output): int
    {
        $actor = $this->actor($input->option('user'), $output);
        if ($actor === false) {
            return 1;
        }
        if ($input->option('dry-run') === true) {
            // Counts first, then what would stop a real run; a dry run creates no directories.
            $counts = $this->wiper->counts();
            $output->line(sprintf(
                'Dry run: demo:seed would delete %d entries, %d sections and tags, %d menu items, %d files and %d messages, and the site settings.',
                $counts['entries'],
                $counts['terms'],
                $counts['menu_items'],
                $counts['media'],
                $counts['messages'],
            ));
            $output->line('Nothing was changed.');
            $problems = $this->seeder->problems(false);
            if ($problems !== []) {
                $output->error('The demo content cannot be made on this site:');
                foreach ($problems as $problem) {
                    $output->error('- ' . $problem);
                }

                return 1;
            }

            return 0;
        }
        $problems = $this->seeder->problems();
        if ($problems !== []) {
            $output->error('The demo content cannot be made on this site; nothing was deleted:');
            foreach ($problems as $problem) {
                $output->error('- ' . $problem);
            }

            return 1;
        }
        $force = $input->option('force') === true;
        if (!$force && !$this->prompt->isInteractive()) {
            $output->error('demo:seed deletes the whole content of the site. Run it with --force to confirm without a terminal.');

            return 1;
        }
        $production = $this->config->environment() === Config::PRODUCTION;
        if ($production) {
            $output->line('Warning: this site runs in the production environment.');
        }
        if ($production || !$force) {
            $counts = $this->wiper->counts();
            $output->line(sprintf(
                'This deletes %d entries, %d sections and tags, %d menu items, %d files and %d messages, and the site settings',
                $counts['entries'],
                $counts['terms'],
                $counts['menu_items'],
                $counts['media'],
                $counts['messages'],
            ));
            $output->line('(except the admin colour, "hide from search engines" and the verification codes), then fills the');
            $output->line('site with demo content. Users and the activity history stay.');
        }
        if (!$force && $this->prompt->ask('Type "yes" to continue:', $output) !== 'yes') {
            $output->line('Nothing was changed.');

            return 1;
        }

        $started = microtime(true);
        $wiped = $this->wiper->wipe();
        $output->line(sprintf(
            'Deleted %d entries, %d sections and tags, %d menu items, %d files, %d messages.',
            $wiped['entries'],
            $wiped['terms'],
            $wiped['menu_items'],
            $wiped['files'],
            $wiped['messages'],
        ));
        try {
            $made = $this->seeder->seed($actor);
        } catch (Throwable $error) {
            $output->error('The content was already deleted, but the demo content could not be made: ' . $error->getMessage());

            return 1;
        }
        $files = $made['']['files'] ?? 0;
        unset($made['']);
        foreach ($made as $locale => $counts) {
            $parts = [];
            foreach ($counts as $what => $count) {
                $parts[] = $count . ' ' . str_replace('_', ' ', $what);
            }
            $output->line(sprintf('%s: %s.', $locale, implode(', ', $parts)));
        }
        $output->line(sprintf('Uploaded %d demo pictures. Done in %s s.', $files, round(microtime(true) - $started, 1)));
        $this->audit->record('demo.seeded', $actor?->id, null, null, ['counts' => $made, 'files' => $files]);

        return 0;
    }

    /**
     * The author of the demo content: --user, else the first active administrator, else nobody.
     */
    private function actor(string|true|null $email, Output $output): User|false|null
    {
        if (is_string($email)) {
            $user = $this->users->findByEmail($email);
            if ($user === null) {
                $output->error(sprintf('No user with the email %s.', $email));

                return false;
            }

            return $user;
        }
        foreach ($this->users->all() as $user) {
            if ($user->role === 'admin' && $user->isActive()) {
                return $user;
            }
        }

        return null;
    }
}
