<?php

declare(strict_types=1);

/*
 * The site's forms besides the contact form (content.contact_form): each opens in a dialog from a link anywhere
 * (data-form-dialog, partials/form-dialog.php) and has its own page /forms/{key} for visitors without JavaScript.
 * Messages land in the admin (Messages) and are e-mailed to "recipient" (null: the site e-mail of the settings).
 *
 * Field types: text, email, tel, textarea, date, time, select, radio, checkbox. The fields name, email, phone and
 * message are reserved (types text, email, tel, textarea); "website" and "form" are no field names. A form needs a
 * required email or phone. select/radio take "options" (keys); date takes "min"/"max" as "today", "+N days" or
 * "Y-m-d", time as "H:i". The labels come from the theme catalog: forms.{key}.title, .intro, .subject, .sent,
 * .submit, .{field} and .{field}.{option}. A "contact" entry here replaces the built-in contact form's fields.
 */
return [
    'booking' => [
        'recipient' => null,
        'fields' => [
            'name' => ['type' => 'text', 'required' => true],
            'phone' => ['type' => 'tel', 'required' => true],
            'email' => ['type' => 'email'],
            'service' => ['type' => 'select', 'required' => true, 'options' => ['consultation', 'diagnostics', 'follow_up']],
            'date' => ['type' => 'date', 'required' => true, 'min' => 'today', 'max' => '+60 days'],
            'time' => ['type' => 'time', 'min' => '09:00', 'max' => '18:00'],
            'consent' => ['type' => 'checkbox', 'required' => true],
            'message' => ['type' => 'textarea'],
        ],
    ],
];
