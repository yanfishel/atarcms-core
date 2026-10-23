# AtarCMS theme guide

How a public theme is built: files, the templates the controllers render, the data and helpers they get, `theme.php`,
the content model behind them and the rules every theme keeps. Names below are checked against the code; **core**
means the AtarCMS package (`src/`, `resources/`), **skeleton** means the example site in `skeleton/` and its theme
`skeleton/themes/default` ("Atar"), which is the reference implementation (in a site made from the skeleton it is
`themes/default`, and the core is `vendor/yanfishel/atarcms-core`).

## 1. What a theme is

A theme is a directory `themes/{name}/` of a site. The site picks it with `APP_THEME` (`config/app.php` `'theme'`,
default `default`); nginx serves the theme's `assets/` directly (skeleton `compose.yaml` passes `ATAR_THEME`).

```
themes/{name}/
  theme.php                    # menus, image sizes, editor settings, share image layout (section 4)
  layouts/default.php          # the page shell (any name; templates choose it)
  partials/*.php               # pieces included with $this->partial()
  index.php, show.php          # fallbacks of collection archives and entries
  collections/{collection}/index.php | show.php   # per collection (optional)
  taxonomies/{taxonomy}/show.php                  # per taxonomy (optional); fallback term.php
  term.php                     # term pages of taxonomies without their own template
  search.php                   # the search page (without it /search is a 404)
  form.php                     # the page of a form of config/forms.php (/forms/{key}; section 3, Forms)
  errors/404.php, errors/{status}.php, errors/error.php
  lang/{locale}.php            # the theme's catalog (keys under "site." by convention)
  assets/                      # CSS, JS, fonts, images; URLs through $this->asset()
  editor/*.html                # ready-made blocks of the rich-text editor (theme.php "editor.templates")
```

Templates are **plain PHP without `declare(strict_types=1)`**; inside them `$this` is `AtarCms\View\Template`. Data
passed to a template are local variables (`extract()`); helpers are called as methods (`$this->menu('main')`).

| Template method | Meaning |
|---|---|
| `$this->layout('layouts/default', [...])` | Render this template inside a layout. The layout receives the template's own data, then the array given here, then `$content` (the rendered template). Layouts may themselves call `layout()`. |
| `$this->partial('partials/card', [...])` | Render another template with **only** the data passed (nothing is inherited), return its HTML. |
| `$this->e($value)` | Escape for HTML text and attributes (`null` → `''`; scalars and `Stringable` only). |

There are no blocks/sections: a layout gets named values (`title`, `aside`, …) and `$content`.

**Escaping.** Echo every value through `$this->e()`. Safe to print raw: a rich-text field's `->html` (sanitized on
save), and the HTML-returning helpers `img()`, `canonical()`, `markdownAlternate()`, `hreflang()`, `socialMeta()`,
`siteHead()`; and other templates' output (`partial()`, `$content`). Template names resolve inside the theme
(`ThemeResolver`); the first existing candidate wins.

## 2. Pages: templates tried and data received

| Page | Controller | Templates tried (first found wins) | Variables |
|---|---|---|---|
| Entry of a routed collection | `ContentController::show` (also via `RootController` for `/{slug}` and sectioned routes) | `collections/{collection}/show`, `show` | `$collection` (Collection), `$entry` (Entry), `$seo` (PageSeo) |
| `single()` page (home, contacts) | `ContentController::single` | same | `$collection`, `$entry` (**Entry or null** before it exists), `$seo` (**PageSeo or null**) |
| Archive (`->archive('/news')`) | `ContentController::index` | `collections/{collection}/index`, `index` | `$collection`, `$page` (EntryPage) |
| Term page of a routed taxonomy (tags, sections) | `TermController::show` | `taxonomies/{taxonomy}/show`, `term` | `$taxonomy` (Taxonomy), `$term` (Term), `$page` (EntryPage), `$seo` (PageSeo) |
| Search `/search?q=` | `SearchController` | `search` (404 without it) | `$query` (string), `$results` (list<SearchResult>), `$total`, `$page`, `$perPage`, `$pages` (ints), `$tooShort` (bool) |
| Form page `/forms/{key}` | `FormController::show` (also a refused form without JavaScript) | `form` (404 without it) | `$form` (FormView); never indexed (`X-Robots-Tag`), no canonical |
| Error | `ThemeErrorRenderer` | `errors/{status}`, `errors/error` | `$status` (int), `$reason` (string) |
| Admin preview | `EntryPreviewController` (admin) | the entry's `show` templates | as an entry page, with an unsaved `PreviewEntry` (id 0 when new) |

Notes:
- **Root sections.** With a hierarchical taxonomy routed at `/{slug}` (skeleton `sections`), one root route serves
  pages (`/{page}`), sections (`/{section}/{sub}`) and sectioned entries (`/{section}/{sub}/{article}`); a wrong
  chain redirects (301); a section with children redirects to its first leaf (302) when the taxonomy
  `->redirectToFirstChild()`. Templates do not see this: they get the entry or term page above.
- **5xx pages must not read the database** (the error may be the database). The skeleton layout computes
  `$withSite = ($status ?? 200) < 500` and skips menus, settings and the admin bar when false. Error pages have no
  canonical URL and no language alternates.
- **Preview** renders the same `show` template: `terms()`, `termsFor()`, `entryUrl()` answer from the form,
  `editUrl()` is null, other reads see saved data only; the response is `no-store` + `noindex`.
- `?page=N` pages archives and term pages (404 past the last page); canonical URLs carry `?page=N` from page 2 on.
- A term page's `$page` lists its entries newest first; a section's articles come in the order the editors set
  ("Order articles" on the Sections screen; new and moved-in articles first). `entries(...)->withTerm(...)` keeps
  its own order (`latest()` is by date).

### Objects

**`Entry`** (`AtarCms\Content\Entry`): `id`, `collection` (Collection), `locale`, `translationGroup`, `slug`,
`title`, `status` (EntryStatus), `publishedAt` (?DateTimeImmutable, UTC), `parentId`, `position`, `authorId`,
`createdAt`, `updatedAt`, `seo` (`Seo`: `metaTitle`, `metaDescription`), `data` (raw), `shareImage`, `preset`
(?string, with `isPreset()`: the entry is a configured preset page).
Read fields with `$entry->field('name')` (throws for a field the collection lacks; check
`$entry->collection->field('name') !== null` in shared partials):

| Field type (`Field::…`) | `field()` returns |
|---|---|
| `text`, `textarea`, `select` | `?string` |
| `richText` | `RichText`: `->html` (sanitized, print raw), `->isEmpty()`, `->text()` (plain) |
| `number` / `boolean` | `int\|float\|null` / `bool` |
| `date`, `datetime` | `?DateTimeImmutable` — wall-clock values: format with `->format()`, **not** `date()` |
| `image`, `file` | `?int` media id → `$media->get($id)` |
| `gallery` | `list<int>` media ids → `$media->list($ids)` |
| `slides` | `list<Slide>` (`AtarCms\Content\Field\Slide`): `imageId` (int → `$media->get($slide->imageId)`), `videoId` (`?int`, an MP4/WebM of the media library: check `$media->get($slide->videoId)?->isVideo()`; the picture is its poster), `title`, `subtitle`, `url`, `button` (`?string` each); `[]` without slides |
| `relation` | `list<int>` entry ids → `$this->entries('x')->byIds($ids)` |
| `youtube` | `?YouTubeVideo`: `->embedUrl()`, `->watchUrl()`, `->id` |
| `tags` | throws: read tags with `$this->terms($entry, 'tags')` |

**`Term`**: `id`, `taxonomy` (name), `locale`, `translationGroup`, `slug`, `name`, `parentId`, `position`, `seo`.

**`PageSeo`**: `title` (never empty; the entry's/section's own SEO title, else inherited along the sections with
`{title}` filled, else the title), `description` (?string). Pass both to the layout.

**`EntryPage`**: `entries` (list<Entry>), `number`, `perPage`, `total`, `lastPage()`, `hasPrevious()`, `hasNext()`.

**`SearchResult`**: `entry`, `url`, `description` (meta description or the start of the text).

**`Media`**: `id`, `path`, `originalName`, `mime`, `size`, `width`, `height`, `alt(locale)`, `isImage()`,
`extension()`, `folderId`, `uploadedBy`, `createdAt`, `updatedAt`.

## 3. Helpers

`$this->name(...)`. "Request" helpers use the current request (language, user, page); all are available in every
theme template (registered by `CoreViewHelpers`, `ContentViewHelpers`, `MediaViewHelpers`, `SettingsViewHelpers`,
`MessagesViewHelpers`, `AnalyticsViewHelpers`, plus `asset`).

### URLs and SEO

| Helper | Returns | Example / note |
|---|---|---|
| `url(string $name, array $params = [], ?string $locale = null)` | path in the page's (or given) language | `url('content.news.index')`, `url('search', ['q' => $q])`, `url('content.home.show', [], 'ru')` |
| `entryUrl(Entry $e)` | path | one query per sectioned entry: use `entryUrls()` in lists |
| `entryUrls(list<Entry> $entries)` | `array<int, string>` id → path | batch (sections loaded once) |
| `termUrl(Term $t, int $page = 1)` | `?string` (null: taxonomy has no route) | `termUrl($term, 2)` → `…?page=2` |
| `termTrail(Term $t)` | `list<Term>` root first | breadcrumbs; one read per taxonomy+language per request |
| `pageTrail()` | `list<array{name: string, url: string}>` root first, the page last (absolute URLs, no home page); `[]` without one | the trail of the page's JSON-LD `BreadcrumbList` (entries below an archive, sections or parents; term pages): visible breadcrumbs that match it; use it when it has 2+ items (an ancestor), else the template's own; previews, search and archives have none |
| `canonical()` | `<link rel="canonical">` or `''` | entry, single, archive and term pages only |
| `markdownAlternate()` | `<link rel="alternate" type="text/markdown">` to the page's markdown twin (`/news/hello.md`, `/news.md`, `/tag/releases.md?page=2`, `/index.md` for a single at `/`) or `''`; the page also sends it as a `Link: <….md>; rel="alternate"; type="text/markdown"` header, so a theme without the tag still announces the twin | public entry, single, archive and term pages; none on previews, search or error pages, or while the site is hidden from search engines |
| `hreflang()` | `<link rel="alternate" hreflang>` lines | |
| `socialMeta(?string $title, ?string $description = null, ?Entry $entry = null, ?string $imageVariant = null)` | Open Graph + Twitter tags, then the JSON-LD block (WebSite and the site's organization on the home page, Article, WebPage on other entry pages, CollectionPage on archives and term pages — pass `$entry` on entry pages, BreadcrumbList; only with a canonical URL) | `socialMeta($title, $description, $entry, 'large')` |
| `pageTitle(?string $title = null)` | `<title>` text by the settings' pattern (`{title} — {site}`) | `null` → site name |
| `editUrl(Entry $e)` | `?string` admin edit URL if the user may edit | superseded in the skeleton by `adminBar()` |

Route names: archive `content.{collection}.index`, entry `content.{collection}.show`, feed
`content.{collection}.feed`, term `taxonomy.{taxonomy}.show`, search `search`, contact form `messages.send`, a form's
page and endpoint `forms.show.{key}` and `forms.send.{key}`.

### Content

| Helper | Returns |
|---|---|
| `entries(string $collection)` | `ContentQuery` over public entries of the page's language |
| `single(string $collection)` | `?Entry` of a `single()` collection in the page's language |
| `presetEntry(string $preset)` | `?Entry`: the preset page (`Collection::presets()`, by its slug) in the page's language; null while it is missing or not public |
| `presetUrl(string $preset)` | `?string` its path (`presetUrl('privacy')` → `/ru/privacy`); null like `presetEntry()`: hide the link then |

Both preset helpers read every preset page of the language in one query per request.

`ContentQuery` (every method returns a new query): `where($indexedField, $value)`, `orderBy($column, 'asc'|'desc')`
(columns `Collection::SORTABLE`: `published_at`, `title`, `slug`, `position`, `created_at`, `updated_at`, or an
`->indexed()` field), `childrenOf(?Entry)` (hierarchical collections), `withTerm($taxonomy, Term|string $slug)`,
`withinTerm($taxonomy, Term)` (the term **and its descendants**), then `get($limit = 100, $offset = 0)`,
`latest($n)`, `first()`, `count()`, `page($number, ?$perPage)` (EntryPage), `byIds(list<int>)`.

```php
<?php $articles = $this->entries('articles')->withinTerm('sections', $section)->latest(3); ?>
```

### Terms

| Helper | Returns |
|---|---|
| `terms(Entry $e, string $taxonomy)` | `list<Term>` of one entry |
| `termsFor(list<Entry> $entries, string $taxonomy)` | `array<int, list<Term>>` entry id → terms (one query) |
| `termChildren(string $taxonomy, ?Term $parent = null)` | `list<Term>` in tree order (null = top level), page language, read once per request |

### Menus

`menu(string $location)` → `list<MenuLink>`: `label`, `url`, `newTab`, `current`, `ancestorOfCurrent`, `children`
(list<MenuLink>). An unknown location is an error. With `content.main_menu` the `main` location is the sections tree
with the configured collections pinned among the top level (a single links to its page, others to their archive),
ordered on the admin's Sections screen; other locations are edited under Structure → Menus.

### Media

| Helper | Returns |
|---|---|
| `mediaFor(Entry\|list<Entry> $entries)` | `MediaSet`: `get(?int $id)` → `?Media`, `list(list<int>)` → `list<Media>` (one query for all) |
| `img(?Media $m, string $variant, array $attributes = [])` | `<picture>` (AVIF and WebP `<source>`s + `<img>`, `srcset` of the variant's family, `width`/`height`, `loading="lazy"`); `''` for null |
| `mediaUrl(Media $m, ?string $variant = null)` | original URL, or a variant's |
| `ogImage(Entry $e, ?string $variant = null)` | absolute sharing image URL or null |

`img()` attributes: `alt` (default: the media's alt text in the page language, else the default language, else
`''`), `class`, `id`, `title`, `sizes`, `loading`, `decoding`, `fetchpriority`, and the option `sources` (art
direction, below). Variants are theme.php `images`.

```php
<?php $media = $this->mediaFor($page->entries); ?>
<?= $this->img($media->get($item->field('cover')), 'card', ['sizes' => '(max-width: 40rem) 100vw, 24rem']) ?>
```

Formats: a picture always has a WebP `<source>` (none for a WebP original) and the `<img>` in the original format.
Where PHP's GD writes AVIF (`bin/atar doctor`: `gd.avif`; `'avif' => false` in `config/media.php` turns it off) an
AVIF `<source>` comes first, also for WebP originals; elsewhere nothing changes and `.avif` URLs answer 404.

Art direction: `sources` maps media queries, in order, to another variant of the same picture or to a
`[Media, variant]` pair (another picture). Each adds `<source media="…">` elements before the default ones — AVIF,
WebP and the original format, each with the srcset of that variant's family and its own `width`/`height` (so the
browser reserves the right box); `sizes` is the one given, else that variant's width. A pair whose `Media` is null
(an empty field) adds nothing, so a field can be passed as it is; an unknown variant or a file that is not an image
throws, like the main variant. The `<img>`'s `loading`/`fetchpriority` apply to whichever source the browser picks.

```php
<?php // The same picture as a taller crop for phones' slide box (theme.php: 'hero_phone' => ['width' => 800, 'height' => 640, 'fit' => 'crop']). ?>
<?= $this->img($photo, 'hero', ['sizes' => '100vw', 'sources' => ['(max-width: 40rem)' => 'hero_phone']]) ?>
<?php // Another picture on phones, when the editor chose one. ?>
<?= $this->img($photo, 'hero', ['sources' => ['(max-width: 40rem)' => [$media->get($entry->field('phone_photo')), 'hero_phone']]]) ?>
```

### Site settings

| Helper | Returns |
|---|---|
| `siteHead()` | `<head>` tags: icons (site icon or the AtarCMS logo), manifest, theme colour, verification metas |
| `siteName()` | the site name of the page language (setting → theme `site.name` → `app.name`) |
| `siteLogo()` | `?Media` → `img($logo, 'logo')` |
| `siteSettings()` | `Settings` of the page language |
| `socialLinks()` / `messengers()` | `list<SocialLink>` (`network`, `url`) in fixed order; messengers carry chat URLs (wa.me, viber://, t.me) |
| `contactMap()` | `?MapView`: `embedUrl` (the Google Maps iframe in the page language), `linkUrl` (`?string`, "Open in Google Maps"), `wazeUrl` (`?string`, "Directions in Waze": to the point, else a search of the address; null for an embed without a centre); null without a map or without `content.contact_page` |

`Settings` fields: `siteName`, `siteShortName`, `metaTitle`, `metaDescription`, `address`, `openingHours` (per
language); `phone`, `email`, `whatsapp`, `viber` (`+` digits), `telegram` (no `@`), `socialLinks`, `messengers`,
`logoId`, `iconId`, `ogImageId`, `themeColor`, `titlePattern`, `hideFromSearch`, `yandexVerification`,
`googleVerification`, `map` (`?GoogleMap`; draw it with `contactMap()`), `accessibilityToolbar` (bool,
true unless switched off in Site settings → General), `accessibilityToolbarPosition` (`top-start`, `bottom-start`,
`top-end` or `bottom-end`, default `bottom-end`; `Settings::TOOLBAR_POSITIONS`); `accent()` is the site colour, the
`AdminAccent` preset nearest to `themeColor` (green without one) that colours the default icon. All user text: escape.
Network names (`Settings::SOCIAL_NETWORKS`, the order of the admin and of `socialLinks()`): telegram_channel, youtube,
instagram, facebook, x, tiktok, pinterest, linkedin, vk, rutube, dzen, ok; messengers (`Settings::MESSENGERS`):
whatsapp, viber, telegram.

### Languages and dates

| Helper | Returns |
|---|---|
| `t(string $key, array $params = [])` | translation (ICU: `{count, plural, one {…} other {…}}`); theme catalog wins over core |
| `hasTranslation(string $key)` / `label(string $label)` | bool / a key or plain text translated (collection and field labels) |
| `locale()` | page language code |
| `langAttrs(string $locale)` | `' lang="he" dir="rtl"'` (leading space) → `<html<?= $this->langAttrs($this->locale()) ?>>` |
| `languageName(string $code)` | the language in itself ("Русский") |
| `alternates()` | `array<string, string>` locale → absolute URL of this page in the languages it exists in (entries: their translations); `[]` when fewer than two (error pages, an entry without translations) |
| `date(?DateTimeInterface $t, string $format = 'Y-m-d H:i')` | in the site time zone (stored instants only) |
| `readableDate(?DateTimeInterface $t, bool $withTime = true)` | "Oct 4, 2026, 01:41" / "Oct 4, 2026" in the page language |
| `timezone()` | the site zone name |

Right-to-left messages: `Translator` wraps string parameters in FSI/PDI isolates (U+2068/U+2069) for RTL languages.

### Forms, admin bar, security

| Helper | Returns |
|---|---|
| `form(string $key)` | `?FormView` of a form of `config/forms.php` (null for an unknown key): `key`, `action` (the POST URL), `url` (the form's page), `token`, `tokenField` (`_form`), `honeypot` (`website`), `fields` (`list<FormFieldView>`), `formError`, `sent`, `onItsPage` (this request is the form's page), `hasErrors()`, `field($name)` |
| `contactForm()` | `?FormView` of the contact form (null without `content.contact_form`); its `url` is the contact page |
| `adminBar(?Entry $entry = null, ?Term $term = null)` | `list<AdminBarAction>` (`kind`: dashboard/add/edit/messages, `url` (null = inactive, the label says why), `label`, `icon` (Lucide name), `badge`); empty for visitors and stateless routes |
| `user()` / `can(string $permission)` | `?User` / bool |
| `nonce()` | the CSP nonce of this response (for the one inline bootstrap script) |
| `consentHead()` | the loader `<script>` of the analytics counters and advertising pixels (core `consent.js`, with the nonce) for the end of `<head>`; it also adds the configured services' hosts to this response's CSP; `''` without a service (Site settings → Integrations) or for a signed-in user |
| `consent()` | `?ConsentView` for the cookie banner: `statistics` (`list`: `metrica`, `google_analytics`), `advertising` (`list`: `meta`, `google_ads`, `vk`, `tiktok`), `services` (both), the ids (`metrica`, `googleAnalytics`, `metaPixel`, `googleAds`, `vkPixel`, `tiktokPixel`), `webvisor` (bool: name session replay), `text` (the owner's text of this language or null for the theme's), `privacyUrl` (the preset page `privacy`, or null), `state` (`?ConsentChoice` with `statistics` and `advertising`, each `?bool`, from the `atar_consent` cookie: informational, never vary the markup by it); null without a service or for a signed-in user |
| `asset(string $path)` | `/theme-assets/{path}?v={hash}` (`app.assets_url`) |
| `csrfToken()` / `csrfField()` | **avoid on public pages**: they create a session (a cookie for every visitor) |

**Forms.** The site defines its forms in `config/forms.php` (booking, consultation, a special request; key =>
`recipient` and `fields`, see the file's comment); the contact form is built in (`contact`: name, email, phone,
message). A `FormFieldView` has `name`, `id` (`{form}-{field}`: unique on a page with several forms), `errorId`
(`{id}-error`), `type` (text, email, tel, textarea, date, time, select, radio, checkbox), `required`, `options`
(option keys), `value` (what was typed, after a refusal), `error` (a translation key) with `params`, and `min`/`max`
(date/time bounds as attribute values for the site's today). Every value is the visitor's text: escape it. The
texts come from the theme catalog by convention: `forms.{key}.title`, `.intro`, `.subject` (the e-mail's, `{name}`
and `{site}`), `.sent`, `.submit`, `.{field}` and `.{field}.{option}` (a dotted key next to the field's:
`'service' => 'Service', 'service.haircut' => 'Haircut'`); `theme:check` warns about missing ones and wants
`form.php` while a form has its own page.

**Form flow** (skeleton `partials/form-fields.php`, used by the contact form, `form.php` and the dialog): POST to
`$form->action` with the fields, the hidden token and the honeypot input (hidden by CSS, `tabindex="-1"`,
`autocomplete="off"`, never `type="hidden"`). No session: the routes are public forms (`Route::publicForm()`). Each
field has its label, the input of its type (`<select>` with an empty first choice, a radio group in a `<fieldset>`,
native `<input type="date|time" min max>`), `aria-describedby` its error paragraph (always there, empty without an
error; `aria-invalid="true"` with one). Without JavaScript a refusal shows the page again (422/429) with the typed
values and `$this->t($field->error, $field->params)` / `$this->t($form->formError)`; success redirects to the form's
page with `?sent=1` (`$form->sent`; the contact form: `?sent=1#contact`).

**Form dialog** (skeleton `partials/form-dialog.php`, `assets/js/forms.js`): the layout includes
`$this->partial('partials/form-dialog', ['key' => 'booking'])` once per form; a trigger is a plain link anywhere,
`<a href="<?= $this->e($this->form('booking')?->url) ?>" data-form-dialog="form-booking">` (without JavaScript it
opens the page). The contact form is no dialog form (its endpoint answers with the contact page, never JSON): the
partial draws nothing for `contact`. It draws `<dialog class="form-dialog" id="form-{key}" aria-labelledby>` (nothing
on the form's own page: `onItsPage`): a header with the title and a close button inside `<form method="dialog">`, a
scrolling body (intro, the form error `role="alert"`, the fields, an empty `role="status"` for the thanks), a footer
with the submit button, and `<script type="module" src="…js/forms.js">` (a module runs once however often it is
included). `forms.js` opens it with `showModal()` (focus to the first field, back to the link on close), posts with
`fetch` and `Accept: application/json`: `{ok, message}` or `{ok: false, errors: {field: text}, form: text|null, token}`
with the texts already translated; it writes them with `textContent`, sets `ariaInvalid`, focuses the first invalid
field, takes the fresh token, keeps the typed values; on success it hides the fields and shows the thanks. The
dialog's texts `forms.close`, `forms.sending`, `forms.network_error` come from `data-` attributes. Full screen on
phones; its open animation stops under `prefers-reduced-motion` and `a11y-still`.

**Search**: a GET form to `url('search')` with `q`.

**Per-request memo**: helpers that read shared data (menus, the taxonomy terms, settings, unread count) are memoised
per request; core helpers do it through `RequestMemo::through($request, $key, fn)`.

## 4. `theme.php`

Returns an array with only these keys (`ThemeConfig`; any other key fails the boot):

```php
return [
    'menus' => ['main' => 'site.menus.main', 'footer' => 'site.menus.footer'],  // location => label (key or text)
    'images' => [
        'thumb' => ['width' => 480, 'height' => 320, 'fit' => 'crop'],  // crop: cover the box, centre
        'large' => ['width' => 1600],                                     // contain (default): fit, never enlarged
    ],
    'editor' => [/* see below */],
    'share_image' => ['layout' => 'band'],                                // band | bottom | panel
    'direction' => 'ltr',                                                 // optional: left to right only (below)
];
```

| Key | Validated at boot |
|---|---|
| `menus` | location names `/^[a-z][a-z0-9_]{0,31}$/`, a label each |
| `images` | names `/^[a-z][a-z0-9_]{0,31}$/`; keys only `width` (required), `height`, `fit` (`contain`/`crop`), `quality`; sizes ≤ 4096; reserved names `admin`, `icon_32`, `icon_180`, `icon_192`, `icon_512` |
| `editor` | `css` (files inside `assets/`, every rule scoped to `.ck-content`), `styles` (label, element p/h2/h3/h4/table, ≤ 4 classes; labels distinct), `templates` (label + `.html` file that must survive the sanitizer), `colors`, `fonts`, `fontSizes`, `markers` (absent: CKEditor defaults; `[]`: off) |
| `share_image` | only `layout` |
| `direction` | `both` (default: the theme lays out right to left too) or `ltr`: a theme kept left to right only, such as a purchased HTML template used as it is; `theme:check` then allows physical CSS sides, and refuses the theme while `config/locales.php` has a right-to-left language |

Variants are generated on first request (`/media/{variant}/…`) and cached; after changing a size run
`bin/atar media:variants:clear`; after changing `share_image` run `bin/atar share-images:rebuild`. `img()` builds
`srcset` from the requested variant and theme variants of the same family (real widths, no upscaling).

Rich text in the site: style the editor's classes under `.rich-text` (the skeleton's `site.css` styles every class
`RichTextTheme::classes()` lists; `SkeletonEditorThemeTest` enforces it for the skeleton) and the same under
`.ck-content` in the editor CSS. Video embeds are `<iframe class="rich-text-video">`.

## 5. The content model a theme relies on

- `config/collections.php`: `Collection::make('news')->label(…)->route('/news/{slug}')->archive('/news', 10)
  ->feed()->translatable()->fields([...])`; `->single()` (one entry per language, route without `{slug}`),
  `->plain()` (singles whose form shows only fields; title = the label), `->hierarchical()`, `->taxonomies([...])`,
  `->sortBy()`, `->searchable(false)`, `->shareImage(...)`, `->icon()`, `->presets(['privacy' => 'site.presets.privacy'])`
  (preset pages: slug => label; a route ending in `/{slug}`, not hierarchical). Fields: `Field::text/textarea/richText/
  number/boolean/date/datetime/select/relation/image/gallery/file/youtube/tags/slides(name)` with `->label()`,
  `->help()`, `->min()`, `->max()`, `->required()`, `->tab('content'|'media'|'settings')`, `->minimal()`/`->tall()` (rich text),
  `->indexed()` (filter/sort; then `bin/atar collections:sync` and `migrate`). `slides` is a hero slider's slides
  (each an image with an optional title, subtitle, link and button text; `->max()` default 10): the admin form gives
  it a tab of its own named by its label and edits each slide in a dialog; its texts are not searched.
- `config/taxonomies.php`: `Taxonomy::make('tags')->route('/tag/{slug}')`; `->hierarchical()`; the root tree
  (`->route('/{slug}')->hierarchical()`) is the site's sections; `->redirectToFirstChild()`.
- `config/content.php`: `main_menu` (collections pinned in the `main` menu), `contact_form` (the single whose page
  shows the form), `contact_page` (the plain single whose admin form edits the contact details: address, hours, phone,
  e-mail, messengers, social links and the one Google map that `contactMap()` draws), `search_per_page`, `revisions`,
  `robots`, `sitemap_limit`.
- `config/forms.php`: the site's forms besides the contact form (key => `recipient`, `fields`), each with its page
  `/forms/{key}` and a dialog in the theme (section 3, Forms); a `contact` entry replaces the contact form's fields.
- A field a design needs (e.g. the skeleton home page's `lead` and `hero`) is added in `config/collections.php`; the
  admin form shows it automatically (labels are theme catalog keys such as `site.fields.lead`). Templates read it with
  `$entry->field('lead')`.
- Preset pages: ordinary entries of their collection (title, fields, SEO, translations, revisions) that
  `bin/atar migrate` creates in every language of the collection (titled by the label, published, empty; a page
  already at the address is adopted), at the same address everywhere (`/privacy`, `/ru/privacy`). They are always
  published, keep their address and cannot be deleted; no other page or top-level section takes the slug. The page
  template of their collection renders them; link them with `presetUrl()` (never a hard-coded path), and hide the link
  while it is null (a language added before the next `migrate`). `demo:seed` fills the skeleton's two with templates.
  A created preset skips its collection's `->required()` fields until its first save in the admin (it starts empty),
  so templates must cope with empty fields; `theme:check` reports a preset label key missing from a catalog.
- Skeleton collections: `home` (single, plain: `lead`, `hero` (slides), `intro`), `contacts` (single, plain: `body`), `news`
  (archive + feed: `summary`, `body`, `cover`, `images`, `attachment`, `video`, `tags`), `articles`
  (`/{sections}/{slug}`: `summary`, `body`, `cover`, `images`, `attachment`, `video`), `pages` (`/{slug}`, with the
  preset pages `privacy`, the privacy policy, and `accessibility`, the accessibility statement); taxonomies `sections`
  (root tree) and `tags`.

## 6. Rules

- **CSP** (`SecurityHeadersMiddleware`): `script-src 'self' 'nonce-…'` — no inline scripts except one nonce'd
  bootstrap (`<script nonce="<?= $this->e($this->nonce()) ?>">`); `style-src 'self'` — no `<style>`, no `style=`
  in templates (sanitized rich text may carry style attributes); `frame-src 'self'` + YouTube-nocookie, Vimeo,
  Google Maps (`https://www.google.com`; + `security.csp.frame_src`); `img-src 'self' data: blob:`
  (+ `security.csp.img_src`); `connect-src 'self'` (+ `security.csp.connect_src`);
  `form-action 'self'`. No network URLs (fonts, scripts, styles all from the theme). The hosts of the analytics
  counters and advertising pixels join the policy only on pages that print `consentHead()`, each service's only while it
  is set (never in the admin or for signed-in users; see Cookie banner).
- **Direction**: `<html lang dir>` from `langAttrs($this->locale())`; CSS with **logical properties only**
  (`margin-inline-start`, `inset-inline-end`, `border-end-start-radius`, `text-align: start`); a sideways transform
  or mirror only with a `:dir(rtl)` rule. The editor's explicit `*-align-left|right` classes stay physical.
- **Accessibility**: skip link, landmarks (`header`, labelled `nav`s, `main`, labelled `aside`, `footer`), one `<h1>`,
  `aria-current` on current links, visible `:focus-visible` rings, images with `alt` (decorative card images
  `alt=""`), names for icon-only links (`aria-label` + `title`), 44px targets, `prefers-reduced-motion`.
- **Performance**: in lists use `entryUrls()`, `termsFor()`, `mediaFor($list)` instead of per-item helpers; no queries
  in loops; give `img()` a `sizes`; load the first big picture (the page's LCP) `loading="eager"` +
  `fetchpriority="high"` + `decoding="auto"`, every other one lazy. A big picture the browser finds late — a
  `<video>`'s `poster`, a CSS background — gets a `<link rel="preload" as="image" href="…" fetchpriority="high">` in
  the `<head>` instead, with exactly the URL the page uses (the skeleton: the layout's `preloadImage`); a picture
  in the markup needs none (the preload scanner finds it, with its AVIF/WebP and art-directed sources).
- **JavaScript is optional**: pages work without it (`<details>` menus); JS-only controls are hidden unless the
  bootstrap added `.js` to `<html>`; theme scripts are ES modules from `assets/`.
- **Vendored files** (fonts, icon sprites, libraries) are byte-exact with their licence, a version in the file name for
  ESM imported by other modules, `.gitattributes -text` and a pinned sha256 test.
- **Catalogs**: a `lang/{locale}.php` for every site language (the skeleton theme speaks en, ru, he, de and fr; the
  skeleton site's content is en, ru, he), all with identical keys (`theme:check`; `SkeletonCatalogTest` for the
  skeleton); Hebrew uses the masculine imperative; no prefix letter
  glued to a `{param}`.
- **Sessions**: public pages never call `csrfField()`/`csrfToken()` (a session cookie for every visitor).

### Tooltips

The skeleton theme turns every `title="…"` into a styled tip with `assets/js/tooltip.js`, a byte-identical copy of the
admin's `resources/admin/assets/tooltip.js` (a test pins the copy; no library). Keep writing `title`s (with an
`aria-label` on icon-only links and buttons); the script does the rest: one shared element `#tooltip` (a manual
popover in the top layer, so `overflow` never clips it and it shows above an open `<dialog>`), shown on hover with a
mouse or pen (300 ms; at once when moving on from another tip; never on touch) and on keyboard focus. While it shows,
the trigger's title waits in `data-tooltip-title` (no second, native box) and the trigger carries
`data-tooltip-anchor`; a trigger named only by its title gets it as `aria-label` for that time. Escape, leaving,
pressing, other keys but Tab, a menu (`<details>`) opening and scrolling hide it; dismissed under the pointer, the
title stays parked until the pointer leaves. The tip fades in and hides at once (keep the transition on
`:popover-open` only: a fade-out would lose its anchor). Without the Popover API or CSS anchor positioning
(`anchor-name` and `position-area`), or without JavaScript, nothing happens and the browser shows the native title.
Never name an icon by an SVG `<title>` (browsers show it as their own box): use `aria-label`.

A theme that loads it (`<script type="module" src="<?= $this->e($this->asset('js/tooltip.js')) ?>"></script>`)
needs these five rules (logical properties only; colours from its own tokens):

```css
[data-tooltip-anchor] { anchor-name: --tooltip; }
#tooltip { position-anchor: --tooltip; position-area: block-start; position-try-fallbacks: flip-block, flip-inline; position-visibility: anchors-visible; inset: auto; inline-size: max-content; max-inline-size: min(18rem, calc(100vw - 2rem)); margin: .25rem; padding: .25rem .5rem; overflow: visible; border: 0; border-radius: .375rem; background: var(--ink); color: var(--paper); font: 400 .8125rem / 1.35 var(--font); text-align: start; unicode-bidi: plaintext; pointer-events: none; opacity: 0; }
#tooltip:popover-open { opacity: 1; transition: opacity .12s; }
@starting-style { #tooltip:popover-open { opacity: 0; } }
@media (prefers-reduced-motion: reduce) { #tooltip:popover-open { transition: none; } }
```

To keep the native title on an element (and everything inside it), add `data-no-tooltip`; CKEditor's content,
`<option>` and `<iframe>` titles are skipped anyway. A trigger whose tip should open elsewhere can move it with a
`:has()` rule, as the skeleton's floating admin bar does (`body:has(.admin-bar [data-tooltip-anchor]) #tooltip {
position-area: inline-end; }`).

### Accessibility toolbar

The skeleton theme draws its own accessibility toolbar (third-party overlays inject styles and scripts that the CSP
refuses). Pieces:

- `partials/a11y.php` (rendered by the layout after the admin bar while `$this->siteSettings()->accessibilityToolbar`
  is true; never on a 5xx page) with `data-a11y-position` on its root `.a11y` (the setting's logical corner): a round
  `<button class="a11y-trigger" aria-expanded aria-controls="a11y-panel">` fixed in that corner (logical insets, so
  right-to-left pages mirror it; a start-side button beside the admin bar's column, a bottom one above its row on
  phones, a top one below the header and under its open menus), and the panel `<section role="dialog"
  aria-labelledby tabindex="-1" hidden>` towards the page (above a bottom button, below a top one, on the same side):
  groups as `<fieldset>`/`<legend>`, each option a `<button type="button" aria-pressed data-a11y-option="…">`, the text size as −/+ buttons (`data-a11y-size`,
  `aria-disabled` at the limits, never `disabled`) around an `<output>` with the step ("115%"), "Reset all" and the
  statement's link; plus the reading guide's band `.a11y-guide-band`. Hidden without `.js`.
- `assets/js/a11y.js` (a module, loaded only with the toolbar): each option toggles a class on `<html>`:
  `a11y-text-1..3` (115/130/150% root size), `a11y-spacing`, `a11y-font`, `a11y-contrast`, `a11y-grayscale`,
  `a11y-invert`, `a11y-links`, `a11y-focus`, `a11y-cursor`, `a11y-still`, `a11y-guide`, `a11y-no-images`; the list
  is stored as JSON in `localStorage` `atar-a11y`. The panel is not modal (no focus trap): opening focuses it, Tab
  moves on past it, Escape (on `window`, after `tooltip.js`, so an Escape that hides a tip goes no further), the close
  button, the button or a click elsewhere close it; Escape and the close button give the focus back to the button
  when it was in the panel (a click elsewhere leaves it where it went). The reading guide's
  position is `--a11y-guide-y` in a constructable stylesheet (`new CSSStyleSheet()`, `document.adoptedStyleSheets`,
  `replaceSync` once a frame on `pointermove`): no `style` attribute. The guide is hidden for touch-only use.
- Before the first paint the layout's one nonce'd bootstrap also applies the stored classes (only the known names,
  the same list as `a11y.js`, pinned by a test); with the toolbar switched off it skips them, since nothing could undo
  them.
- `assets/css/site.css`: the toolbar's look and each class's rules (logical properties only). High contrast replaces
  the colour tokens for light and both dark cases; greyscale and inverted colours put `filter` on `.site-header`,
  `.site-main` and `.site-footer`, never on `<html>` or `<body>` (a filter would become the containing block of the
  fixed toolbar, admin bar and tooltip); the big cursor uses `assets/cursors/arrow.svg` and `pointer.svg`.
- The statement is the preset page `accessibility` of `pages`: the layout links `presetUrl('accessibility')` from the
  panel while it is not null, and the footer links both preset pages (privacy policy, accessibility statement) by
  their titles (`presetEntry()`) with or without the toolbar.

Another theme copies the partial, the script, the cursors, the CSS section "Accessibility toolbar", the bootstrap's
`atar-a11y` part, the catalog keys `site.a11y.*` and the icons `accessibility`, `minus`, `plus`, `rotate-ccw`, `x`;
its colour tokens and its page wrappers take the place of the skeleton's in the contrast and filter rules.

### Chat button

The contact page's form has "Show a chat button" after the messengers (off by default; `$this->siteSettings()->
chatButton`). A theme that offers it shows a button while the switch is on and `messengers` is not empty: the
skeleton's `partials/chat.php` is a round button in the bottom end corner (above the accessibility toolbar's button
when that sits there, `data-chat-raised`; the bottom start corner is the cookie banner's) whose `<details>` panel
lists the messengers (`SocialLink`: `network`, `url`) with their brand icons and names (`site.network.*`); with one
messenger the button is its link, named `site.chat.via`. The links open the messengers themselves, so there is no
script to load, no cookie and nothing to add to the Content Security Policy. Without JavaScript the `<details>`
opens and closes as usual; `js/theme.js` also closes it on Escape and on a click elsewhere (`details.chat`, like the
header dropdowns).

Another theme copies the partial, the CSS section "Chat button", the `details.chat` selector of `theme.js`, the catalog
keys `site.chat.*` and the icon `message-circle` (with the brand sprite it already has for the contacts).

### Cookie banner

With an analytics counter or an advertising pixel in Site settings → Integrations visitors must allow cookies before
they load, per category: "Statistics" (Yandex Metrica, optionally with Webvisor, and Google Analytics 4) and
"Advertising" (Meta Pixel, Google Ads, VK Ads, TikTok Pixel). The core does the deciding and the loading; the theme
draws the banner (nothing at all without a service, nothing for signed-in users, who are not counted):

- `layouts/default.php`: `<?= $this->consentHead() ?>` last in `<head>` (not on a 5xx page), and
  `$consent = $this->consent()`; while it is not null the partial below after the accessibility toolbar (early in the
  reading order) and, in the footer, `<button type="button" aria-controls="consent" data-consent-open>` labelled
  `site.consent.settings` ("Cookie settings"), shown only under `.js`.
- `partials/consent.php`: `<section class="consent" id="consent" aria-labelledby="consent-title" tabindex="-1" hidden
  data-consent>` (a region, never a modal: no focus trap, the page stays readable; its layer lies over every other
  one of the theme, the toolbar, the chat button and the admin bar included, only the skip link above it) with a heading
  (`site.consent.title`), the text, the privacy link (`$consent->privacyUrl`, when not null) and the buttons.
  - Statistics only (`$consent->advertising === []`): two buttons of equal weight, `data-consent-accept` ("Accept") and
    `data-consent-decline` ("Decline"). The text is `$consent->text` (the owner's, plain text: escape it), else
    `site.consent.text`, or `site.consent.text_replay` when `$consent->webvisor` (session replay must be named), with
    `{services}` = `site.consent.services.both` or `.metrica`/`.google_analytics`.
  - With advertising pixels: the title `site.consent.title_ads` (`title_ads_only` without statistics), the text
    `site.consent.text_ads`, `text_ads_replay` (Webvisor) or `text_ads_only`, with `{statistics}` and `{advertising}`
    the services' names (`site.consent.services.*`) in comma lists; three buttons of equal weight, "Accept all"
    (`data-consent-accept`), "Decline" (`data-consent-decline`) and "Choose" (`data-consent-choose`, `aria-expanded`,
    `aria-controls` = the panel); after them the panel `<div id="consent-choice" hidden data-consent-panel>`: a
    fieldset (legend `site.consent.choose_title`) with, per category that has a service, a `<input type="checkbox"
    role="switch" data-consent-category="statistics|advertising">`, its label (`site.consent.category.*`) and its
    description naming the services (`…statistics_text`, `…statistics_text_replay` with Webvisor,
    `…advertising_text`), then "Save choice" (`data-consent-save`). Nothing is pre-ticked.
- The core script (`/admin-assets/consent.js`) works through those data attributes only: it unhides the banner while an
  offered category is undecided (it never moves the focus then), stores the choice in the first-party cookie
  `atar_consent` (a token per category, `s1.a0`; 180 days; `SameSite=None; Secure` on https, so Metrica's framed click
  map sees it), loads and initialises each category's services after it is granted (their scripts get the nonce; no
  inline code; Google's tag once, with Consent Mode v2), and on a withdrawal stops what can be stopped, deletes the
  category's cookies and stops loading it from the next page. "Choose" opens the panel with the switches on the stored
  choice. `data-consent-open` reopens the banner with the focus on it (and the panel open when a choice is stored);
  after a choice the focus goes back to the opener. Without JavaScript nothing loads, so nothing shows.
- `assets/css/site.css`, section "Cookie banner": a card fixed at the bottom inline-start corner (a band on phones),
  `.consent[hidden] { display: none; }` and `.consent-choice[hidden] { display: none; }`, lifted above the
  accessibility toolbar's button when it shares the bottom corner, a fade that the reduced-motion and `a11y-still`
  rules stop, logical properties only.

Another theme (a purchased template too) keeps the data attributes and the two helper calls and restyles or rewrites
the rest freely: its own markup, classes and CSS, its own catalog keys `site.consent.*`; a theme without the panel
still works (Accept all and Decline cover every category); a theme that never prints `consentHead()` simply never
counts.

### Hero slider

The skeleton draws the home page's `hero` slides (`Field::slides()`) with its own small slider (no library):

- `partials/hero-slider.php` (from `collections/home/show.php` while there are slides, `mediaFor($entry)` for the
  pictures and videos): `<section class="hero-slider" aria-roledescription="carousel" aria-label>` with `data-slider`
  for more than one slide or any video slide; each slide a `role="group" aria-roledescription="slide"` ("1 of 3") with
  its picture (`img($m, 'hero')` with the taller crop `hero_phone` (5:4, the phones' slide box) for `(max-width: 40rem)`, the first `loading="eager"` + `fetchpriority="high"` + `decoding="auto"`, the
  others lazy) and a caption:
  the title as `<h2>` (the page's `<h1>` is the site name), the subtitle and the link, a `.button` when the slide has a
  button text, else the whole slide is an `<a>`. The server marks the first slide `is-active`. A slide with a video
  draws, in place of the `<img>`, `<video class="hero-slide-video" muted loop playsinline preload="metadata"
  poster="{mediaUrl($picture, 'hero')}" aria-hidden="true" data-slide-video><source src="{mediaUrl($video)}"
  type="{$video->mime}"></video>` and the picture's alt text in a `.visually-hidden` span: no `autoplay` attribute, so
  without the script the poster shows. Browsers fetch a poster late and at low priority, so when the first slide is a
  video `collections/home/show.php` passes its poster URL to the layout as `preloadImage`, which preloads it from the
  `<head>` (`as="image"`, `fetchpriority="high"`); later slides' posters wait. Controls (only with `data-slider`): previous and next (`arrow-left`/`arrow-right`,
  mirrored by `:dir(rtl)`) and a dot per slide (`aria-current` on the shown one), both only for more than one slide,
  and Pause/Play (a quiet icon-only button: its `pause`/`play` icons are switched by `.is-paused`, and `slider.js`
  renames its `aria-label` and `title` — the tooltip — when pressed; one video slide gets Pause alone), named by
  `site.slider.*`.
- `assets/js/slider.js` (a module the layout loads only when the page holds ` data-slider>`): shows the next slide
  every 6 s; never on its own under `prefers-reduced-motion` or `a11y-still` on `<html>` (watched with a
  `MutationObserver`, so the toolbar's "Stop animations" stops it at once; Pause is then hidden), while the pointer
  rests on it or the focus is inside, while the tab is hidden, or after Pause; the arrow keys on the controls go by
  `getComputedStyle(slider).direction`. The shown slide's video plays (`muted`, `.play()` with its rejection swallowed:
  a browser that refuses leaves the poster) while motion is allowed — not still, the tab visible, no Pause; hovering
  holds only the turning — and every other video is paused. Slides not shown are `inert`; the slides' region is
  `aria-live="off"` while it moves and `polite` when still. Classes, properties and `textContent` only.
- `assets/css/site.css` ("Home: the slider"): the slides share one grid cell and fade (opacity, plus a slight zoom
  of the shown picture), with no sideways movement, so right to left needs only the mirrored arrows; without `.js`
  only the first slide shows (`:root:not(.js) .hero-slide + .hero-slide { display: none; }`). A video fills the slide
  like the picture (`.hero-slide-video`, `object-fit: cover`). Captions use the `--hero-*` tokens (light text on a dark
  shade in both schemes).
- Videos are the media library's own files (MP4/WebM up to `max_video_size`, 100 MB, in `config/media.php`), served
  from `/uploads` inline with byte ranges (Safari needs them); the CSP's `default-src 'self'` covers them. Keep them
  short, silent loops (a few MB: 720p, H.264 MP4 plays everywhere); the picture stays required as the poster and the
  fallback. YouTube and Vimeo are not slide videos (their iframes bring controls, cookies and autoplay rules a
  background cannot honour): use `Field::youtube()` for an ordinary embed elsewhere on the page.

**The same data, other heroes.** The slides are plain data (`list<Slide>`); the carousel above is one way to draw
them, not part of the field. A theme may build any hero from the same field, within the usual rules (a background
is an `<img>` with `object-fit: cover`, never `style="background-image"`; keep `prefers-reduced-motion`,
`a11y-still` and the working no-JS state):

- **Background slides** (the skeleton): every slide its full-bleed picture under its own caption.
- **One headline, rotating pictures:** the template writes the heading itself (or prints the first slide's
  `title`) outside the rotation and cycles only the pictures; the editors leave the other slides' texts empty.
- **Rotating texts over one picture:** every slide carries the same picture (a slide always has one); the
  template draws the first slide's picture once, still, and cycles only the captions.
- **Split hero:** the caption in one column, the picture beside it; the rotation swaps the pair together.
- **Video slides** (the skeleton): a slide with `videoId` plays its video instead of the picture, the others keep
  their pictures; the rotation goes on as usual.
- **One full-hero background video:** one slide with a video (and its texts); the skeleton draws it without arrows
  or dots, with Pause, still under reduced motion.
- **Background video with rotating captions:** every slide carries the same video (and picture); the template draws
  the first slide's `<video>` once, outside the rotation, and cycles only the captions; playback follows the same
  rules (Pause, reduced motion, `a11y-still`, hidden tab).
- Dressings — thumbnails instead of dots, a Ken Burns zoom, a progress bar — are CSS and script on top of the
  same markup. Start from `partials/hero-slider.php` and `assets/js/slider.js` and keep their ARIA
  (`aria-roledescription`, the per-slide labels), `inert` on the hidden parts and the stillness behavior when
  you restyle; what cycles changes, the manners do not.

## 7. Recipe: a theme from a design

1. Map the design to page types (section 2): home (single), archive, entry, section/term page, search, contacts,
   404/500; list the shared pieces (header, menu, footer, card, breadcrumbs, side column, pagination) as partials.
2. Decide the content: which collections/fields the design needs (add fields in `config/collections.php`), menu
   locations (`theme.php` `menus`), image sizes per slot (`images`: e.g. `hero` crop, `card` crop, `large`).
3. Write tokens (colours for light and dark, type scale, spacing) at the top of `assets/css/site.css`; logical
   properties only; style `.rich-text` and `editor.css` (`.ck-content`).
4. Write `layouts/default.php` (head helpers, header, `$content`, footer), then partials, then page templates.
5. Add the theme catalog keys in every language. Keep the cookie banner (section 6, Cookie banner): the layout's
   `consentHead()` and `consent()`, a `[data-consent]` region with Accept and Decline (and, with advertising pixels,
   Choose and the categories' panel), a footer `[data-consent-open]`.
6. Preview: `php bin/atar demo:seed` (deletes content; asks "yes"; `--dry-run` shows what it would delete), sign in
   (`APP_ENV=local` offers the dev login), check every language, light/dark, 375px.
7. Check: `php bin/atar theme:check <theme>` (section 9) until it reports no errors; then switch `APP_THEME`. The
   skeleton's own theme has repository tests on top (`SkeletonThemeAssetsTest`, `SkeletonCatalogTest`,
   `SkeletonThemeTest`: vendored hashes, contrast, page markup).

Minimal working example:

```php
<?php // layouts/default.php ?>
<?php $withSite = ($status ?? 200) < 500; ?>
<!doctype html>
<html<?= $this->langAttrs($this->locale()) ?>>
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($withSite ? (($fullTitle ?? null) ?? $this->pageTitle($title ?? null)) : ($title ?? '')) ?></title>
    <?php if ($withSite): ?><?= $this->siteHead() ?><?php endif ?>
    <?= $this->hreflang() ?><?= $this->canonical() ?><?= $this->markdownAlternate() ?>
    <?= $this->socialMeta($title ?? null, $description ?? null, isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null, 'large') ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset('css/site.css')) ?>">
</head>
<body>
<header><a href="<?= $this->e($this->url('content.home.show')) ?>"><?= $this->e($withSite ? $this->siteName() : '') ?></a>
<?php if ($withSite): ?><nav aria-label="<?= $this->e($this->t('site.menus.main')) ?>">
    <?php foreach ($this->menu('main') as $link): ?>
    <a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?>><?= $this->e($link->label) ?></a>
    <?php endforeach ?>
</nav><?php endif ?></header>
<main id="main"><?= $content ?></main>
</body>
</html>
```

```php
<?php // collections/home/show.php (a single: $entry may be null) ?>
<?php $this->layout('layouts/default', ['title' => null, 'fullTitle' => $this->siteSettings()->metaTitle]); ?>
<h1><?= $this->e($this->siteName()) ?></h1>
<?php $news = $this->entries('news')->latest(3); $media = $this->mediaFor($news); ?>
<?php foreach ($news as $item): ?>
<article><?= $this->img($media->get($item->field('cover')), 'card', ['alt' => '']) ?>
    <h2><a href="<?= $this->e($this->entryUrl($item)) ?>"><?= $this->e($item->title) ?></a></h2></article>
<?php endforeach ?>
```

```php
<?php // show.php (any entry) ?>
<?php $this->layout('layouts/default', ['title' => $seo?->title ?? $entry?->title, 'description' => $seo?->description]); ?>
<article><h1><?= $this->e($entry?->title ?? $this->label($collection->label)) ?></h1>
<?php if ($entry !== null && $collection->field('body') !== null): ?>
<div class="rich-text"><?= $entry->field('body')->html ?></div>
<?php endif ?></article>
```

## 8. Reference: the skeleton theme (`skeleton/themes/default`)

| File | Role |
|---|---|
| `theme.php` | menus `main`, `footer`; images `thumb`, `card`, `medium`, `large`, `hero`, `logo`; editor styles/blocks/colours/fonts; `share_image` band |
| `layouts/default.php` | head (theme bootstrap with nonce and the stored accessibility classes, SEO helpers, CSS, `theme.js`, `tooltip.js`, `a11y.js`, `slider.js` on slider pages, lightbox on gallery pages), skip link, admin bar, accessibility toolbar, header (brand, search, language menu, phone menu, theme menu), main menu band, `main` with optional `aside`, footer (with the preset pages' links); `wide`, `aside`, `title`, `fullTitle`, `description`, `robots`, `feed` |
| `collections/home/show.php` | the slider of the `hero` slides (`partials/hero-slider.php`), hero (`lead`), a block per top section (`termChildren`, `withinTerm`), latest news, intro |
| `collections/articles/show.php` | breadcrumbs (`termTrail`), article body; aside: subsections, more in section, contacts |
| `collections/news/index.php`, `collections/news/show.php` | news cards with tags and pagination; news item with tags and related |
| `collections/pages/show.php` | a page; aside: latest news, contacts |
| `collections/contacts/show.php` | text, contact form, contact details, map |
| `form.php`, `partials/form-fields.php`, `partials/form-dialog.php` | a form's page; the fields of any form (contact form, page, dialog); the dialog of a form (the layout includes the `booking` dialog and the header's "Book" link) |
| `taxonomies/sections/show.php` | a section's articles: announced (summary) or in full |
| `term.php`, `index.php`, `show.php`, `search.php` | tag pages, generic archive, generic entry, search |
| `errors/404.php`, `errors/error.php` | not found (search + home), other errors (no database on 5xx) |
| `partials/admin-bar.php` | signed-in quick actions (`adminBar()`) |
| `partials/a11y.php` | the accessibility toolbar: the round button, the options panel and the reading guide |
| `partials/consent.php` | the cookie banner of the analytics counters and advertising pixels (`consent()`; the core's `consent.js` shows it) |
| `partials/hero-slider.php` | the home page's slides as a carousel (see Hero slider) |
| `partials/menu.php`, `language-menu.php`, `languages.php`, `theme-menu.php`, `search-form.php` | header pieces |
| `partials/breadcrumbs.php`, `pagination.php`, `card.php`, `article-body.php`, `aside.php` | page pieces |
| `partials/footer.php`, `contact-links.php`, `contact-details.php`, `brand-icon.php`, `contact-form.php`, `map.php`, `icon.php` | footer and contacts |
| `assets/css/site.css`, `assets/css/editor.css` | tokens (light, dark, `[data-theme]`), layout, components, `.rich-text`; editor view |
| `assets/js/theme.js`, `assets/js/gallery.js`, `assets/js/tooltip.js`, `assets/js/a11y.js`, `assets/js/slider.js`, `assets/js/forms.js` | theme menu and dropdowns; PhotoSwipe lightbox; titles as tooltips (the admin's file, byte for byte); the accessibility toolbar; the hero slider; the form dialogs |
| `assets/icons.svg` (+LICENSE), `assets/cursors/`, `assets/brands.svg` (+LICENSE), `assets/flags/`, `assets/fonts/`, `assets/vendor/` | Lucide sprite and the big cursors drawn from it, Simple Icons brands, language flags, Rubik, PhotoSwipe 5.4.4 |
| `editor/note.html`, `editor/columns.html` | ready-made rich-text blocks |
| `lang/en.php`, `lang/ru.php`, `lang/he.php`, `lang/de.php`, `lang/fr.php` | the theme catalog (`site.*`, the forms' `forms.*`) |

## 9. Starting and checking a theme

**`php bin/atar theme:new <name> [--from=<theme>]`** creates `themes/<name>/`: from the core's scaffold (a layout with
the head helpers, header, main menu, languages, search and a footer with the footer menu, contacts and social links;
`show.php` printing an entry's fields in config order with the contact form on the `content.contact_form` page,
`index.php`, `term.php` with breadcrumbs, `search.php`, `form.php`, `errors/404.php`, `errors/error.php`, partials, a stylesheet
with light and dark tokens and logical properties only, and a catalog for every site language; languages other than
en, ru, he, de and fr get the English catalog to translate) or, with `--from`, a byte-exact copy of a site theme. It refuses
an existing directory. The scaffold passes `theme:check` (on a site with forms of `config/forms.php` it warns until
their `forms.{key}.*` texts are in its catalogs); build on it rather than from nothing.

**`php bin/atar theme:check [<theme>] [--json] [--no-render]`** checks a theme (default: the active one) without
switching the site. Exit code 0 without errors, 1 with errors (warnings never fail), 2 when `themes/<theme>` is
missing. Each finding is one line `ERROR|WARNING|INFO [check] file:line message`; `--json` prints
`{"theme", "ok", "errors", "warnings", "info", "pages"}` (findings as `{"check", "file", "line", "message"}`).

| Check | What it reports |
|---|---|
| `config` | `theme.php` fails the boot validation; `direction: ltr` with a right-to-left language; no `main` menu location while `content.main_menu` is set (warning) |
| `templates` | a routed collection without `collections/{c}/show.php` or `show.php`; an archive without `index.php`; a routed taxonomy without `taxonomies/{t}/show.php` or `term.php`; no error template or no `search.php` (warnings); template folders of unknown collections or taxonomies (warning); no `form.php` while `config/forms.php` has a form |
| `sources` | `style=` and `<style>`, inline `<script>` without the nonce, `on…=` handlers, `javascript:` URLs, files loaded from other hosts; literal names that do not exist: `menu('x')`, `img($m, 'size')`, `mediaUrl($m, 'size')`, `url('route')`, `asset('file')`, `t('key')` (default language or core catalog); `<?= $entry->title ?>` printed without `$this->e()` and `declare(strict_types=1)` (warnings) |
| `catalogs` | a site language without `lang/{locale}.php`, keys that differ from the default language's catalog, invalid ICU messages; missing `forms.{key}.*` texts of a form of `config/forms.php` (warning) |
| `css` | physical sides outside the editor's own alignment classes (not with `direction: ltr`, not in `assets/vendor/`), `url()`/`@import` of other hosts, missing `url()` files; a mirroring transform outside `:dir(rtl)` (warning) |
| `scripts` | imports, `fetch()` and other requests to other hosts |
| `vendor` | a folder of `assets/vendor/` without a licence file (warning) |
| `render` | (skipped with `--no-render`; needs the database) every language: the home page, each single page and archive, the newest entry of each routed collection, the first term of each routed taxonomy, a search and a missing page (must be 404), through the whole stack of the site booted with this theme; a 5xx, a PHP warning or a logged error is an error with the page and the message; no `<html lang>`, not one `<h1>` or an `<img>` without `alt` is a warning; a collection without entries is an info line |

The render smoke writes nothing (no logs, uploads, share images or image variants: the pages are rendered, the files
they link to are not requested), with two exceptions that come from the theme: a public page calling
`csrfField()`/`csrfToken()` starts a session (as it would for every visitor: the reason not to), and the first page
with the contact form creates `storage/app.key` when the site has none. Run it as the web server's user, so such a
file belongs to the web server.

Related: `php bin/atar demo:seed --dry-run` (what the demo content would delete) and
`php bin/atar settings:import <file.json>` (the site settings from JSON; `--example` prints the current ones in that
shape, `--dry-run` only validates; a key left out keeps its value, `""` or `null` removes it, images and the "hide from
search engines" switch included (`accessibility_toolbar`: `false` turns the toolbar off, `""` or `null` back to the
default, on; `accessibility_toolbar_position`: a corner, `""` or `null` back to `bottom-end`; `yandex_metrica`,
`yandex_webvisor` (bool), `google_analytics` and per language `consent_text` for the analytics counters,
`meta_pixel`, `google_ads`, `vk_pixel`, `tiktok_pixel` for the advertising pixels); a file
naming no settings is refused).
