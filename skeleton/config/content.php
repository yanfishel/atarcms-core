<?php

declare(strict_types=1);

return [
    // The main menu (the theme's "main" location): the sections with these collections pinned among them, a single()
    // linking to its page and any other to its archive. The admin's Structure → Sections screen orders them all.
    'main_menu' => ['home', 'news', 'contacts'],
    // The single() page that shows the contact form and receives it (a POST to its address). Messages are kept in the
    // admin (Messages) and e-mailed to the site e-mail when MAIL_DSN is set (.env.example).
    'contact_form' => 'contacts',
    // The plain single() page whose admin form holds the site's contact details: the address and opening hours, phone,
    // e-mail, messengers, social links and the Google map (one for every language; the theme shows it with
    // $this->contactMap()).
    'contact_page' => 'contacts',
    // The schema.org type of the site in the home page's structured data (with its contact details): a schema.org
    // type name such as "Organization" (the default), "LocalBusiness" or a subtype ("Restaurant", "Dentist", "Store").
    // 'organization_type' => 'LocalBusiness',
    // Snapshots kept per entry for restoring earlier versions.
    'revisions' => 20,
    // The body of /robots.txt. Without it, /admin is disallowed, the Content Signals are stated
    // ("Content-Signal: search=yes, ai-input=yes, ai-train=no") and the sitemap is announced. It replaces that default
    // entirely: copy the Content-Signal line into it to keep it.
    // 'robots' => "User-agent: *\nDisallow: /\n",
    // The X-Robots-Tag of public HTML pages: by default search engines and AI answers may quote any length of text and
    // show large image and video previews. Another value replaces it (e.g. 'max-snippet:160'); '' sends none. Pages
    // that are hidden from search engines, previews and the admin never get it.
    // 'snippet_directives' => 'max-snippet:-1, max-image-preview:large, max-video-preview:-1',
    // Past this many URLs (10–50 000), /sitemap.xml becomes a sitemap index of /sitemap-{entries|terms}-{name}-{n}.xml pages.
    // 'sitemap_limit' => 50000,
    // Results per page of /search (1–100).
    'search_per_page' => 10,
];
