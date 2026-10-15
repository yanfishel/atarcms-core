<?php

declare(strict_types=1);

// The default locale has no URL prefix; every other locale lives under /{locale}/...
return [
    'default' => 'en',
    'locales' => ['en', 'ru', 'he'],
    // The admin's languages: 'linked' makes the top bar's language menu switch the interface and the content
    // together; 'separate' keeps the interface language (Profile) and the content language (top bar) apart.
    'admin' => 'linked',
];
