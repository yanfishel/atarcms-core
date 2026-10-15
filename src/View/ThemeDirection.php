<?php

declare(strict_types=1);

namespace AtarCms\View;

/**
 * The writing directions a theme lays out (theme.php 'direction'): both by default (logical CSS, right to left from
 * <html dir>), or left to right only, for a purchased template kept as it is on a site without right-to-left
 * languages. theme:check relaxes its logical-sides rule for an LTR-only theme and refuses one with an RTL locale.
 */
enum ThemeDirection: string
{
    case Both = 'both';
    case Ltr = 'ltr';
}
