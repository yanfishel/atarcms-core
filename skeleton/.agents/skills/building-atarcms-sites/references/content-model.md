# Content model

Everything here is site configuration (`config/*.php`), read at every boot: a mistake stops the site and
`bin/atar list` prints it. The full builder reference is `references/theme-guide.md` section 5; the skeleton's
`config/collections.php` and `config/taxonomies.php` are commented examples. Keep the skeleton's comments style:
short English comments.

## Languages: `config/locales.php`

```php
return ['default' => 'en', 'locales' => ['en', 'he'], 'admin' => 'linked'];
```

The default language has no URL prefix, the others `/he/...`. Every language needs a theme catalog
`themes/<theme>/lang/<code>.php` with the same keys. Removing a language hides its content (entries stay in the
database).

`admin` is how the admin's languages behave: `'linked'` (the default) makes the top bar's language menu switch the
admin's interface and the content together (Hebrew content, Hebrew right-to-left interface; a language the admin
has no translation for gets an English interface; it speaks English, Russian, Hebrew, German and French); `'separate'` lets each user pick the interface language on the
Profile apart from the content language. Any other value stops the site. Ask the owner only when their editors
want an interface in a language other than the content's.

## Collections: `config/collections.php`

```php
Collection::make('events')                 // name: a-z, 0-9, _ ; never rename once there is content
    ->label('site.collections.events')     // a theme catalog key (or plain text)
    ->route('/events/{slug}')              // entry address; single(): no {slug}
    ->archive('/events', 12)               // list page with 12 per page (optional)
    ->translatable()                       // one entry per language, linked as translations
    ->sortBy('starts_on', 'asc')           // built-in columns or an ->indexed() field
    ->icon('calendar')                     // admin menu icon (optional)
    ->fields([
        Field::date('starts_on')->label('site.fields.starts_on')->indexed(),
        Field::text('meeting_point')->label('site.fields.meeting_point')->max(200),
        Field::select('difficulty', ['easy' => 'site.difficulty.easy', 'hard' => 'site.difficulty.hard'])
            ->label('site.fields.difficulty'),                      // value => label

        Field::richText('body')->label('site.fields.body'),
        Field::image('cover')->label('site.fields.cover')->tab('media'),
    ]),
```

- Patterns: `->single()` (one entry per language: home, contacts), `->plain()` (a single whose form shows only its
  fields), `->hierarchical()` (pages with parents), `->taxonomies(['sections'])` with route `/{sections}/{slug}`
  (articles living in a section), `->feed()` (RSS), `->searchable(false)`,
  `->presets(['privacy' => 'site.presets.privacy'])` (preset pages: `bin/atar migrate` creates each in every
  language at the same address, always published, never deleted; the skeleton's `pages` has `privacy` and
  `accessibility`; themes link them with `presetUrl('privacy')`). A page the owner must always have (privacy policy,
  accessibility statement, terms) is a preset of `pages`, not a single collection; add its label to every catalog
  (`theme:check` reports a missing one). A new preset page starts empty, skipping `->required()` fields until its
  first save in the admin.
- Field types: `text`, `textarea`, `richText` (`->minimal()`, `->tall()`), `number`, `boolean`, `date`, `datetime`,
  `select`, `relation`, `image`, `gallery`, `file`, `youtube`, `tags`, `slides`. Common: `->label()`, `->help()`,
  `->min()`, `->max()`, `->required()`, `->tab('content'|'media'|'settings')`, `->indexed()` (filter and sort in
  themes).
  Check a builder's exact arguments in `vendor/yanfishel/atarcms-core/src/Content/Field/Field.php` (read only).
- `Field::slides('hero')`: a hero slider's slides, each an image (required) with an optional video, title,
  subtitle, link (a site path or http(s)) and button text; `->max()` limits the count (default 10). The video is an
  MP4 or WebM of the media library (up to `max_video_size` in `config/media.php`, 100 MB) that plays instead of the
  picture, which stays its poster and the still fallback; no YouTube or Vimeo in slides. The admin form shows the
  field on a tab of its own named by its label (a plain single then has "Content | Slides" tabs) and edits each
  slide in a dialog. Themes read `list<Slide>` (`imageId`, `videoId`, `title`, `subtitle`, `url`, `button`); slide
  texts are not searched. The skeleton's home page has one, drawn by `partials/hero-slider.php`. The data does not
  dictate a carousel: the same field feeds rotating pictures under one fixed headline, rotating texts over one still
  picture, a split hero, video slides, one background video, a background video under rotating captions and the
  like — the theme guide's "Hero slider" lists the patterns and the rules they keep.
- Demo content fills only `text`, `textarea`, `richText`, `image`, `gallery` and `slides` fields (slides with
  pictures only: GD cannot make videos, so a video hero is shown with the owner's own file). A `->required()` field of
  another type (date, select, number, relation...) makes `demo:seed` fail: add `->required()` to such fields only
  after the demo content, and tell the owner that demo entries leave them empty.
- `->indexed()` added, changed or removed: `bin/atar collections:sync`, then `bin/atar migrate`.
- Before removing a collection with content, ask the owner (its entries disappear from the site). After removing
  a collection or a media field, `bin/atar media:references:prune` releases the media files it held.
- Field labels are theme catalog keys: add them to every `lang/*.php` of the theme. The admin reads them from the
  active theme, so until the new theme is switched on add new keys to the active theme's catalogs too (or the admin
  shows the raw key).

## Taxonomies: `config/taxonomies.php`

- The site's sections: `Taxonomy::make('sections')->label('site.taxonomies.sections')->hierarchical()
  ->route('/{slug}')->redirectToFirstChild()` (the root tree; one per site). Articles: a collection routed
  `/{sections}/{slug}` with `->taxonomies(['sections'])`.
- Tags: `Taxonomy::make('tags')->label('site.taxonomies.tags')->route('/tag/{slug}')` and `Field::tags('tags')`
  in a collection.
- The terms themselves (the actual section names) are content: create them in the admin (Structure, Sections), per
  language. Demo content creates generic ones.

## `config/content.php`

- `'main_menu' => ['home', 'news', 'contacts']`: collections pinned in the main menu among the sections (a single
  links to its page, others to their archive). Every name must be a routed single or have an archive, and it needs
  the root sections taxonomy. A site without sections leaves `main_menu` out: the theme's `main` location is then an
  ordinary menu edited in the admin (Structure, Menus).
- `'contact_form' => 'contacts'`: the single whose page shows and receives the contact form.
- `'contact_page' => 'contacts'`: the plain single whose admin form edits the contact details (address and hours per
  language, phone, e-mail, messengers, social links) and the one Google map; the theme draws the map with
  `$this->contactMap()`. Without it they are written only by `bin/atar settings:import`.
- `search_per_page`, `revisions`, `robots`, `sitemap_limit`.

## `config/forms.php`: add a form

The site's forms besides the contact form (booking, consultation, a callback, a special request): each opens in a
dialog from a link anywhere, has its page `/forms/{key}` for visitors without JavaScript, and lands in the admin's
Messages (and the mail) like the contact form. The skeleton ships `booking`. To add one:

1. In `config/forms.php` add `'callback' => ['recipient' => null, 'fields' => [...]]` (`recipient`: an address for
   this form, null for the site e-mail). Field types: `text`, `email`, `tel`, `textarea`, `date`, `time`, `select`,
   `radio`, `checkbox`; `'required' => true`; `select`/`radio` take `'options' => ['key', …]`; `date` takes `min`/`max`
   as `'today'`, `'+N days'` or `'Y-m-d'`, `time` as `'H:i'`. `name`, `email`, `phone`, `message` are reserved (types
   text, email, tel, textarea); `website` and `form` are no field names; every form needs a required `email` or
   `phone`. A mistake stops the boot with a message naming the form and field: run `bin/atar list` to check.
2. In every `themes/*/lang/*.php`, under `forms.callback`: `title`, `intro`, `subject` (the e-mail's, `{name}` and
   `{site}`), `sent`, `submit`, a label per field and `'{field}.{option}'` per option. `bin/atar theme:check` warns
   about missing ones.
3. In the layout, include the dialog once, `<?= $this->partial('partials/form-dialog', ['key' => 'callback']) ?>`,
   and put a trigger where the design wants it: `<a href="<?= $this->e($this->form('callback')?->url) ?>"
   data-form-dialog="form-callback">…</a>` (the default theme's header has the "Book" link for `booking`).
4. Send one through the dialog and once with JavaScript off (the page), and check it under Messages.

Not available (the backlog): file attachments, prefilling a field from the button, statuses, slots or a calendar,
CAPTCHA, conversion events.

## Site settings (database, not config)

Names, short names, SEO texts, address and hours per language; phone, email, messengers, social links, map, title
pattern, theme colour site-wide. Write them with:

```
bin/atar settings:import --example > storage/tmp/site-settings.json   # the current values in the import shape
# edit the file: every key from the brief, "" for the rest (after demo:seed the current values are demo values;
# a key left out keeps its value), then:
bin/atar settings:import storage/tmp/site-settings.json --dry-run
bin/atar settings:import storage/tmp/site-settings.json
```

Shape: `{"site": {"phone", "email", "whatsapp", "viber", "telegram", "social": {"youtube": "https://..."},
"map": {"address"}, "title_pattern", "theme_color", "hide_from_search", "accessibility_toolbar", "accessibility_toolbar_position", ...},
"locales": {"en": {"site_name", "site_short_name", "meta_title", "meta_description", "address", "opening_hours"}}}`.
The map (one for every language) is one of `{"address"}`, `{"latitude", "longitude", "zoom"}`, `{"link"}` (a full
Google Maps address) or `{"embed"}` (Google's embed code); `null` removes it. `--example` prints a saved map in
its stored shape, `{"query", "zoom"}` (or `{"embed"}`), which the import accepts back unchanged.
Errors name the JSON path and the reason. Logo and icon are uploaded in the admin (Settings) by the owner.
Keep the JSON under `storage/tmp/` (not committed).

## Checking the model

1. `bin/atar list` boots the config (errors name the file and the problem).
2. `bin/atar migrate:status`; after indexed fields `collections:sync` + `migrate`.
3. The admin (`/admin`) shows a menu item per collection with the fields.
