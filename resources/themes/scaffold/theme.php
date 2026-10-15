<?php

// The theme's settings (see the theme guide, section 4): menu locations, image sizes, the share image layout. Add
// 'editor' for rich-text styles and ready-made blocks, and 'direction' => 'ltr' only for a theme that cannot lay
// out right to left (theme:check refuses it on a site with a right-to-left language).
return [
    'menus' => [
        'main' => 'site.menus.main',
        'footer' => 'site.menus.footer',
    ],
    'images' => [
        'card' => ['width' => 640, 'height' => 400, 'fit' => 'crop'],
        'large' => ['width' => 1600],
    ],
    'share_image' => ['layout' => 'band'],
];
