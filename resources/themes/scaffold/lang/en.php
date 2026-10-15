<?php

return [
    'site' => [
        'name' => 'My site',
        'skip' => 'Skip to content',
        'languages' => 'Languages',
        'menus' => [
            'main' => 'Main menu',
            'footer' => 'Footer menu',
        ],
        // The titles of the site's preset pages (config/collections.php), given when "bin/atar migrate" creates them.
        'presets' => [
            'privacy' => 'Privacy policy',
            'accessibility' => 'Accessibility statement',
        ],
        'breadcrumbs' => 'You are here',
        'no_entries' => 'Nothing published yet.',
        'pagination' => 'Pages',
        'newer' => 'Newer',
        'older' => 'Older',
        'page_of' => 'Page {page} of {pages}',
        'not_found' => 'Page not found',
        'not_found_text' => 'The address may be mistyped, or the page has moved. Try the search.',
        'error' => 'Something went wrong',
        'error_text' => 'Please try again in a few minutes.',
        'search' => [
            'title' => 'Search',
            'label' => 'Search the site',
            'submit' => 'Search',
            'found' => '{count, plural, =0 {Nothing found} one {# result} other {# results}}',
            'too_short' => 'Type a longer word.',
        ],
        'social' => 'Social networks',
        'network' => [
            'whatsapp' => 'WhatsApp',
            'viber' => 'Viber',
            'telegram' => 'Telegram',
            'vk' => 'VK',
            'telegram_channel' => 'Telegram channel',
            'youtube' => 'YouTube',
            'rutube' => 'Rutube',
            'dzen' => 'Dzen',
            'ok' => 'Odnoklassniki',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'x' => 'X',
            'tiktok' => 'TikTok',
            'pinterest' => 'Pinterest',
            'linkedin' => 'LinkedIn',
        ],
    ],
    // The site's forms: the shared texts, then per form (the contact form, and each form of config/forms.php):
    // title, intro, subject (the e-mail's: {name}, {site}), sent, submit, a label per field and per option
    // ("{field}.{option}").
    'forms' => [
        'optional' => '(optional)',
        'choose' => 'Choose…',
        'errors' => 'Please check the fields marked below.',
        'note' => 'We use your details only to answer you.',
        'trap' => 'Leave this field empty',
        'contact' => [
            'title' => 'Send us a message',
            'sent' => 'Thank you! Your message has been sent.',
            'submit' => 'Send message',
            'name' => 'Your name',
            'email' => 'Email',
            'phone' => 'Phone',
            'message' => 'Message',
        ],
    ],
];
