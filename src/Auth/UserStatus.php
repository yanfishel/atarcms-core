<?php

declare(strict_types=1);

namespace AtarCms\Auth;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
