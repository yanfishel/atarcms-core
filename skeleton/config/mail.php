<?php

declare(strict_types=1);

use AtarCms\Kernel\Env;

// Outgoing mail: the contact form's messages are e-mailed to the site e-mail (the contacts page's form, Contacts tab).
// Without a DSN no mail is sent and the messages stay in the admin (Messages). Check the settings with "bin/atar mail:test you@example.com".
return static fn(Env $env): array => [
    // smtp://user:password@smtp.example.com:587 (STARTTLS), smtps://user:password@smtp.example.com:465, or empty.
    'dsn' => $env->get('MAIL_DSN') ?? '',
    // The sender of the site's mail; empty: noreply@ and the host of APP_URL.
    'from' => $env->get('MAIL_FROM') ?? '',
];
