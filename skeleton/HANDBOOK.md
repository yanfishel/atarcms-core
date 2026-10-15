# Site handbook

How an AtarCMS site works under the surface, for the person (or agent) who builds and runs it: the theme, the
content model, the admin, languages, media, search, the console and production. What the site can do and how to
install it: `README.md`.

## Development with Docker

```bash
docker compose up -d --build
docker compose exec -u www-data php composer install
docker compose exec -u www-data php php bin/atar migrate
```

One user runs the site's PHP: PHP-FPM, composer and every `bin/atar` command run as `www-data` in the container,
which the image moves to `HOST_UID`/`HOST_GID` of `.env` (on Linux set them to `id -u` and `id -g` before the first
build; Docker Desktop on Windows and macOS needs nothing). Never run them as root: files root leaves in `storage/`
(`app.key`, image caches) are unreadable for the site.

Open http://localhost:8080. CLI: `docker compose exec -u www-data php php bin/atar list`; `bin/atar doctor` checks
the installation. Mail the site sends in development lands in Mailpit: http://localhost:8025.

## Demo content

`php bin/atar demo:seed` deletes the site's content and fills every configured language with demo content: three
sections with two subsections each, two articles per subsection, six news with tags, the About page,
the home and contacts pages, the texts of the two preset pages (a privacy policy template: what the contact form keeps,
the browser, the map and videos; an accessibility statement template: the standard, what the site does, known limits
and the coordinator reached through the site's e-mail and phone; replace both with your own), the footer menu, the site
settings (names, contacts, messengers, social links), a map
of Tel Aviv and placeholder pictures labelled with their purpose and size ("Hero · 1600×900"). It deletes entries,
sections and tags, menu items, files (with their sizes and share images), messages and the site settings except the
admin colour, the "hide from search engines" and accessibility toolbar switches, the verification codes, the analytics counters and the advertising pixels; users and the activity history stay. It asks for
"yes"; `--force` skips the question (needed without a terminal), `--user=you@example.com` makes that user the
author (default: the first administrator), `--dry-run` only prints what it would delete. Running it again gives the
same content. The demo settings replace yours: put the real ones back with `php bin/atar settings:import site.json`
(`--example` prints the current settings in that JSON shape, `--dry-run` only validates).

## Building the site with an AI agent

The project ships an agent skill, `.agents/skills/building-atarcms-sites/` (the open Agent Skills format; Claude Code
finds it through `.claude/skills/`, other agents through `AGENTS.md`). Open your coding agent in the project and ask it
to build your site: it asks about your languages, sections, news and other content, contacts and design source
(sketches or pictures, a site you like as inspiration, or a purchased HTML template), writes the answers to
`site-brief.md` for you to confirm, then configures the content, builds a theme with `php bin/atar theme:new`, fills
demo content after asking, checks everything with `php bin/atar theme:check` and ends with a launch checklist. It
commits after each step and can resume from `site-brief.md`; it never edits `vendor/` and switches `APP_THEME` only
once the new theme passes the check.

## The default theme

The full reference for building themes (page templates and their variables, every helper, `theme.php`, rules and a
recipe) is `docs/theme-guide.md` in the AtarCMS repository; a copy ships with the site builder skill
(`.agents/skills/building-atarcms-sites/references/theme-guide.md`).

`themes/default` ("Atar") works in every language and direction: `<html lang dir>` comes from the page's language
(`$this->langAttrs($this->locale())`) and the stylesheet uses logical properties only (`margin-inline-start`,
`inset-inline-end`, `border-end-start-radius`…), so Hebrew pages lay themselves out right to left. It works
without JavaScript (the phone menu and the language dropdown are `<details>`); with it, `assets/js/theme.js` adds the
Light / Dark / System menu at the header's edge (the choice is kept in the browser and applied before the first paint
by a small nonce'd script in `<head>`; without a choice the site follows the device) and `assets/js/gallery.js` opens
gallery photos in the PhotoSwipe 5.4.4 lightbox (MIT, `assets/vendor/`, loaded only on pages with a gallery). It uses Rubik (Latin,
Cyrillic, Hebrew; `assets/fonts/`, SIL OFL) and a few Lucide icons (`assets/icons.svg`). Colours, type sizes and
spacing are custom properties at the top of `assets/css/site.css`. The layout takes `aside` (the side column's HTML,
`partials/aside.php`) and `wide` (the page draws its own full-width bands, as the home page does). Helpers it uses
besides those below: `termChildren('sections', $parent)` (a level of a taxonomy in tree order),
`$this->entries('articles')->withinTerm('sections', $term)` (a section's articles including its subsections),
`readableDate($time, false)` (the day in the page's language), `languageName($code)` and `adminBar($entry, $term)` (the quick
actions of a signed-in user: the admin, "Add" for the collections they may create in, "Edit" for this page, the
messages with their unread count; nothing for visitors; drawn by `partials/admin-bar.php`).

The theme has its own accessibility toolbar (no third-party overlay, which the Content Security Policy would refuse):
a round button at the bottom corner opens text size, wider spacing, a readable font, high contrast, greyscale,
inverted colours, marked links and headings, a strong focus ring, a big cursor, no animations, a reading guide and
hidden images (`partials/a11y.php`, `assets/js/a11y.js`; the visitor's choice is kept in the browser and applied before
the first paint). Settings → Site settings → General switches it off and on (on by default) and puts its button in one of the
four corners (bottom right by default; left and right swap in right-to-left languages). The panel links the
accessibility statement and the footer both preset pages (below) while they exist in the page's language.

Visitor statistics: with a Yandex Metrica counter (optionally with Webvisor, session replay) or a Google Analytics 4
measurement ID in Settings → Site settings → Integrations, visitors see a cookie banner (`partials/consent.php`; its
text per language in the same tab, empty = the theme's) and the counters load only after "Accept"; "Decline" loads
nothing, and "Cookie settings" in the footer changes the choice (kept 6 months in the `atar_consent` cookie).
Advertising pixels (Meta Pixel, Google Ads, VK Ads, TikTok Pixel; the same tab) make the banner offer "Accept all",
"Decline" and "Choose": statistics and advertising are allowed separately, and each loads only after its own consent. If
nginx restricts `/admin` by IP, keep `/admin-assets/` public: public pages load the logo files, `tooltip.js` and
`consent.js` from it.
Signed-in users are never counted. Without a counter or pixel there is no banner and nothing changes. The core decides and
loads (`consentHead()`, `consent()`; the theme guide's Cookie banner).

## Preset pages

Pages has two preset pages (`config/collections.php`, `->presets([...])`): the Privacy policy at `/privacy` and the
Accessibility statement at `/accessibility` (`/ru/privacy`, `/he/accessibility`… in the other languages).
`bin/atar migrate` creates every missing one in every language, titled from the theme catalog (`site.presets.*`),
published and empty, and a page already at that address is taken over; a new language or a new preset gets its pages
on the next `migrate`. Write their texts in Content → Pages (a lock chip marks them): their title, text and SEO are
yours, but they are always published, keep their address and cannot be deleted. The theme links them with
`presetUrl('privacy')` and `presetUrl('accessibility')`. To add one (Terms, say), add `'terms' => 'site.presets.terms'`
to the list, the label to every `themes/*/lang/*.php`, and run `bin/atar migrate`.

## Forms and mail

`config/content.php` `'contact_form' => 'contacts'` puts the contact form on the contacts page: the form posts to the
page's own address without a session (visitors get no cookie), a signed token and a hidden field keep simple bots out,
and one address may send 3 messages in 10 minutes and 20 a day (over all forms). The theme draws it from
`$this->contactForm()` (`partials/contact-form.php`). Further forms are defined in `config/forms.php` (the skeleton
has `booking`, "Book a consultation"): each opens in a dialog from a link (the header's "Book";
`partials/form-dialog.php`, `assets/js/forms.js`) and has its own page `/forms/{key}` for visitors without JavaScript;
its texts are the theme catalog's `forms.{key}.*`. Messages are listed in the admin under Messages (permission `messages.manage`:
administrators and editors) and e-mailed to the site e-mail of the settings when `MAIL_DSN` is set
(`smtp://user:password@smtp.example.com:587`; `MAIL_FROM` is the sender, default `noreply@` and the site's host). A mail
that cannot be sent is logged and the message stays in the admin. Check the settings with
`php bin/atar mail:test you@example.com`.

The active theme is set via the `APP_THEME` env var (default `default`) in `.env`. It is read both
by PHP (`config/app.php`) and by the web server (`compose.yaml`'s `ATAR_THEME`), which serves the theme's
`assets/` directory directly (without such a rule PHP serves `/theme-assets/` and `/admin-assets/` itself).

## Composer and versions

The core comes from its GitHub repository (`composer.json`, a `vcs` repository) at `^0.1`: `composer update
yanfishel/atarcms-core` takes the 0.1.x fixes; a new minor version (0.2) is a deliberate change of the constraint after
reading its `UPGRADE.md`. Commit `composer.lock`; never commit `auth.json` (Composer's credentials). While AtarCMS is
young, its 0.x versions are drafts: an update may need a fresh install (UPGRADE.md says so).

Inside the AtarCMS repository the skeleton is developed against the working tree instead: `compose.override.yaml`
(loaded by `docker compose` automatically, never released) mounts the whole repository and selects
`composer.dev.json`, whose path repository links `vendor/yanfishel/atarcms-core` to it.

## First administrator

There is no web registration. Create the first admin from the CLI and sign in with a one-time link:

```bash
docker compose exec -u www-data php php bin/atar user:create you@example.com --role=admin --name="Your Name"
docker compose exec -u www-data php php bin/atar user:login-link you@example.com
```

Open the printed URL within 10 minutes; it works once. More users are added in `/admin/users`.

To enable "Sign in with Google", create an OAuth client of type "Web application" in the Google Cloud
console with the redirect URI `{APP_URL}/admin/login/google/callback`, then set
`ATAR_GOOGLE_CLIENT_ID` and `ATAR_GOOGLE_CLIENT_SECRET` in `.env`. Only users that already exist
(matched by email on their first Google login) can sign in.

## Languages

Locales are listed in `config/locales.php`. The default locale has no URL prefix; the others are served
under `/{locale}/…` (`/ru/…`). A URL with the default locale's prefix (`/en/…`) redirects to the
unprefixed one. The admin (`/admin`) has no locale prefix; it speaks each user's interface language (English, Russian, Hebrew, German or French; see "Admin").

UI strings live in PHP arrays: the core ones in the package's `resources/lang/{locale}.php`, the theme's
in `themes/<name>/lang/{locale}.php` (the theme wins on the same key). In templates:

```php
<?= $this->e($this->t('site.running')) ?>
<?= $this->e($this->t('shop.items', ['count' => 3])) ?>   // ICU: '{count, plural, one {# item} other {# items}}'
<html lang="<?= $this->e($this->locale()) ?>">
<?= $this->hreflang() ?>                                  // <link rel="alternate" hreflang=…> for every locale
```

`$this->url('route.name', [...])` links within the current locale. A missing key falls back to the default
locale, then to English, then to the key itself; with `APP_DEBUG=true` misses are logged.

### Search engines and social sharing

The default layout prints, next to `hreflang()`:

```php
<?= $this->canonical() ?>   // <link rel="canonical" href="…">
<?= $this->socialMeta($title ?? null, $description ?? null, isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null, 'large') ?>
```

Entry pages, `single()` pages whose entry exists, archives and term pages have a canonical URL: their own absolute
address (`app.url` plus the path, `?page=N` from page 2 on, any other query string left out). Search, preview and
error pages have none, and both helpers print nothing there. `socialMeta()` prints the Open Graph tags (`og:type`
`article` on entries of collections that are not `single()`, `website` elsewhere (the home page and other single
pages, archives, term pages); `og:title` falls back to the site title of the site settings, then `app.name`;
`og:description` falls back to the site description, else it is left out; `og:url` is the canonical URL;
`og:site_name`; `og:locale` such as `en` or `pt_BR`; `og:image`, the entry's generated share image (with
`og:image:width` 1200 and `og:image:height` 630), else its main photo in the given variant, else the site's sharing
image) and `twitter:card` (`summary_large_image` with an image, `summary` without). After them it prints the
schema.org JSON-LD for search engines: `WebSite` on the home page, `Article` on news items and articles (collections
with an archive or sections), and `BreadcrumbList` on pages below an archive, a section or a parent page.

**Share images.** Nobody makes images for social networks by hand: when an entry of a collection with a route is
saved with a main photo, the site draws a 1200 × 630 card from it: the photo darkened, the title for search engines
on it, or the entry's own title when that is empty (at most four lines, the last ending in "…" when it is longer),
and the brand (the site icon, else the AtarCMS logo in the admin colour, the site name and the domain of `app.url`).
It is stored as `storage/uploads/share/{entry id}-{fingerprint}.jpg` and served at `/uploads/share/…`; a save that
changes nothing it shows draws nothing, no photo removes it, and the entry form's SEO tab shows the card at its top. The
main photo is the first image field, else the first photo of the first gallery field;
`Collection::shareImage('banner', 'images')` names other fields (the first that holds an image wins) and
`->shareImage()` without fields turns the cards off for a collection. `theme.php` picks the layout:
`'share_image' => ['layout' => 'band']` (the title on top, the brand on a dark band), `'bottom'` (the title at the
bottom over a gradient, an accent bar above the brand) or `'panel'` (the title on a slanted panel of the admin colour
beside the photo, with the short site name). Changing the layout, the site name, the site icon, the admin colour or
`app.url` does not touch saved cards: run `php bin/atar share-images:rebuild` (`--collection=news` for one
collection), which draws again only what changed. Drawing text needs PHP's GD extension built with FreeType (the
skeleton's image has it); without it every save logs a warning and the main photo is shared instead.

Entry pages and term pages receive `$seo` (`AtarCms\Content\PageSeo`: `title`, never empty, and `description`, null
when there is none), the texts for search engines resolved along the sections (see "Sections" below); the default
theme passes them to the layout, which prints them as `<title>`, `<meta name="description">` and the Open Graph texts:

```php
<?php $this->layout('layouts/default', ['title' => $seo->title, 'description' => $seo->description]); ?>
```

On a `single()` page whose entry does not exist yet `$seo` is null (`$seo?->title ?? …`). Archives, search and error
pages have none and name themselves.

### Site settings

Settings → Site settings in the admin (`/admin/settings`, permission `settings.manage`: administrators) holds the
site's name and short name, logo, icon, toolbar colour and the accessibility toolbar switch (General), the home page's title and the site description
for search engines, the page title pattern and a "hide from search engines" switch (SEO) and the search consoles'
verification codes, the analytics counters, the advertising pixels and the cookie banner's text (Integrations). The names, the SEO texts
and the banner text are kept per language; the rest is shared by the whole
site. The contact details are edited on the contacts page itself (Content → Contacts, `config/content.php`
`'contact_page' => 'contacts'`), in the right column's tabs, and saved with the page: the address and opening hours of
the form's language with the map (Address), the phone, email and messengers (Contacts) and the social network links
(Social); whoever may save that page edits them.

Theme helpers (all in the page's language; every value is user text, so escape what you print):

- `$this->siteHead()` in `<head>`: the icon in the browser tab (`/favicon.ico` too) and on a phone's home screen, the
  web app manifest (`/manifest.webmanifest`), the toolbar colour and the verification meta tags. Without a site icon
  the icons are the AtarCMS logo in the admin color; the toolbar colour and the verification tags only when set.
  The site icon must be a square PNG, JPEG or WebP of at least 512 × 512 pixels; the core makes its PNG sizes.
- `$this->pageTitle($title)`: the `<title>` by the title pattern, `{title} — {site}` by default (`{title}` is the
  page's title, `{site}` the site name); the site name alone for `null`. The default layout passes a page's `title`
  through it, and a page may pass `fullTitle` to print a title as it is (the home page passes the home page title).
- `$this->siteName()`: the site name, else the theme catalog's `site.name`, else `app.name`.
- `$this->siteLogo()`: the logo for `img()` (`<?= $this->img($this->siteLogo(), 'logo') ?>`), or null.
- `$this->socialLinks()` and `$this->messengers()`: the filled links (`$link->network`, `$link->url`) in a fixed
  order; draw their icons and labels yourself (the default theme labels them from `site.network.*`).
- `$this->siteSettings()` for everything else (`address`, `openingHours`, `phone`, `email`, `metaDescription`…):

```php
<?php $settings = $this->siteSettings(); ?>
<?php if ($settings->phone !== null): ?><a href="tel:…"><?= $this->e($settings->phone) ?></a><?php endif ?>
```

They read the database: a 5xx error page should not call them (the default layout checks `$status`). While "hide
from search engines" is on, `robots.txt` disallows everything, the sitemaps answer 404 and every public page is sent
with `X-Robots-Tag: noindex, nofollow`.
`/sitemap.xml` lists every translation of a page with its alternates and `x-default` (the default language's URL).

## Content

Content types (collections) are declared in `config/collections.php`:

```php
Collection::make('news')
    ->route('/news/{slug}')          // the entry URL; {slug} once (a single() collection has none)
    ->archive('/news', 10)           // paginated list: /news?page=2
    ->feed()                         // RSS at /news/feed.xml (needs an archive)
    ->translatable()                 // one entry per locale, linked as translations
    ->sortBy('published_at', 'desc')
    ->fields([Field::text('summary')->required()->max(300), Field::date('event_date')->indexed()]);
```

Field types: `text`, `textarea`, `richText` (sanitized on save; `->minimal()` for a short formatted text such as a
summary, `->max(N)` limits its visible text), `number`, `boolean`, `date`, `datetime`,
`select`, `relation`, `youtube`. `->hierarchical()` gives entries a parent (nested URLs such as
`/about/team`), `->single()` keeps one entry per locale (the home page), and `->single()->plain()` makes it a page
that is always there: its form shows only its fields (no title, status or SEO inputs), its title is the collection's
label and every save publishes it. `Field::richText(…)->tall()` gives a page-long editor and `->help('…')` a hint
under any field (beside the word count of rich text). Collection routes
are registered after every module, so a route in `src/SiteModule.php` always wins over them. An entry or term
cannot take a slug that another route uses right after its prefix: with a route `/news/feed`, a news entry cannot
be called `feed` (the editor is asked for another slug).

An entry is public when its status is published or scheduled and its publication date has passed. Editors
can preview drafts with `?preview=1`.

Dates are stored in UTC. `config/app.php` sets the site's time zone (`'timezone' => 'Europe/Berlin'`, default
`UTC`): the admin shows its dates in it and editors enter the publication date in it, and themes print dates with
`<?= $this->e($this->date($entry->publishedAt, 'd.m.Y')) ?>`. A publication time that does not exist in the zone
(skipped when the clocks go forward) is rejected; a repeated one (when they go back) means the later of the two.
`date()` is for stored instants (`published_at`, `created_at`, ...); format `date`/`datetime` field values with `->format()`, not `date()`, or a date west of UTC prints as the previous day.

Themes render `collections/{collection}/show.php` (or `show.php`) with `$entry` and `$collection`, and
`collections/{collection}/index.php` (or `index.php`) with `$page`. Useful helpers:

```php
<?= $this->e($entry->field('summary')) ?>
<?= $entry->field('body')->html ?>                        // rich text is sanitized on save; print it raw
<?php foreach ($this->entries('news')->latest(3) as $item): ?>
<a href="<?= $this->e($this->entryUrl($item)) ?>"><?= $this->e($item->title) ?></a>
<?php endforeach ?>
<?php $settings = $this->single('settings'); ?>
<a href="<?= $this->e($this->url('content.news.index', [], 'ru')) ?>">…</a>   // a route in another locale
```

The admin's Preview renders the same `show.php` with an `$entry` built from the unsaved form (id 0 for a new entry):
`terms()`, `termsFor()` and `entryUrl()` answer from the form's choices, `editUrl()` is null, and the page has no
canonical URL, language alternates or sharing tags. Read the entry only through `$entry` and these helpers: other
database reads by its id see the saved entry (or nothing for a new one), and lists such as `entries()` show saved
content only.

`->indexed()` fields can be filtered and sorted (`$this->entries('news')->where('event_date', '2026-10-01')`).
After adding, changing or removing one, run:

```bash
docker compose exec -u www-data php php bin/atar collections:sync   # writes database/migrations/…_sync_collections.php
docker compose exec -u www-data php php bin/atar migrate
```

`/sitemap.xml` lists every public entry with its translations; `/robots.txt` comes from `config/content.php`
(`robots`) or a default that hides `/admin`.

### AI agents

The site is AI-ready without any work of yours. `/llms.txt` is a markdown map of the public site (name, description,
languages, a link per public entry and term; after `content.llms_limit` links, default 1000, it points to the
sitemap). Every public entry page also answers at its address plus `.md` (`/news/hello.md`, `/index.md` for the home
page) with the page as clean markdown, a canonical link to the HTML page and `noindex`; old addresses redirect like
the HTML pages. A signed-in agent working in the admin reads `/admin/ai-guide` — the "AI guide" link at the bottom of
the sidebar — which lists the screens, content types and fields its user may use. "Hide from search engines" hides
`/llms.txt` and the `.md` pages along with the sitemap.

## Admin

Every collection gets a menu item in the admin sidebar, under "Content", with an icon: `newspaper` for
collections with an archive, `home` for singles, `file-text` otherwise (taxonomies: `folder-tree` when hierarchical,
else `tag`); `->icon('calendar')` on a `Collection` or `Taxonomy` picks another symbol of
`resources/admin/assets/icons.svg` (Lucide names; the symbol must be in the sprite). Each collection has a list with
language, status, category and search filters (singletons open their form directly). The entry form is generated from
the collection's fields in two columns of tabs: Content and Media on the left, Settings and SEO on the
right (the contacts page adds Address, Contacts and Social). A field goes to the Content tab unless it says otherwise:

```php
Field::gallery('images')->tab('media'),      // next to Content: photos, videos, files
Field::date('event_date')->tab('settings'),  // the right column, under the status and publication date
```

The title, status, publication date, parent page, categories, page address and search engine texts (with the preview
of the share image at the top of the SEO tab) have fixed places. Rich text uses the bundled CKEditor 5 (see "Rich text"). Saving shows a confirmation; saving a form
that someone else saved in the meantime is refused, so reload and redo your change. The form also offers a language
switch ("Copy for translation" copies the entry as a draft; in Chrome, "Translate (machine translation)" also
translates the copy's texts on your computer for you to check and save), Preview (the page as the theme shows it, with the form's
unsaved values, in a tab of its own; nothing is saved), Earlier versions (restore one) and Delete…. Drafts save
themselves about 30 seconds after the last change and when the page is left ("Saved 2 minutes ago" in the bar; a new
entry is created as a draft by its first autosave); published and scheduled entries are only saved with Save, and
the form asks before leaving unsaved changes. An author's autosaves between two saves share one earlier version,
marked "Autosave". A draft with an empty page address takes it from its current title; once published, the address
stays. Saved drafts stay visible to anyone with `entries.{collection}.view` via `?preview=1`. The top bar holds the
interface language menu (only when the site has more than one language; each user picks one of the site's languages
for the admin, the site itself keeps the language of its URLs), the light, dark or device theme, and the user menu
with Sign out. The admin remembers the
content language each user last switched to for the lists and "Add new". The admin's building blocks, rules and
how-tos (screens, field widgets, icons) are in `docs/admin-ui.md` of the AtarCMS repository (not shipped in the
Composer package).

Permissions per collection are `entries.{collection}.{view|create|edit|edit_others|publish|delete}`
(`config/roles.php`). The default author role creates and edits its own drafts; editors publish. A user with
`users.manage` can only assign roles that their own role covers.

Custom field types (`Field::make('color', new ColorType())`) get their admin widget from a module:
return a directory from `Module::adminTemplatePaths()` that contains `fields/color.php` (the type name in
kebab-case). The template receives `$field`, `$name`, `$id` and `$value`. The core admin templates take
precedence, so a module cannot replace a core widget such as `fields/text.php`.

## Rich text

`Field::richText()` fields are edited with the bundled CKEditor 5 (48.5.2, GPL; the "Powered by CKEditor" badge
shows while editing). The toolbar is a full word processor in two rows. The first holds undo and redo, headings,
the theme's styles, bold, italic, font size, font colour, a "More formatting" menu (underline, strikethrough, sub- and
superscript, inline code, font, background colour, highlighters, indentation, a language mark for foreign phrases,
remove formatting) and alignment; the second holds bulleted, numbered (style, start number, reversed) and to-do
lists, links (optionally opening in a new tab), "Photos and files" (pick an image from the library, or upload one in
the same dialog; editors need `media.upload`), tables (with table and cell properties, column resizing and a
caption), videos, "Ready-made blocks", an "Insert" menu (quote, code block, horizontal line, special characters), a
"Tools" menu (find and replace, select all, show blocks), the HTML source and fullscreen. The word count shows under
the editor. Images can also be dropped or pasted; they are uploaded to
the library. Their own toolbar places them in the text, floated left or right, centred or full width, resizes them
(drag the handles, or 25/50/75 %), and sets the alt text, the caption and a link. The interface follows the user's
admin language. Without JavaScript the field is a plain HTML text box; the server cleans the HTML either way (no
scripts, only the allowed elements and attributes, inline styles only for the properties the editor writes).

The theme decides what the editor offers, in `theme.php`:

```php
'editor' => [
    'css' => ['css/editor.css'],   // inside assets/; scope every rule to .ck-content (the editor lives in the admin)
    'styles' => [                  // the Style list: label, element (p, h2, h3, h4 or table), up to 4 classes
        'lead' => ['label' => 'site.editor.lead', 'element' => 'p', 'classes' => ['lead']],
        'striped' => ['label' => 'site.editor.striped_table', 'element' => 'table', 'classes' => ['table-striped']],
    ],
    'templates' => [               // the Ready-made blocks list: label and an .html file inside the theme
        'note' => ['label' => 'site.editor.note', 'file' => 'editor/note.html'],
    ],
    'colors' => [                  // the colour pickers' palette (up to 30); any colour can still be picked
        ['label' => 'site.editor.colors.accent', 'color' => '#3a7bd5'],
    ],
    'fonts' => [                   // the font list (up to 16); saved text keeps only these families
        ['label' => 'site.editor.fonts.serif', 'family' => "Georgia, 'Times New Roman', serif"],
    ],
    'fontSizes' => [               // name => label and one class (up to 8; the name "default" is the editor's)
        'small' => ['label' => 'site.editor.sizes.small', 'class' => 'text-small'],
    ],
    'markers' => [                 // highlighters: label, class, swatch colour, "marker" (background) or "pen" (text)
        'yellow' => ['label' => 'site.editor.markers.yellow', 'class' => 'marker-yellow', 'color' => '#fdfd77', 'type' => 'marker'],
    ],
],
```

Labels are translation keys of the theme's `lang/` files or plain text; two styles may not share a label (the editor
tells its styles apart by it). Without `colors`, `fonts`, `fontSizes` or
`markers` the editor keeps its own (sizes `text-tiny`, `text-small`, `text-big`, `text-huge`; markers `marker-yellow`,
`marker-green`, `marker-pink`, `marker-blue`, `pen-red`, `pen-green`; eight common font stacks); an empty list turns
the font, size or highlighter list off (`'colors' => []` leaves only the picker). The default theme declares six
colours and three fonts and keeps the editor's sizes and markers. Table styles appear in the Style list only while
the cursor is in a table; use a ready-made block for boxes and columns (a `div` with classes). A block's file must be
UTF-8 and its HTML must survive the sanitizer unchanged: classes only, no `style`, `id` or scripts. Saved text keeps only the classes the
theme declares (its styles and the classes in its block files) and those of the editor's features, and only the
theme's fonts; colours are kept as picked. After a theme change, old classes and fonts stay in an entry until it is
saved again.

The editor writes classes wherever it can, so the theme decides the look: style them in `css/editor.css` (under
`.ck-content`) and for visitors in the site's stylesheet (under `.rich-text`, as the default theme's `site.css` does):
the theme's own classes, the font sizes and markers, `text-align-left|center|right|justify`, `indent-1` … `indent-4`,
images (`image`, `image-style-align-left`, `image-style-align-right`, `image-style-full`, `image_resized`), to-do
lists (`todo-list`, `todo-list__label`, `todo-list__label_without-description`, `todo-list__label__description`),
`media`, tables (`table`, `table-style-align-left|center|right`, `table-style-block-align-left|right`,
`ck-table-resized`), and code blocks (`pre`, `code`; `language-php` and the like name the language). Colours, font
families, image and table sizes, table borders and cell properties are inline styles; they apply because the site's
Content Security Policy allows style attributes (`style-src-attr 'unsafe-inline'`, CSP Level 3: current Chrome, Edge,
Firefox and Safari; an older browser shows the text without them). List styles are `type` attributes
(`<ol type="a">`); a theme rule that sets `list-style-type` on every list hides them (the default theme's
`editor.css` lets them through with `revert-layer`).

A `->minimal()` rich-text field (`Field::richText('summary')->minimal()->max(500)`, the default theme's news summary)
has a short editor with bold, italic, font size and font colour; Enter starts a new paragraph. Its stored HTML keeps
only paragraphs, line breaks, bold, italic and spans with a font size class or a colour, also when it is posted
without the editor; `->max(500)` counts the visible characters, not the markup. Print it as HTML
(`<?= $item->field('summary')->html ?>`, as the default theme's `collections/news/index.php` does) or as plain text
(`<?= $this->e($item->field('summary')->text()) ?>`, e.g. for a meta description); check `->isEmpty()` first.

Videos: paste a YouTube (`youtube.com/watch?v=…`, `youtu.be/…`, `/shorts/`, `/live/`, `/embed/`) or Vimeo
(`vimeo.com/…`, `player.vimeo.com/video/…`) link into the video dialog; other sites are refused, start times are
dropped and unlisted Vimeo links are not supported. They are stored as links and shown to visitors as players
(`<iframe class="rich-text-video">`, sized by the theme: the default theme's `site.css` has
`.rich-text-video { width: 100%; height: auto; aspect-ratio: 16 / 9; border: 0; display: block; }`). Their two player
hosts are always allowed by the Content Security Policy; `config/security.php` `frame_src` only lists extra hosts
(maps, other embeds). Editing an entry with a video loads the players from YouTube or Vimeo in the admin.

## Sections

The skeleton's structure is its sections: a hierarchical taxonomy at the root of the site, with articles living in
them.

```php
Taxonomy::make('sections')->hierarchical()->route('/{slug}')->redirectToFirstChild();

Collection::make('articles')->route('/{sections}/{slug}')->taxonomies(['sections']);  // /{section}/{subsection}/{article}
Collection::make('pages')->route('/{slug}');                                          // flat: /{page}
```

- Addresses repeat the nesting: `/{section}`, `/{section}/{subsection}`, `/{section}/{subsection}/{article}`, and
  `/{section}/{article}` for an article of a section without subsections. A partial or wrong chain (`/{subsection}`,
  `/{other-section}/{article}`) redirects (301) to the right address.
- A taxonomy routed at `/{slug}` shares the root with a collection routed there (pages): the page answers first,
  then the section. A page and a top-level section of one language can never take the same slug (the editor is asked
  for another one; a subsection is free, its address starts with its parent's), and module routes (`/news`,
  `/search`, …) reserve their first segment for top-level sections as usual.
- A collection route may start with a placeholder named after one of its `taxonomies()` routed at `/{slug}`:
  that taxonomy becomes required and single-valued for the collection (a single select in the entry form, shaped
  as the tree; sections with subsections are shown but cannot be chosen). An article's address follows its section:
  moving it to another section changes its address (the old one is not found), and an address with another section
  redirects (301) to the right one. A section cannot be deleted while articles take their address from it. A route
  with a placeholder cannot be `hierarchical()`, and no hierarchical collection may live at `/{slug}` next to it.
- `->redirectToFirstChild()` (a routed hierarchical taxonomy): a section with subsections has no page of its own; its
  address redirects (302) to its first subsection by the tree's order, down to the first one without subsections.
  The sitemap lists only the sections that have a page.
- The default theme's `taxonomies/sections/show.php` lists a section's articles, newest first: an article with a
  summary is announced (cover, title, summary, a link), one without a summary is shown in full, so a subsection
  holding one such article reads as a plain page. Breadcrumbs come from `$this->termTrail($term)` (the section and
  its parents, root first); use `$this->entryUrls($page->entries)` (entry id => address, one query for all their
  sections) instead of `entryUrl()` in lists of articles.
- The sections are the main menu. `config/content.php` declares the collections pinned in it:

  ```php
  'main_menu' => ['home', 'news', 'contacts'],   // a single() links to its page, any other to its archive
  ```

  The admin's Structure → Sections screen (`/admin/sections`, permission `taxonomies.manage`) is the menu: the
  sections of one language as a tree (add, edit, translate, delete, nest and reorder them) with Home, News and
  Contacts among them, which only move up and down at the top level (their places are shared by every language).
  "Add section" (below the tree, on the right; the language switch sits in the header) and each section's plus (add a
  subsection to it), pencil and bin icons open a small window (without JavaScript, a page); the bin is greyed out while the
  section has subsections or articles. Sections with subsections fold: a click on one (or on its "2 subsections" before the arrows) shows or hides them, and the
  browser remembers which are open. A name opens its content: a section the list of its articles, Home and Contacts
  their page's form, News its list. Every result (a saved order, a refusal) appears as a message in the corner.
  `$this->menu('main')` returns them in that order, sections with their subsections as children; a section is
  `current` on its page and `ancestorOfCurrent` on its subsections' and its articles' pages, News on its entries'.
- Each section has, per language, a title and a description for search engines (the section form's SEO tab, in the
  window and on the full page; a translation starts without them). Its articles and subsections inherit them:
  - The title is a template: write `{title}` where the page's own name goes, e.g. `{title} — Repair in Moscow`. An
    article without its own title for search engines gets its section's template with its title (`Battery swap —
    Repair in Moscow`); a subsection without its own gets it with its name. If the section has no template, the
    section above it is asked, up to the top level; without any, the page's own title is used. A title without
    `{title}` (`Phone repair`) is used on that section's own page only and is not passed down. An article's own
    title for search engines always wins as it is.
  - The description is passed down as it is: the article's own, else its section's, else the nearest section's above,
    else the site description of the site settings.
  - Each text is resolved on its own (the title may come from the top section, the description from the subsection).
  - With `->redirectToFirstChild()`, a section with subsections has no page of its own, so a title without `{title}`
    shows only while it has no subsections.
- The contacts page shows the contact details, the contact form (see "Contact form and mail") and the site's Google
  map (`$this->contactMap()`: `embedUrl` for the iframe in the page's language, `linkUrl` for "Open in Google Maps" and `wazeUrl` for "Directions in Waze", each or
  null; null without a map). One map serves every language: it is added, changed and removed in the Address tab of the
  contacts page's admin form (`config/content.php` `'contact_page' => 'contacts'`), in a dialog with four ways to enter it: Google's embed code (Share → Embed a map), a full Google Maps link (short
  `maps.app.goo.gl` links cannot be read: open them and copy the full address), an address, or coordinates with a
  zoom. No API key is needed, and `https://www.google.com` is always allowed in frames.

## Taxonomies and tags

Taxonomies are declared in `config/taxonomies.php` and used by collections:

```php
Taxonomy::make('categories')->label('Categories')->hierarchical()->route('/category/{slug}');
Taxonomy::make('tags')->route('/tag/{slug}', 20);        // 20 entries per term page

Collection::make('news')
    ->taxonomies(['categories'])                         // a checkbox list in the entry form
    ->fields([Field::tags('tags')->max(10)]);            // a tag box with suggestions; a flat taxonomy
```

Terms are kept per language (`/admin/taxonomies/{taxonomy}`, permission `taxonomies.manage`), with
"Copy for translation" (and machine translation in Chrome) like entries; "Copy for translation" of an entry carries over the terms that have a
translation. Anyone who can save an entry can create new tags by typing them. Deleting a term removes it
from its entries, except a term that entries take their address from (a section): it cannot be deleted while
they use it, and the admin shows the refusal (see the Sections chapter).

A taxonomy with a `route()` gets a page per term (`taxonomies/{taxonomy}/show.php`, falling back to
`term.php`; variables `$taxonomy`, `$term` and `$page`) and appears in `/sitemap.xml`. The route's first
segment is reserved, so a page can no longer use the slug `category` at the top level. In templates:

```php
<?php foreach ($this->terms($entry, 'categories') as $category): ?>
<a href="<?= $this->e($this->termUrl($category)) ?>"><?= $this->e($category->name) ?></a>
<?php endforeach ?>
<?php $tags = $this->termsFor($page->entries, 'tags'); // entry id => terms, one query for a whole list ?>
<?php $this->entries('news')->withTerm('categories', 'events')->latest(5); ?>
```

## Menus

The theme declares its menu locations in `themes/<name>/theme.php`:

```php
return [
    'menus' => [
        'main' => 'site.menus.main',   // a translation key or plain text, shown in the admin
        'footer' => 'Footer',
    ],
];
```

With `main_menu` in `config/content.php` the `main` location is the sections (see "Sections"); every other location
has its own tree per language in `/admin/menus` (permission `menus.manage`; the screen is not in the sidebar while
there is none; the skeleton's theme declares `main` and `footer`, the links at the bottom of every page). An item links to an entry, a term or a URL (`https://…`, a
path such as `/about`, `#anchor`, `mailto:` or `tel:`); an empty label shows the linked title. Menus are at most three levels deep. Deleting an item deletes its subitems; deleting a
linked entry or term keeps the item, which then stays hidden on the site until it is edited. Items linking to drafts
or to future scheduled entries are hidden (with their subitems) until the entry is public. Site-path links
(`/about`) are not localized — use entry or term items when a link must point to a different URL per language.

In templates, `$this->menu('main')` returns the visible links of the current language (an unknown location is an
error). Each link has `label`, `url`, `newTab`, `current` (it points at the requested page), `ancestorOfCurrent`
and `children`; escape the label and the URL:

```php
<?php foreach ($this->menu('main') as $link): ?>
<a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?>><?= $this->e($link->label) ?></a>
<?php endforeach ?>
```

The default theme renders nested menus with `partials/menu.php`.

## Order of pages and terms

Hierarchical collections (`/admin/content/{collection}/order`) and hierarchical taxonomies
(`/admin/taxonomies/{taxonomy}/order`) have a "Reorder" screen with the same tree editor as menus: drag items by
their handle (the order is saved a moment after the drop, and a message says so), or use the arrow
buttons (they work without JavaScript and save at once). A
page moves only if you may edit it, and a page cannot move to the top level of a `/{slug}` collection when its slug
is reserved there. Someone else's newer order makes your save fail instead of overwriting it; moving a page or term
also makes its edit forms that are already open stale. The editor uses the bundled SortableJS 1.15.7.

## Media

The media library (`/admin/media`, permission `media.upload`; deleting needs `media.delete`) keeps uploads below
`storage/uploads/YYYY/MM/` under random names. `config/media.php` sets the allowed types (JPEG, PNG, WebP, GIF and PDF
by default; SVG is never allowed), the size limit and the pixel limit. Images are re-encoded on upload: camera
metadata such as the GPS position is removed, the photo's orientation is applied and animated GIFs keep their first
frame. PDFs are stored as they are and are always downloaded, never shown inline. Folders (any depth) only sort the
library: they live in the database, so moving a file never changes its address; editors with `media.upload` create,
rename and move them, deleting one (only when it is empty) needs `media.delete`. A file cannot be deleted while an
entry uses it (in a field or in rich text) or while it is the site's image for social
sharing; its page lists where it is used, and the library's "Only unused" filter finds the others. After
removing a collection or a media or rich-text field from `config/collections.php`, run
`php bin/atar media:references:prune`: it releases the files those entries or fields still hold (the entries stay).
Now and then, `php bin/atar media:cleanup --dry-run` lists files nothing refers to any more (left by a delete that
could not remove a file or by an interrupted upload, older than a day); run it without `--dry-run` to delete them.

Collections use media with `Field::image('cover')`, `Field::gallery('images')->max(20)` and
`Field::file('attachment')`; the main photo of an entry of a collection with a route also makes its share image (see
"Share images"). Editors can pick library images for rich text or drop images into it; dropped ones are uploaded to the library.

The theme declares image sizes in `theme.php`:

```php
'images' => [
    'thumb' => ['width' => 480, 'height' => 320, 'fit' => 'crop'],   // cover the box, cropping the centre
    'medium' => ['width' => 800],                                      // scale down to fit ("contain", the default)
    'large' => ['width' => 1600],
],
```

A size is generated on its first request (`/media/{variant}/…`, WebP and the original format), cached in
`storage/cache/media/` and then served by nginx. Images are never enlarged. After changing a size, run
`php bin/atar media:variants:clear` (URLs carry a hash of the settings, so browsers load the new files).

In templates, load the media of a list in one query and print images with `img()`, which outputs a `<picture>` with a
WebP source, `srcset`, `width`/`height` and `loading="lazy"`; `alt` defaults to the image's alternative text in the
current language:

```php
<?php $media = $this->mediaFor($page->entries); ?>
<?php foreach ($page->entries as $item): ?>
<?= $this->img($media->get($item->field('cover')), 'thumb', ['class' => 'cover']) ?>
<?php endforeach ?>

<?php $media = $this->mediaFor($entry); ?>
<?php foreach ($media->list($entry->field('images')) as $image): ?>
<?= $this->img($image, 'medium', ['sizes' => '(max-width: 48rem) 100vw, 48rem']) ?>
<?php endforeach ?>
<?php $file = $media->get($entry->field('attachment')); ?>
<?php if ($file !== null): ?>
<a href="<?= $this->e($this->mediaUrl($file)) ?>" download="<?= $this->e($file->originalName) ?>"><?= $this->e($file->originalName) ?></a>
<?php endif ?>
```

`$this->ogImage($entry, 'large')` returns the absolute URL of the entry's sharing image: its share image, else its main photo in that size (the default layout
prints it as `og:image` through `socialMeta()`, see "Search engines and social sharing"). nginx serves `/uploads/`
and `/media/` itself, where PHP can never run
(`deploy/nginx/atarcms.conf.template`); without those rules PHP serves the same files with the same headers.

## Search

`/search?q=…` (`/ru/search?q=…` in another language) lists the published entries of the page's language that contain
every word of the query, most relevant first (a word in the title counts double), `search_per_page` results per page
(`config/content.php`, default 10). Russian and English word forms match ("новость" finds "новостями", "run" finds
"running"); in other languages words match as typed or by their beginning. Words whose stem is shorter than three
characters are ignored (a MariaDB setting): "она" stems to "он" and cannot be searched.

The default layout has a search form. The theme's `search.php` renders the page with `$query` (the text as typed),
`$results` (each with `->entry`, `->url` and `->description`: the meta description, or the start of the first text
field), `$total`, `$page`, `$perPage`, `$pages` and `$tooShort` (nothing to search for). A theme without `search.php`
has no search page (404). `search.php` passes `'robots' => 'noindex'` to the layout, which prints
`<meta name="robots" content="noindex">` for it.

Every collection with a route is searched: the title and the `text`, `textarea` and `richText` fields of its entries.
Leave a collection out with `->searchable(false)` (the skeleton does this for `home`). Saving an entry updates the
index. After installing on existing content, after changing collections or their fields, or after an upgrade that
changes how a language is stemmed (German and French content indexed before German and French stemming), rebuild
it:

```bash
docker compose exec -u www-data php php bin/atar search:reindex
```

While it runs the index is empty, so `/search` returns nothing for a moment (fine for small sites; run it at a quiet
time).

Until a rebuild, a collection taken out of search stays hidden (only searchable collections are queried), and the text
of a removed field still matches its entries.

## Console commands

Site modules add commands through `Module::commands()`. A command implements `AtarCms\Cli\Command` (only `run()`)
and declares its name and description with an attribute, so `bin/atar list` never constructs it (and never connects
to the database for it):

```php
#[AsCommand('site:report', 'Print the monthly report: site:report [--month=<yyyy-mm>]')]
final class ReportCommand implements Command
{
    public function __construct(private readonly Connection $db) {}

    public function run(Input $input, Output $output): int { /* … */ return 0; }
}
```

Names are lowercase words separated by `:` or `-`; two commands with the same name are an error, and `list` and
`help` are taken by the console. End a description with its usage after `: <name>`, as above: `bin/atar help
site:report` and `bin/atar site:report --help` print that tail as the usage line.

## Production

Set `APP_ENV=production` (a missing or empty value also means production; the value is trimmed and lower-cased),
`APP_DEBUG=false` and `APP_URL` to the public `https://` URL of the site root (no path, query or fragment; the web and
`bin/atar` refuse to boot without a valid one). Only HSTS depends on the `production` environment; the
`__Host-` session cookie is used in every environment except `local` over plain HTTP. Every environment boots the same
way, from the sources: there is no build step and no compiled cache to clear.

The step-by-step installation of a server is `.agents/skills/building-atarcms-sites/references/install.md`: with
Docker (`compose.production.yaml`: Caddy with automatic HTTPS, PHP-FPM, MariaDB; select it with
`COMPOSE_FILE=compose.production.yaml` in `.env`) or without (Debian/Ubuntu, nginx or Caddy or Apache, PHP-FPM,
MariaDB), and shared hosting. The web server configs are in `deploy/`: `deploy/nginx/atarcms.conf.template` (also the
Docker development config), `deploy/caddy/Caddyfile`, `deploy/apache/atarcms.conf` with `public/.htaccess`, the last
resort `deploy/apache/root.htaccess` for hosts whose document root cannot be `public/`, and the PHP-FPM pool
`deploy/php-fpm/atarcms.conf`. They all serve only `public/`, run only `public/index.php` as PHP and send the same
headers as the PHP fallbacks. `php bin/atar doctor --http` checks a running site from the outside and asks the web
server's PHP about itself.

One user owns the site and runs PHP-FPM, composer and `bin/atar` (the pool of `deploy/php-fpm/atarcms.conf`; in Docker
`www-data` with `HOST_UID`/`HOST_GID`). Deploy as that user:

```bash
composer install --no-dev --optimize-autoloader
php bin/atar migrate
php bin/atar doctor
```

Run one deploy at a time: `migrate` takes no lock, so two parallel runs could apply the same migration twice.

`docker/php/production.ini` sizes OPcache for production; `compose.production.yaml` builds the image with it
(`--build-arg ATAR_PRODUCTION=1`). It keeps `opcache.validate_timestamps=1` with `opcache.revalidate_freq=2`, so PHP picks up
changed files within two seconds and a deploy needs no PHP-FPM reload. Reload PHP-FPM anyway when a deploy must switch
all files at the same moment (for example a release that changes many classes at once).

Media files, `robots.txt`, the sitemaps and the feeds run without the session: they never set a cookie and never look
up the visitor. `/sitemap.xml` lists every URL up to `content.sitemap_limit` (50 000); larger sites get a sitemap index
of `/sitemap-entries-{collection}-{n}.xml` and `/sitemap-terms-{taxonomy}-{n}.xml` pages.
