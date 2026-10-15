<?php

// Theme settings. "menus" declares the menu locations (location => label, a translation key or plain text);
// templates print one with $this->menu('main'). "main" shows the sections (config/content.php 'main_menu', ordered in
// /admin/sections); any other location gets its own tree per language in /admin/menus.
// "images" declares image sizes for img() and /media/{variant}/…: width (required), height, fit ("contain" scales
// down to fit, "crop" covers the box and crops the centre) and quality. Images are never enlarged. After changing
// a size, run "bin/atar media:variants:clear".
// "editor" is what the admin's rich-text editor takes from the theme: "css" lists stylesheets inside assets/ that
// show the content as on the site (scope every rule to .ck-content: the editor lives in the admin page); "styles"
// fills the Style list (label, element p/h2/h3/h4/table, at most 4 classes; table styles are offered while the
// cursor is in a table); "templates" fills the Ready-made blocks list (label and an .html file inside the theme,
// whose markup must survive the rich-text sanitizer). Stored text keeps only the classes declared here and those of
// the editor's own features (style them in css/editor.css and css/site.css).
// "colors" is the palette of the font colour, background and table colour pickers (any colour can still be picked);
// "fonts" the font list (stored text keeps only these families). Without "fontSizes" and "markers" the editor keeps
// its own (classes text-tiny/-small/-big/-huge, marker-yellow/-green/-pink/-blue, pen-red/-green); to use your own:
//     'fontSizes' => ['small' => ['label' => 'site.editor.sizes.small', 'class' => 'text-small']],
//     'markers' => ['yellow' => ['label' => 'site.editor.markers.yellow', 'class' => 'marker-yellow', 'color' => '#fdfd77', 'type' => 'marker']],
// An empty list turns a font, size or marker list off ([] colours leave only the picker).
// "share_image" picks the layout of the image the site makes for social sharing from an entry's main photo and its
// title for search engines: "band" (the title on the darkened photo, the brand on a dark band below), "bottom" (the
// title at the bottom over a gradient) or "panel" (the title on a slanted panel of the admin colour beside the photo).
// After changing it, run "bin/atar share-images:rebuild".
return [
    'share_image' => ['layout' => 'band'],
    'menus' => [
        'main' => 'site.menus.main',
        // The links at the bottom of every page (Structure → Menus); the footer shows nothing while it is empty.
        'footer' => 'site.menus.footer',
    ],
    'images' => [
        'thumb' => ['width' => 480, 'height' => 320, 'fit' => 'crop'],
        // Cards of the home page, sections and lists.
        'card' => ['width' => 800, 'height' => 500, 'fit' => 'crop'],
        'medium' => ['width' => 800],
        'large' => ['width' => 1600],
        // The home page's top picture.
        'hero' => ['width' => 1600, 'height' => 1000, 'fit' => 'crop'],
        // A taller crop for phones' slide box (5:4; art direction: partials/hero-slider.php).
        'hero_phone' => ['width' => 800, 'height' => 640, 'fit' => 'crop'],
        // The site logo in the header (Site settings → Images), shown at most 48px high.
        'logo' => ['width' => 480, 'height' => 96],
    ],
    'editor' => [
        'css' => ['css/editor.css'],
        'styles' => [
            'lead' => ['label' => 'site.editor.lead', 'element' => 'p', 'classes' => ['lead']],
            'striped' => ['label' => 'site.editor.striped_table', 'element' => 'table', 'classes' => ['table-striped']],
        ],
        'templates' => [
            'note' => ['label' => 'site.editor.note', 'file' => 'editor/note.html'],
            'columns' => ['label' => 'site.editor.columns', 'file' => 'editor/columns.html'],
        ],
        'colors' => [
            ['label' => 'site.editor.colors.accent', 'color' => '#1e5e55'],
            ['label' => 'site.editor.colors.red', 'color' => '#c0392b'],
            ['label' => 'site.editor.colors.green', 'color' => '#27ae60'],
            ['label' => 'site.editor.colors.orange', 'color' => '#e67e22'],
            ['label' => 'site.editor.colors.grey', 'color' => '#7f8c8d'],
            ['label' => 'site.editor.colors.dark', 'color' => '#2c3e50'],
        ],
        'fonts' => [
            ['label' => 'site.editor.fonts.sans', 'family' => 'system-ui, sans-serif'],
            ['label' => 'site.editor.fonts.serif', 'family' => "Georgia, 'Times New Roman', serif"],
            ['label' => 'site.editor.fonts.mono', 'family' => 'ui-monospace, Consolas, monospace'],
        ],
    ],
];
