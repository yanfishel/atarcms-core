<?php

declare(strict_types=1);

namespace AtarCms\Doctor;

use AtarCms\Kernel\Application;
use AtarCms\Kernel\Config;
use AtarCms\Kernel\Env;
use AtarCms\Kernel\Paths;
use Throwable;

/**
 * What the checks look at: the site's directories, the machine, the options and the site itself as far as it
 * loads (.env, then config/, then the whole application). Each stage is tried once; a failure is kept as a message
 * for the config checks to report, and the checks that need that stage skip.
 */
final class DoctorContext
{
    private ?Env $env = null;

    private ?string $envError = null;

    private ?Config $config = null;

    private ?string $configError = null;

    private ?Application $app = null;

    private ?string $bootError = null;

    private bool $loaded = false;

    public function __construct(
        public readonly Paths $paths,
        public readonly Platform $platform,
        public readonly DoctorOptions $options,
    ) {}

    public function env(): ?Env
    {
        $this->load();

        return $this->env;
    }

    public function envError(): ?string
    {
        $this->load();

        return $this->envError;
    }

    public function config(): ?Config
    {
        $this->load();

        return $this->config;
    }

    public function configError(): ?string
    {
        $this->load();

        return $this->configError;
    }

    public function app(): ?Application
    {
        $this->load();

        return $this->app;
    }

    /** Why the site does not boot (the .env or config message included), null when it boots. */
    public function bootError(): ?string
    {
        $this->load();

        return $this->envError ?? $this->configError ?? $this->bootError;
    }

    /**
     * The same site seen through another machine's facts (the web server's PHP), without loading it again.
     */
    public function withPlatform(Platform $platform): self
    {
        $this->load();
        $context = new self($this->paths, $platform, $this->options);
        $context->env = $this->env;
        $context->envError = $this->envError;
        $context->config = $this->config;
        $context->configError = $this->configError;
        $context->app = $this->app;
        $context->bootError = $this->bootError;
        $context->loaded = true;

        return $context;
    }

    /** The result for a check that needs the booted site when it does not boot. */
    public function skipWithoutBoot(string $id): CheckResult
    {
        return CheckResult::skip($id, 'skipped: the site does not boot (see config.boot)');
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        try {
            $this->env = Env::load($this->paths->path('.env'));
        } catch (Throwable $e) {
            $this->envError = $e->getMessage();

            return;
        }
        try {
            $config = Config::fromDirectory($this->paths->config(), $this->env);
            $config->appUrl();
            $this->config = $config;
        } catch (Throwable $e) {
            $this->configError = $e->getMessage();

            return;
        }
        try {
            $this->app = Application::boot($this->paths);
        } catch (Throwable $e) {
            $this->bootError = $e->getMessage();
        }
    }
}
