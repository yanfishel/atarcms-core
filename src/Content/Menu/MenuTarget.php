<?php

declare(strict_types=1);

namespace AtarCms\Content\Menu;

/**
 * What a menu item links to.
 */
enum MenuTarget: string
{
    case Entry = 'entry';
    case Term = 'term';
    case Url = 'url';
}
