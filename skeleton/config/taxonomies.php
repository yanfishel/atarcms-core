<?php

declare(strict_types=1);

use AtarCms\Content\Taxonomy;

// Taxonomies: vocabularies of terms, kept per language. A collection uses one with ->taxonomies([...]) (a checkbox
// list in the entry form) or with a Field::tags() field (a tag box with suggestions; flat taxonomies only).
// A route gives every term a public page that lists its entries; its first segment must be literal, except for the
// root route "/{slug}" shared with pages.
// Sections are the structure of the site: /{section}/{subsection} lists its articles, a section with subsections sends
// visitors to its first subsection, and the sections are the main menu (config/content.php 'main_menu'), ordered on
// the admin's Structure → Sections screen.
return [
    Taxonomy::make('sections')
        ->label('site.taxonomies.sections')
        ->hierarchical()
        ->route('/{slug}')
        ->redirectToFirstChild(),
    Taxonomy::make('tags')
        ->label('site.taxonomies.tags')
        ->route('/tag/{slug}'),
];
