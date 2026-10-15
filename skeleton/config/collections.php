<?php

declare(strict_types=1);

use AtarCms\Content\Collection;
use AtarCms\Content\Field\Field;

// Content types. After adding, changing or removing an ->indexed() field, run
// "bin/atar collections:sync" and then "bin/atar migrate".
// The site's structure is its sections (config/taxonomies.php): an article lives in exactly one section and its
// address repeats the sections, /{section}/{subsection}/{article}; pages are standalone at /{page}.
// The home and contacts pages are plain(): their form shows only their fields, they are always published, and search
// engines and link previews use the site settings (Settings → Site settings in the admin).
return [
    Collection::make('home')
        ->label('site.collections.home')
        ->route('/')
        ->single()
        ->plain()
        ->translatable()
        ->searchable(false)
        ->fields([
            // The top of the home page: the slides (a slider, on a tab of their own in the form), then the site name with
            // this line under it.
            Field::text('lead')->label('site.fields.lead')->help('site.fields.lead_help')->max(200),
            Field::slides('hero')->label('site.fields.hero'),
            Field::richText('intro')->label('site.fields.body')->tall(),
        ]),
    // The contact form (config/content.php 'contact_form') sits under the text. Its admin form also edits the site's
    // contact details (config/content.php 'contact_page'): address, opening hours, phone, e-mail, messengers, social
    // links and the map.
    Collection::make('contacts')
        ->label('site.collections.contacts')
        ->route('/contacts')
        ->single()
        ->plain()
        ->translatable()
        ->icon('map-pin')
        ->fields([
            Field::richText('body')->label('site.fields.body')->tall(),
        ]),
    Collection::make('news')
        ->label('site.collections.news')
        ->route('/news/{slug}')
        ->archive('/news')
        ->feed()
        ->translatable()
        ->fields([
            Field::richText('summary')->label('site.fields.summary')->minimal()->max(500),
            Field::richText('body')->label('site.fields.body'),
            Field::image('cover')->label('site.fields.cover')->tab('media'),
            Field::gallery('images')->label('site.fields.images')->max(20)->tab('media'),
            Field::file('attachment')->label('site.fields.attachment')->tab('media'),
            Field::youtube('video')->label('site.fields.video')->tab('media'),
            Field::tags('tags')->label('site.fields.tags')->tab('settings'),
        ]),
    // An article with an announce is announced on its section's page; without one it is shown there in full.
    Collection::make('articles')
        ->label('site.collections.articles')
        ->route('/{sections}/{slug}')
        ->translatable()
        ->taxonomies(['sections'])
        ->icon('align-left')
        ->fields([
            Field::richText('summary')->label('site.fields.announce')->help('site.fields.announce_help')->minimal()->max(500),
            Field::richText('body')->label('site.fields.body'),
            Field::image('cover')->label('site.fields.cover')->tab('media'),
            Field::gallery('images')->label('site.fields.images')->max(20)->tab('media'),
            Field::file('attachment')->label('site.fields.attachment')->tab('media'),
            Field::youtube('video')->label('site.fields.video')->tab('media'),
        ]),
    // Two preset pages every site has: "bin/atar migrate" creates them in every language (/privacy, /ru/privacy…),
    // always published, never deleted, their addresses fixed. The theme links them from its footer (presetUrl()), the
    // accessibility statement also from the accessibility toolbar.
    Collection::make('pages')
        ->label('site.collections.pages')
        ->route('/{slug}')
        ->translatable()
        ->sortBy('title', 'asc')
        ->presets(['privacy' => 'site.presets.privacy', 'accessibility' => 'site.presets.accessibility'])
        ->fields([
            Field::richText('body')->label('site.fields.body'),
            Field::image('cover')->label('site.fields.cover')->tab('media'),
            Field::gallery('images')->label('site.fields.images')->max(20)->tab('media'),
            Field::file('attachment')->label('site.fields.attachment')->tab('media'),
            Field::youtube('video')->label('site.fields.video')->tab('media'),
        ]),
];
