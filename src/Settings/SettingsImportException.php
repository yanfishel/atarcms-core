<?php

declare(strict_types=1);

namespace AtarCms\Settings;

use RuntimeException;

/**
 * The JSON given to settings:import is not in the import shape; nothing was validated or written.
 */
final class SettingsImportException extends RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode('; ', $problems));
    }
}
