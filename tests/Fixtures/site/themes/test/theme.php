<?php

return [
    'menus' => [
        'main' => 'Main menu',
        'footer' => 'site.menus.footer',
    ],
    'images' => [
        'thumb' => ['width' => 320, 'height' => 320, 'fit' => 'crop'],
        'medium' => ['width' => 800],
        'large' => ['width' => 1600],
    ],
    'editor' => [
        'css' => ['editor.css'],
        'styles' => [
            'lead' => ['label' => 'site.editor.lead', 'element' => 'p', 'classes' => ['lead']],
            'striped' => ['label' => 'Striped table', 'element' => 'table', 'classes' => ['table-striped']],
        ],
        'templates' => [
            'note' => ['label' => 'site.editor.note', 'file' => 'editor/note.html'],
        ],
        'colors' => [
            ['label' => 'site.editor.accent', 'color' => '#3a7bd5'],
            ['label' => 'Grey', 'color' => 'hsl(0, 0%, 30%)'],
        ],
        'fonts' => [],
        'markers' => [
            'note' => ['label' => 'site.editor.note', 'class' => 'marker-note', 'color' => '#fdfd77', 'type' => 'marker'],
        ],
    ],
];
