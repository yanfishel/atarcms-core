<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use AtarCms\Cli\Command;

final readonly class ModuleList
{
    /**
     * @param list<Module> $modules
     */
    public function __construct(public array $modules) {}

    /**
     * @return list<string>
     */
    public function migrationPaths(): array
    {
        return array_merge(...array_map(static fn(Module $module): array => $module->migrationPaths(), $this->modules));
    }

    /**
     * @return list<string>
     */
    public function adminTemplatePaths(): array
    {
        return array_merge(...array_map(static fn(Module $module): array => $module->adminTemplatePaths(), $this->modules));
    }

    /**
     * @return list<class-string<Command>>
     */
    public function commands(): array
    {
        return array_merge(...array_map(static fn(Module $module): array => $module->commands(), $this->modules));
    }
}
