<?php

declare(strict_types=1);

namespace AtarCms\Theme\Check;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
