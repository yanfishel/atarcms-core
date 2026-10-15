<?php

declare(strict_types=1);

use AtarCms\Content\Taxonomy;

return [
    Taxonomy::make('topics')->label('Topics')->hierarchical()->route('/topics/{slug}', 2),
    Taxonomy::make('tags')->label('Tags')->route('/tags/{slug}'),
    Taxonomy::make('regions')->label('Regions'),
    Taxonomy::make('sections')->label('Sections')->hierarchical()->route('/{slug}', 2)->redirectToFirstChild(),
];
