<?php

declare(strict_types=1);

use AtarCms\Content\Collection;
use AtarCms\Content\Field\Field;

return [
    Collection::make('articles')
        ->label('Articles')
        ->route('/articles/{slug}')
        ->archive('/articles', 2)
        ->feed()
        ->translatable()
        ->taxonomies(['topics'])
        ->fields([
            Field::textarea('summary')->max(500),
            Field::richText('body'),
            Field::number('rating')->min(0)->max(10)->indexed(),
            Field::date('event_date')->indexed()->tab('settings'),
            Field::select('kind', ['report' => 'Report', 'interview' => 'Interview'])->indexed(),
            Field::boolean('featured'),
            Field::youtube('video'),
            Field::relation('related', 'articles')->max(3),
            Field::tags('tags')->max(5),
            Field::image('cover')->tab('media'),
            Field::gallery('images')->max(4)->tab('media'),
            Field::file('attachment')->tab('media'),
        ]),
    Collection::make('pages')
        ->route('/pages/{slug}')
        ->hierarchical()
        ->translatable()
        ->sortBy('position', 'asc')
        ->fields([Field::richText('body'), Field::richText('teaser')->minimal()->max(200), Field::slides('hero')->label('site.slides')->max(3)]),
    Collection::make('contact')
        ->route('/contact')
        ->single()
        ->translatable()
        ->fields([Field::text('phone')->required()]),
    Collection::make('notes')
        ->fields([Field::text('text')->required()]),
    Collection::make('events')
        ->route('/events/{slug}')
        ->archive('/events')
        ->feed()
        ->searchable(false)
        ->taxonomies(['regions'])
        ->fields([Field::text('venue'), Field::relation('speakers', 'notes'), Field::datetime('starts_at')]),
    Collection::make('settings')
        ->single()
        ->fields([Field::text('tagline')]),
    Collection::make('info')
        ->label('Info pages')
        ->route('/{slug}')
        ->translatable()
        ->presets(['legal' => 'site.presets.legal'])
        ->fields([Field::richText('body')]),
    Collection::make('stories')
        ->label('Stories')
        ->route('/{sections}/{slug}')
        ->archive('/stories')
        ->feed()
        ->translatable()
        ->taxonomies(['sections'])
        ->fields([
            Field::richText('summary')->minimal()->help('Leave it empty to show the whole story on its section page.'),
            Field::richText('body'),
            Field::image('cover')->tab('media'),
            Field::tags('tags')->tab('settings'),
        ]),
    Collection::make('welcome')
        ->label('site.welcome')
        ->route('/welcome')
        ->single()
        ->plain()
        ->translatable()
        ->fields([Field::richText('intro')->tall(), Field::slides('hero')->label('site.slides')]),
];
