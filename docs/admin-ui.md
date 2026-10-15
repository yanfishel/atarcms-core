# Admin UI reference

The admin panel: its files, design tokens, shell, screens and rules. Rules
marked "(test)" are enforced by a test; change the test only together with the rule.

## Files

- `resources/admin/assets/`: `admin.css` (the one stylesheet), `icons.svg` + `icons.LICENSE` (vendored Lucide
  subset), `fishart.png` (sidebar logo), `logo/` (the AtarCMS logo per admin colour preset, Plan 19: `atar-{preset}.svg`
  and `atar-{preset}-{32,180,192,512}.png`, the 180 full-bleed for iOS, with `Cardo-OFL.txt` and `NOTICE.txt`; the admin
  brand and the site's default icons, `Settings\AtarLogo`), `shell.js` (every signed-in page), `tooltip.js` (every
  page, guests too: titles as tooltips, Plan 27; the skeleton theme ships a byte-identical copy), `tabs.js` (entry form, section form, site settings, the dashboard's "In progress" tabs, the profile), `media.js` (library,
  picker, media widgets, the rich-text editor's image picker), `editor.js` (the rich-text editor), `tree.js` (order
  screens) with `tree-open.js` (a classic script of the tree editor that opens its remembered items before the first
  paint), `dialog.js` (the dialog of the Sections, Menus and Users screens, the terms lists, the media library's folders and the contact page's form), `autosave.js` (the entry form's draft autosave), `slides.js` (the slides widget and its dialog, Plan 30), `tags.js` (the tags field's tag box, Plan 35), `translate.js` (machine translation in the browser, Plan 38: the offers' machine buttons and the copy's panel; entry and term forms and lists of multilingual sites, last in `scripts`), `list-filters.js` (the self-submitting list toolbars), `youtube.js` (the YouTube field's video card, entry forms with a YouTube field, Plan 42), `consent.js` (not an admin script: the public cookie banner's loader, printed by the theme helper `consentHead()`, Plan 40; it keeps the admin script rules), `vendor/` (htmx, SortableJS `sortable-1.15.7.esm.js`, each
  with its `.LICENSE`; CKEditor 5 `ckeditor5-48.5.2.js` + `ckeditor5-48.5.2.css` + `ckeditor5-48.5.2-{ru,he,de,fr}.js` with
  `ckeditor5.LICENSE` and `ckeditor5.COPYING.GPL`, GPL-2.0-or-later).
- `resources/admin/templates/`: `layouts/admin.php` (shell and guest layout), `partials/{page-header,pagination,toast,locale-badge,content-locale,translations,machine-translation,brand-mark,dialog-close,table-date}.php`,
  `partials/flags.php` (the lists' plain language flags),
  screens per area (`dashboard.php` with its blocks in `dashboard/*`, `entries/*`, `terms/*`, `sections/*`, `menus/*`, `media/*`, `messages/*`, `settings/*`, `map/*`,
  `users/*`, `profile/*`, `audit/*`, `login.php`, `errors/error.php`), `tree/{editor,nodes}.php`, and the widgets `fields/{type}.php` (`fields/field.php`
  wraps each field, `fields/default.php` is the fallback; `slides/{item,dialog}.php` belong to `fields/slides.php`,
  `tags/pill.php` to `fields/tags.php`).
- Serving: `AdminModule` builds `AssetUrl` over `resources/admin/assets` with the prefix `app.admin_assets_url`
  (default `/admin-assets`); the web server serves the directory there (`skeleton/deploy/`, `immutable`), else PHP
  (`View\Http\AssetFileController`, same headers). `$this->asset($path)` appends `?v=` + 12 hex chars of the file's xxh128 hash, so a changed file gets a
  new URL. ESM files imported by another module cannot carry `?v=` and put their version in the file name instead.
- Vendored files, `icons.svg`, `icons.LICENSE`, `fishart.png` and `logo/*` are byte-exact: each has a `-text` entry in
  `.gitattributes` (test: `EditorAssetsTest` for every `vendor/*`, `MediaAssetsTest`, `IconsAssetsTest`,
  `AtarLogoAssetsTest`); the sha256 of `icons.svg` (`IconsAssetsTest`), `fishart.png` (`FishartLogoAssetTest`), every
  logo file (`AtarLogoAssetsTest`, which also checks each SVG is the green master with only the colour changed and
  each PNG's size, tile colour and corners) and the six CKEditor code files (`EditorAssetsTest`) is pinned, and no
  `.map` file is vendored. The logo was rendered once outside the repo (`rsvg-convert` 2.58.5 in a throwaway
  container, `oxipng`); a new preset adds its files the same way. Never edit or reformat them; restyle CKEditor from
  `admin.css`. An upgrade adds files with the new version in their names and new pinned hashes.

## Design system (`admin.css`)

- Sections in order: Tokens, Base, Shell, Components, Screens, Editor. The token section declares the light palette on
  `:root`, the dark one on `:root[data-theme="dark"]` and again under `@media (prefers-color-scheme: dark)` for
  `:root:not([data-theme="light"])`; keep the two dark copies identical.
- Admin colour (Plan 19; personal since Plan 34): `<html data-accent="{preset}">` (the layout, from the `adminBrand`
  helper; the shell, the login page and admin error pages): the signed-in user's own colour (`users.accent`, chosen on
  the Profile; an admin error page gets it from `AdminErrorLocale` as the request attribute `AdminAccent::class`), else
  the site colour (`Settings::accent()`: the preset nearest to the theme colour, green without one) — the login page,
  guests' error pages and users without a choice. Green is the base palette; each other preset (`Settings\AdminAccent`: teal, blue,
  indigo, violet, cherry, terracotta, graphite) sets `--accent`, `--accent-hover`, `--accent-strong`, `--accent-soft`,
  `--on-accent` and `--focus-ring` in the token section under `:root[data-accent="x"]`,
  `:root[data-theme="dark"][data-accent="x"]` and, in the media block, `:root:not([data-theme="light"])[data-accent="x"]`
  (0,3,0, so the dark ones win wherever they stand). Light: hover = strong = the darker shade, white text; dark: strong =
  accent, hover = the accent mixed 22% towards white, `#1b1916` text. `--swatch-{preset}` tokens show every preset in
  the current palette. Test `AccentPresetsTest`: the blocks and tokens, the light accent = the logo colour, the dark
  copies identical, WCAG AA for button text (and on hover), links on `--surface` and on `--accent-soft`.
- Chart palette (Plan 36): `--chart-1` … `--chart-8`, the swatch hues re-stepped and ordered (green, blue,
  terracotta, teal, violet, cherry, indigo, then graphite as a neutral) so that neighbours pass the dataviz
  `validate_palette.js` checks in both palettes on `--surface` and `--raised` (the plan records the run); the swatches
  themselves stay the presets' colours. `admin-tone-{1…8}` set `--tone` from them (`admin-tone-accent`,
  `admin-tone-warning` from the accent and the warning colour); `--chart-wash` is what the activity chart mixes the
  accent with for its second series (the surface in light, `--border-strong`'s value in dark).
- Colours (`#…`, `rgb()`, `hsl()`) appear only in the token section (test: `testTheTokensAreTheOnlyPlaceWithLiteralColours`).
  Add a token rather than a literal.
- Shape tokens: `--radius-card` 12px, `--radius-control` 8px, `--radius-pill`; `--space-1`…`--space-6` (4/8/12/16/24/32);
  `--control-height` 40px, `--control-height-compact` 32px, `--control-height-tiny` 24px (the tree's moves and
  chevron), `--topbar-height` 56px, `--sidebar-width`, `--rail-width`,
  `--content-width`, `--side-column`; shadows `--shadow-card`, `--shadow-bar`, `--shadow-pop`; `--logo-filter`.
- Base: `:focus-visible` ring (inputs add `--focus-ring`), `[hidden] { display: none !important }`,
  `prefers-reduced-motion` switches transitions and animations off, `.admin-visually-hidden` for screen-reader text.
- Editor: `vendor/ckeditor5-48.5.2.css` loads after `admin.css`, so every override starts with `:root` (test). CKEditor's
  `--ck-color-*` (and style-panel) variables are mapped to tokens on `:root .ck`, which beats its `:root` defaults, so the
  editor follows both palettes; every value there is a `var(--token)` (test). The editable has the input border and
  `min-height: 12rem`; `.admin-editor-video` sizes the video previews; `.ck-word-count` (muted, under the editor) has
  a rule because the vendor stylesheet has none; the fullscreen editable, which the vendor stylesheet paints white with
  a selector ending in `:not(.ck-editor__nested-editable)`, gets `var(--raised)` with that same ending (test). The
  toolbar wraps on its own (`shouldNotGroupWhenFull`); there is no toolbar rule.
- Widths: grid tracks are `minmax(0, 1fr)` and grid/flex children that hold text or editors get `min-width: 0`, so no
  child widens a column. The entry form is a container (`container: entry-form / inline-size`) and stacks its two
  columns below 880px of its own width (`@container entry-form (max-width: 879px)`), whatever the sidebar leaves.
- Breakpoints: below 1200px the sidebar defaults to the rail (JS only), below 768px it is a drawer, tables become
  card lists and the action bar widens; below 480px the entry form's Delete, Earlier versions and Preview show
  their icon only (`.admin-action-compact`; its text sits in `.admin-action-label`, visually hidden there but kept for
  screen readers and as the `title`) and the buttons keep one row under the status line (`flex-wrap: nowrap`, Save
  included); the media item page stacks below 1000px.
- Hiding rules: every rule that hides something (`display: none`, `visibility: hidden`, the drawer's off-screen
  transform) starts with `.js`, names a class a script adds, or is listed with its reason in
  `AdminStylesTest::HIDDEN_WITHOUT_JS` (test). Each selector of a list counts on its own (`.js .a, .b` is not gated:
  `.b` hides without JavaScript), and a script's class counts only as a whole class name (Plan 42).

## Icons

- `$this->icon($name, ?$label)` renders `AdminIcon::svg()`: `<svg class="admin-icon"><use href="…/icons.svg?v=…#name">`.
  Without a label it is `aria-hidden`; with one it is `role="img"` with an `aria-label` (icon-only buttons and links; never an SVG `<title>`, which browsers show as their own tooltip box, Plan 27).
  Names must match `AdminIcon::NAME_PATTERN` (`[a-z0-9-]+`) and exist in the sprite (tests: literal `->icon('…')`
  names in `AdminStylesTest`, every icon of the main admin pages as rendered in `AdminIconsTest`). The admin menu checks
  its items' icons against the sprite (`AdminMenu::assertIcons()`, `AdminIcon::symbols()`) when `AdminModule` builds
  it: a typo in `Collection::icon()`, `Taxonomy::icon()` or a module item is a `ConfigException` on the first admin page
  that names the item and the module that added it (`AdminMenu::from()`, called by `AdminModule` before each module's
  `adminMenu()`); a missing or empty sprite file is one `ConfigException` of `AdminIcon::symbols()`.
- Mirrored icons: the names in `AdminIcon::MIRRORED` (`arrow-left`, `arrow-right`, `chevron-left`, `chevron-right`,
  `external-link`, `log-in`, `log-out`, `folder-input`, `folder-tree`, `align-left`, `list`, `list-tree`, `arrow-down-wide-narrow`)
  render with the extra class `admin-icon-mirror`, flipped in a right-to-left page by
  `.admin-icon-mirror:dir(rtl) { scale: -1 1; }` (the `scale` property composes with the `transform: rotate()` of the
  rail toggle and the tree accordion). Clocks, letters, vertical arrows and pictures of things are never mirrored; a new
  directional icon joins the list (`AdminIconTest`).
- Field labels: `FieldIcons::of($field)` by `FieldType::name()` (`FieldIcons::ICONS`); other types get `circle`
  (`FieldIcons::DEFAULT`).
- Menu items: `AdminMenu::add(label, route, permission, order, params, icon:)`, default `AdminMenu::DEFAULT_ICON`
  (`circle`). `Collection::icon()` / `Taxonomy::icon()` set their item's icon; `Collection::adminIcon()` defaults to
  `home` (single), `newspaper` (archive), `file-text`; taxonomies default to `folder-tree` (hierarchical) or `tag`
  (`ContentModule`). Core items: dashboard `layout-dashboard`, menus `list-tree`, media `image`, site settings
  `settings` (the gear; `monitor` stays for the theme menu's "System"), users `users`, audit `history`. The sections' item leads to `/admin/sections`; the Menus item is left out
  when the theme has no location besides the main menu of sections (`MainMenu::menuLocations()`). Structure order
  (Plan 32, user decision): the sections first (`TAXONOMIES`), Menus right after them (`TAXONOMIES + 1`), then the
  other taxonomies in config order; without a root tree the taxonomies keep their order and Menus follows them
  (`MENUS`).
- Adding a symbol: copy it from the same Lucide release (1.49.0, `icons.LICENSE`) into `icons.svg` and update the
  pinned hash in `IconsAssetsTest`. Each `<symbol>` holds the icon file's children with `" />"` written `"/>"`, one
  line per symbol, sorted by id in byte order (Plan 16 added `folder`, `folder-plus`, `folder-input`, `layout-grid`
  and `copy` this way, then `arrow-down-a-z`, `arrow-down-wide-narrow`, `calendar-arrow-down`, `calendar-arrow-up` and
  `files` for the media toolbar; Plan 18 added `settings`; the dashboard cards added `chart-no-axes-column`, `layers`
  and `pencil-line`; Plan 42 `image-off` for the entries list's photo placeholder; the YouTube card `play`).

## Shell (`layouts/admin.php`, `shell.js`)

- Head: the one inline script (nonce'd) reads `localStorage` `admin.theme` (`light`/`dark`, else no attribute =
  system) and `admin.sidebar` (`rail`/`full`) into `<html data-theme>` / `data-sidebar`, then adds the `js` class.
  Then `admin.css` and htmx (a plain deferred `<script src>` without a nonce, guests included; `htmx-config`: no
  eval, no indicator styles). Signed-in pages add the page's `styles` as `<link>`s, then its `themeStyles` (paths
  inside the active theme's `assets/`, through `themeAsset()`), then `shell.js` and the page's `scripts` as nonce'd
  module scripts. `tooltip.js` (nonce'd module) comes right after htmx on every page, guests included.
  `<body hx-headers>` carries the CSRF token for htmx.
- Tooltips (Plan 27, user decisions: no library, every `title` becomes one): `tooltip.js` shows the `title` of any
  element in one shared tip `#tooltip` (a `div` with `popover = 'manual'` and `role = 'tooltip'`, text through
  `textContent`, in the top layer: never clipped by `overflow`, above an open modal `<dialog>`) on hover with a mouse
  or pen (300 ms, `SHOW_MS`; at once within 500 ms of the last tip, `CHAIN_MS`; never for touch) and on keyboard focus
  (`:focus-visible`, the focused element's own title). Templates keep writing `title`s (with an `aria-label` for
  icon-only controls, and never an SVG `<title>`: `AdminIcon` names labelled icons by `aria-label`). A descendant with
  a title of its own inside a shown trigger is a new trigger. Placement is CSS only: the trigger gets
  `data-tooltip-anchor` (dataset; `[data-tooltip-anchor] { anchor-name: --tooltip }`) and `#tooltip` has
  `position-area: block-start` with `position-try-fallbacks: flip-block, flip-inline` (logical, so right to left
  follows), `position-visibility: anchors-visible` (the sidebar's tips open at its inline-end side:
  `body:has(.admin-sidebar [data-tooltip-anchor]) #tooltip`); its text is `--surface` on a `--text` background
  (inverted, so it reads in both palettes). It fades in through `@starting-style` (the transition sits on
  `:popover-open` only) and hides at once, so it never fades out away from its anchor (no motion under the Base rule's
  `prefers-reduced-motion`). While it shows, the title waits in `data-tooltip-title` (no second, native box) and comes
  back on hide; an element named only by its title (no `aria-label`/`aria-labelledby`, label, visible text, image alt
  or labelled icon) gets it as `ariaLabel` for that time, any other one whose name differs from the text and that has
  no `aria-describedby` gets `ariaDescribedByElements = [tip]`. It hides on pointer or focus leave, a press, any key
  but Tab and the modifiers, a `toggle` (a `<details>` menu or another popover opening or closing, captured), a
  resize, scrolling (a hovered tip only; a focused one follows its anchor), `htmx:beforeSwap`, a dialog's `close`,
  `pagehide`, a trigger no longer in the page (checked on the next event) and Escape, which then closes only the tip
  (`preventDefault`, so an open dialog or menu stays; the next Escape closes it). A tip dismissed under the pointer (a
  press, a key, a toggle, scrolling) keeps the title parked until the pointer leaves that element, so the native box
  does not take its place. Skipped (native title kept): CKEditor's UI (`.ck`), `<option>`, `<iframe>`, anything inside
  `[data-no-tooltip]`. Without `HTMLElement.prototype.showPopover`, `CSS.supports('anchor-name: --a')` or
  `CSS.supports('position-area: block-start')`, or without JavaScript, nothing happens and the browser shows the
  titles. The file is shared byte for byte with the skeleton theme, so it names no `admin-` class
  (`TooltipAssetsTest`).
- AI guide (`GET /admin/ai-guide`, `admin.ai_guide`, any signed-in user; `Admin\AiGuide`,
  `Controller\AiGuideController`): the admin described for AI browser agents as one English markdown document,
  `text/plain; charset=utf-8`. The static part is `resources/admin/ai-guide.md` (a `## ` section whose first line is
  `<!-- requires: {permission} -->` is left out for users without it; any other comment throws, and the tests check
  the named permissions: keep it true to this file when screens change); the generated part lists the site languages, the user's visible sidebar items
  (`AdminMenu::visibleTo()`, the sidebar's own filter) plus Messages and Profile, and of those the content types
  with their fields, the taxonomies and the menu locations. Every signed-in page links it from the sidebar footer
  and as `<link rel="help">` in `<head>`. Tests: `tests/Functional/AiGuide/`.
- Favicon: every admin page and the login page link the brand mark as `<link rel="icon">` (the site icon's
  `icon_180`, else the logo SVG of the admin colour).
- Sidebar (`#sidebar`): brand (a band as tall as the top bar with a bottom line level with the top bar's, the mark 33 px
  and centred in it; the mark of `partials/brand-mark.php` + the site name, links to the dashboard; the
  mark is an `<img alt="">` of the site icon's `icon_180` variant when the settings choose one, `admin-brand-icon`, else
  the logo SVG of the admin colour, which stays the same in the dark palettes, a user decision of Plan 19), nav groups from `AdminMenuGroup::of()`
  (`AdminMenuGroup::BANDS`: `DASHBOARD` has no heading; Content = `CONTENT`, Structure = `TAXONOMIES` + `MENUS`,
  Files = `MEDIA`, Modules = `MODULES`, Settings = `SETTINGS` + `USERS` + `AUDIT`, keys `admin.menu.group.*`; a group without items
  is not shown; a plain single's item needs its publish permission, as editing it does), current item
  `aria-current="page"`. The brand and the links name themselves in `data-rail-title`; `shell.js` makes it their
  `title` (a tip) only on the rail (not in the phone drawer) or for a label cut short. The rail is a container query
  (`@container sidebar (max-width: 120px)`): labels visually hidden, headings gone, logo hidden. The edge toggle
  (`[data-sidebar-toggle]`, JS only) sits at the bottom on the right edge, outside the scrolling nav; the AI guide
  link (`admin-sidebar-guide`, `admin.layout.ai_guide`, hidden on the rail) and the fishart link are centred in
  `.admin-sidebar-footer`. There is no page footer. Below 768px: the burger (`[data-drawer-open]`) opens
  a drawer (`html.admin-drawer-open`; its own × button, the backdrop `[data-drawer-close]`, Escape and a link click
  close it); the × (Plan 42: the open drawer covers the burger) is `admin-button admin-button-link admin-button-icon
  admin-drawer-close` `[data-drawer-dismiss]`, "Close the menu" (`admin.layout.close_menu`), right after the brand link
  in the DOM (the focus on opening and the Tab cycle keep the brand first) and placed at the inline end of the brand
  band (absolute, logical insets; the brand gets room for it by `padding-inline-end`); `.admin-sidebar
  .admin-drawer-close { display: none; }` (two classes, so the later `.admin-button` rule does not show it; in
  `HIDDEN_WITHOUT_JS`) and only `.js .admin-sidebar .admin-drawer-close` in the phone query shows it; closing by it
  returns the focus to the burger like every close. The drawer slides only
  once the burger was used (`html.admin-drawer-animated`, removed when the window crosses 768px), opens visible at
  once and is hidden after sliding out. While it is open on a phone the page behind it is `inert` (the property, on the
  skip link, the breadcrumbs, the top-bar menus and `.admin-content`, cleared by every close and when the window
  crosses 768px); the burger stays usable as a toggle beside the drawer's ×, Tab and Shift+Tab wrap
  inside it; Escape closes it and returns the focus to the burger. Without JS the sidebar is a plain block above the page. The group names are
  not headings (in the pale `--faint` token, a user decision): `<p class="admin-nav-heading" id="nav-group-N">` plus `<ul class="admin-nav-list"
  aria-labelledby="nav-group-N">`.
- Top bar: burger, breadcrumbs, then in this order (logical, so mirrored in RTL) the inbox, theme, content language
  and account, the three menus `<details class="admin-menu" data-menu>`. Its buttons never shrink (`.admin-burger` and
  `.admin-menu-trigger` are `flex: none`, 40×40, down to 320px). Below 768px the breadcrumbs are hidden
  (`.admin-breadcrumbs { display: none; }` in the phone query; still in the DOM, so shell.js making them inert under
  the drawer is harmless): the page header names the screen, and the drawer (without JS the plain sidebar above the
  page) leads back — on the pages whose trail is the only link to their parent (a menu item → its menu, a collection's
  or taxonomy's order → its list, revisions → the entry, a media folder form → its folder) that takes one more step,
  but none is a dead end, so there is no separate back link. Theme (`admin-theme-menu`, JS only: hidden
  without `.js`; its trigger shows the choice's icon alone, no chevron on any top-bar trigger; `shell.js` sets the
  radios' `.checked` from the stored choice, listens for `change` and updates the trigger's icon, `title` and
  `ariaLabel`): a `<fieldset class="admin-menu-list admin-theme-switch">` of `<label class="admin-menu-item
  admin-theme-option">` rows that look exactly like the language menu's items: the icon (`sun`, `moon`, `monitor`,
  muted), the label and the `check` icon at the end; the `<input type="radio" name="theme" data-theme-choice>` inside
  is visually hidden but focusable (arrows move within the group), the focus ring drawn on the row
  (`:has(> input:focus-visible)`); the checked row shares the current language's accent label
  (`.admin-menu-item:has(> input:checked)` beside `[aria-current="true"]`) and alone shows its check mark (every row
  keeps it, `visibility: hidden` otherwise). Content language
  (`partials/content-locale.php`, `admin-content-locale`, only with more than one site locale: the trigger shows the
  current language's round flag of the theme alone, an icon button as the inbox and the avatar (`languageFlag()`,
  `admin-locale-flag-large`, 22px; without a flag the `languages` icon and its pill), named "Content language: {language}";
  the panel lists the site's languages, each with its flag (`admin-locale-flag`), the check pushed to the end;
  the names in themselves (`languageName()`, `LanguageNames::endonym()`), each a plain POST form to
  `admin.preferences.content_locale` with the page's `return` (`requestTarget()`), the current one `aria-current` with a
  check; no script). Account (the avatar: the Google profile photo, an `<img class="admin-avatar-image" alt="">` of `/admin/avatar/{id}?v={users.avatar_version}`, else the initials; name, e-mail, role, then Profile and Sign out as `admin-menu-item admin-menu-item-icon` items, Sign out set off by an
  `<hr class="admin-menu-separator">`). First, before the theme, for `messages.manage` users, the inbox: a
  link `admin-menu-trigger admin-inbox` to `/admin/messages` with the `inbox` icon and, when there are unread
  messages, their count in `admin-inbox-count` (99+ past 99); named "Messages: N new" (`admin.layout.inbox`, the
  `unreadMessages()` request helper, null for others). Messages has no sidebar item.
  Triggers are 40px squares named by a `title` and an `aria-label` with the same text (the content language's
  without a flag is wider: the `languages` icon and its pill, `:has(> .admin-locale-badge)`). One menu opens at a time; a click outside or Escape closes it. The current content language
  is the layout's `contentLocale` (every per-language template passes the language it shows), else
  `ContentLocale::current()`. There is no per-screen language switch anywhere (Plan 21). In the linked mode (see User
  preferences) the menu is the admin's one language switch and is named "Language: {language}"
  (`admin.content_locale.language`, the `linkedLanguage()` helper); the separate mode keeps "Content language: …".
- Profile (`/admin/profile`, `profile/show.php`, `PreferencesController::show()`, Plan 34): the entry form's two
  columns (`admin-entry-form` > `admin-form-layout admin-profile-layout`, `--side-column`). The side column holds a
  read-only `admin-card admin-profile-card` outside the form, first in the markup and placed by `grid-column: 2;
  grid-row: 1` (on the left in Hebrew; reset under the `entry-form` container's 879px, so it stacks above the form) —
  the avatar `admin-avatar admin-avatar-large admin-role admin-role-{role}` (72 px: the Google photo, else
  `User::initials()`, with the role dot), the name (`admin-profile-name`), the e-mail, the role chip `admin-chip
  admin-role admin-role-{role}` and "Last sign-in: {date}" (`readableDate()`, "never" when null). The main column is
  the form `admin-profile-form` with one tab "Interface" (`data-tabs=""`, the tab list named "Profile settings";
  without JS the panel shows under its heading): "Interface language" (`languages` icon), the media dropdown of
  `InterfaceLanguages::ALL` (radio options `locale`, each with the theme's round flag `admin-locale-flag` from
  `languageFlag()`, else the `languages` icon, and named in itself with its `lang`/`dir` on the option's text), and
  "Admin color" (`palette` icon), the same media dropdown (`media/dropdown.php`, `media.js`) with radio options `accent`:
  first "Site color ({preset})" (value `''`, the site colour's swatch; stored as `NULL`), then the eight presets, each
  a swatch `admin-accent-swatch admin-accent-swatch-{preset}` (the `--swatch-*` tokens) and its name
  (`admin.accent.*`). One Save posts `POST /admin/profile` (`admin.profile.save`, `PreferencesController::save()`)
  and returns to the Profile with the toast "Preferences saved."; a refusal re-renders the Profile (422) with the
  summary alert, the error at its field, the tab's error badge and the posted choices. In the linked mode the tab
  holds only "Admin color": no interface language field, and `POST /admin/profile` ignores a posted `locale`
  (`users.locale` stays; `UserManager::savePreferences()` with a null locale).
- One site language: the admin shows nothing about languages (the `multilingual()` helper, `tests/Functional/Languages/SingleLanguageAdminTest`):
  no content language switch, no locale marks, no Languages columns (entries, terms), no Translations line, no
  Language column or "(English)" on the messages, and the delete confirmations of entries, terms and menu items
  without "({locale})" and "other languages stay" (`*.delete_confirm_single`). The Profile's interface language stays
  (a one-language site always behaves as the separate mode: `LinkedLanguage::enabled()` is false).
- Locale pills (`partials/locale-badge.php`: the theme's round flag of the language, `admin-locale-flag` from
  `languageFlag()`, else the code pill `admin-locale-badge`; nothing with one site locale): one pill by the
  title of a per-language screen or form (`partials/page-header.php`'s `locale`; the entry form, the term form and the
  Sections dialog title, the lists, the order screens, Sections, Menus, the menu item form); a pill per field only on
  forms that mix per-language and site-wide fields (settings, the media alternative texts). The pill is `aria-hidden`
  with a visually hidden twin of its title. The linked mode shows none of them (Plan 37, user decision: the screen and
  its interface speak that language already), except where the partial gets `keep`: the media's alternative texts
  (one input per language) and the top bar's trigger without a theme flag.
- Translations switch (`partials/translations.php`, `terms/translations.php`): on the right of the title of a saved
  entry or term form (the `aside` of `partials/page-header.php`; in a term dialog in the header, outside the
  dialog's form; the section dialog has none, a user decision of Plan 32, the full section page keeps it; tags have
  none at all, neither form nor dialog, Plan 35: see "Tags kept per language" below): a
  segmented control (a pill track on `--bg`), one segment per site language, the form's own as the raised segment
  (`admin-translations-current`: `--raised`, `--shadow-card`, the theme's flag when it has one, and the code), an
  existing translation a link (`?locale=`; in the dialog `data-dialog-open`) with a check, or plain text without
  the right to edit it, a missing one a small POST "+" form to the translate action ("{language}: copy for
  translation"; its twin segment with the `languages` icon, "{language}: machine translation", is rendered `hidden` for
  `translate.js`, see Machine translation), or "—" without the right. The
  "Translations" name is visually hidden; each segment names its language for screen readers. Separate mode only: the
  linked mode renders none on any form or dialog (Plan 37, user decision: only the top bar changes the language; the
  translate actions stay for the top bar's "Copy for translation" offer). The lists' Languages column
  (`partials/flags.php`, plain flags, never links): the entry lists show it in both modes (user decision after Plan 37,
  reversing its removal for entries: the flags only tell which translations exist, the top bar still switches the
  language); the term lists show it in the separate mode only (Plan 37, user decision).
- Tags kept per language (Plan 35, user decision; reverses the Plan 32 note that tags keep their Translations
  line): a taxonomy filled only by tags fields (`Collections::onlyTagged()`, the same rule that drops its "Add new")
  offers no translating — no Translations line on its term form or dialog (`$translatable`), the translate action
  answers 404, `TranslationOffers::termById()` makes no "Copy for translation" offer, and the content language switch
  from one of its term pages goes to that language's list (no `?translate=`). Every other taxonomy keeps all of it.
- Content `lang`/`dir`: inputs holding content carry the language's `lang` and `dir` (`langAttrs()`,
  `I18n\TextDirection`), whatever the interface speaks: the entry's title, search-engine texts and text widgets (the
  `widget()` helper's `locale`, `$lang` in the widget templates), term names and SEO texts, menu item labels,
  per-language settings, alternative texts. Slugs, addresses and site-wide values keep the interface's. CKEditor
  gets `language.content` from the form's `data-content-locale`.
- Page data: `$this->layout('layouts/admin', ['title' => …, 'breadcrumbs' => [[label, url], …, [label]]])`. The trail
  starts after the dashboard; the last item is the current page; without a trail the page names itself.
- Messages (a user decision of Plan 13b): every success and error message is a toast; the only exception is field
  validation, whose errors stay at their fields together with the form's summary alert (`admin-alert`, which only
  counts or repeats those field errors). Any refusal without a field of its own (a stale version, a refused tree, a
  refused delete, a gone item) is an error toast, never an `admin-alert` (Plan 17 converted the last screens: the
  delete confirmations, the entry, term and menu item forms). Second exception (a user decision of Plan
  15): a successful autosave has no toast; the entry form's action bar status says "Saving…" / "Saved N minutes ago"
  instead. Its failures are error toasts.
- Toasts: `<div class="admin-toasts" data-toasts aria-live="polite">` in `<main>` (always present) holds the flashes
  rendered with `partials/toast.php` (`admin-toast-success` / `-error`, close button `[data-toast-close]`; successes
  have no role, errors `role="alert"`; an optional `action` label adds a small button `[data-toast-action]` under the
  text, in `admin-toast-body`, which the script showing the toast wires), followed by the page's own messages: a
  template passes `toasts` (a list of `FlashMessage`, e.g. `FlashMessage::errors($errors, $params)` for a refused
  form post of an order screen, `FormErrors::split()`'s field-less errors of a form) to the layout. The stack is the
  live region, so a script that reports a result inserts its toast into the stack (errors are alerts as well). A live
  region announces changes only: for the toasts present at page load the layout renders an empty visually hidden
  `role="status"` region `[data-toast-announcer]`, into which `shell.js` writes their texts (errors first,
  `textContent`) 500 ms after start (`ANNOUNCE_MS`); the toasts themselves stay (a screen reader that reads a load-time
  `role="alert"` may read an error twice). Scripts never build a toast: they clone a server-rendered `<template>` of
  `partials/toast.php` (`data-upload-toast` in `media.js`, `data-tree-saved`/`data-tree-failed` in `tree.js`, `data-machine-done`/`data-machine-failed` in `translate.js`) and set
  only its text through `textContent`. Under `.js` the empty stack stays
  rendered (zero height) so the region exists before its first toast; only without JS is it hidden when empty.
  `shell.js` `wireToast()` hides a success after 6s (hover/focus pauses), errors stay; a `MutationObserver` on every
  stack wires toasts added later. Under `.js` the stack is fixed below the top bar; without JS toasts stay in place.
- Validation: the form's summary `admin-alert` (`role="alert"`, `admin.form.fix_fields`) plus `admin-field-error` under
  each field and `aria-invalid`/`aria-describedby` on the input (on each radio of the status and each checkbox of a
  term picker, never on a `fieldset` or group; the media widget's ids input for the sharing image; a field widget's
  primary control, with the error line `field-{name}-error`; the term form's name, address and parent as
  `{name}-error`). The summary counts only the errors of inputs the form shows: `AtarCms\Admin\FormErrors::split()`
  turns the rest into the layout's error toasts — a form-wide error (entry, term, item, locale: a stale version, a gone
  item, an unsupported language) and an input the form does not show (a crafted post: a parent on a flat collection or
  taxonomy, a term's order; `FormLayout::$unplaced` on the entry form, `TermForm::fields()` on the term form, a fixed
  list on the menu item form). They count on no tab. In the Sections dialog such a refusal answers `HX-Redirect` to
  the screen with them as error flashes; field errors come back into the dialog.

## Screens

- Lists (entries, terms, users) share a table in `admin-table-card`, rows `admin-table-row` with an
  `admin-row-link` whose `::after` covers the row (other row controls sit above it), and below 768px each `td` shows
  its `data-label` (the audit list keeps the card and the `data-label`s but plain rows without a row link; its
  details column is a `<details class="admin-details">` with the entry's JSON meta). Per list:
  - entries: `admin-filters` GET form keeping `sort`/`dir` as hidden inputs (`data-list-filters`: `list-filters.js`
    submits it through htmx on a dropdown change and 300 ms after typing 2+ characters in `data-list-search` or
    emptying it; htmx swaps only `#entries-results` (count, table, pages) and pushes the URL; the Filter button
    `admin-list-apply` is hidden under `.js`), dates in words (`readableDate()`: the interface language, the site
    zone; in a table cell `partials/table-date.php`: the day on one unbroken line `admin-table-date`, the time under
    it, smaller, `admin-table-time`; also the media and messages tables), sortable headers `admin-sort` (the
    order the list is in shows in its header: the chosen one, else `EntryListing::defaultSort()`, the latest update
    first, or a hierarchical collection by position) +
    `aria-sort`, status chips `admin-chip-{status}`, the lock chip `admin-chip-preset` of a preset page, pagination, empty state;
    the main photo (Plan 42, user decisions): in a collection with main photo fields (`Collection::shareImageFields()`
    not empty: `shareImage(...)`, else the first image and the first gallery field) the title cell is
    `admin-entry-title-cell` (flex: the picture first, then `admin-entry-title-text` with the row link and the slug;
  its `td` has no `data-label`, so a phone card starts with the picture and the title),
    the Media column (user decisions) right after the title, only in a collection with a gallery or YouTube field:
    `admin-entry-media` with one 22px `admin-entry-media-item` (`role="img"`, `aria-label` and `title`) per extra
    medium the row's own values hold, a filled gallery (`images`, `admin-entry-media-gallery` in `--swatch-blue`,
    `admin.entries.has_gallery` with the photo count) and a filled YouTube field (`video`,
    `admin-entry-media-video` in `--swatch-cherry`); a video in the text does not count; the items sit above the
    row link so their tips show,
    the picture the entry's main photo exactly as the share card takes it (`MainPhoto`: the first candidate that is an
    image) as a 40px square `<img class="admin-entry-thumb" alt="" loading="lazy">` of the `admin` variant
    (`mediaThumb()`, decorative: the row link names the entry), else the placeholder `admin-entry-thumb
    admin-entry-thumb-empty` (the same box, dashed `--border-strong`, the muted `image-off` icon); one media query
    for the page (`ShareImages::mainPhotos()`); a collection without such fields, or one that turned the main photo off
    with an empty `shareImage()`, keeps the plain title cell; on phones the title line carries its picture; a collection routed
    `/{taxonomy}/{slug}` adds a column with each entry's term of that taxonomy (its section), headed by the taxonomy's
    label, and its term filter offers only that taxonomy's terms, flat and indented (`— ` per level), labelled with the
    taxonomy's label (no `<optgroup>`); a term with children filters by its whole branch (the term and its descendants,
    read from the flattened options); the columns end with Status and then Languages, each language of the translation
    group a round flag of the theme (`languageFlag()`: `assets/flags/{code}.png`, `admin-flag`, named in itself as `alt`
    and `title`), or its code (`admin-flag-code`, an `<abbr>`) when the theme has none;
  - audit: pagination and empty state; actions in words (`admin.audit.action.{action}`, the code as the `title`; an
    action without a key, a module's, shows its code), items by name (`AuditSubject::name()`: an entry's title, a
    file's name, a user's e-mail…; `admin.audit.subject.*` names the kind, else "kind #id");
  - terms (not the sections; Plan 35, user decision: a list like the users'): empty state, a name search (flat taxonomies, below), no
    pagination; the name is the row link and the last column (`admin-table-actions`, a visually hidden "Actions"
    header) holds Edit (`pencil`) and Delete (`trash-2`, red) `admin-row-action` links named like the tree's; the
    name, both actions and "Add new" open the term dialogs (`data-dialog-open`, `dialog.js`, `terms/dialog.php`,
    `terms/delete-dialog.php`; the full pages without JS); "Used in" is a count pill `admin-uses` (the media list's
    accent pill, titled "Used in N entries", empty when unused); Languages are the theme's flags, no Languages column for a tags-only taxonomy (tags are kept per language)
    (`partials/flags.php`, shared with the entries list). A taxonomy filled only by tags fields
    (`Collections::onlyTagged()`: some `Field::tags()` edits it, no `taxonomies()` lists it) has no "Add new" (header
    and empty state) and no line about it (user decision); its create form stays reachable by its address. A flat
    taxonomy's list has the entries list's search (`admin-filters` `data-list-filters`, "Search by name" `terms-q`,
    `list-filters.js`, htmx swaps `#terms-results`, the count line and the filtered empty state "Nothing matches
    these filters." inside it; the no-JS Filter button): `?q=` (100 characters) through `TermRepository::search()`
    (`name LIKE ?`, wildcards escaped, the column collation: case- and accent-insensitive) in the list's language;
    a hierarchical taxonomy keeps its tree without one and ignores `q`. A save or delete in the dialog returns to
    the list as it was opened (`Dialog::back()`: htmx's `HX-Current-URL` when it is the same list, its query kept
    with the list's `locale` winning, `translate` dropped; anything else gets the plain list);
  - users (Plan 33): no filters, pagination or empty state; a role chip `admin-chip admin-role admin-role-{role}` (the
    default roles coloured by `--role-{admin|editor|author}` and `-soft`, any other role neutral; the top bar avatar
    carries the same classes and shows the role as a dot) and `admin-chip-{active|disabled}`; the last
    sign-in as `partials/table-date.php` ("never", `admin-muted`, without one); "Add user" (the page header's
    action with `dialog: true`), a row's e-mail (`admin-row-link`) and its Edit open the screen's dialog
    (`data-dialog-open`, `dialog.js` and the empty shell on the list; without JS the full pages). The last column
    (`admin-table-actions`, a visually hidden "Actions" header) holds Edit (`pencil`) and Delete (`trash-2`, red),
    icon links of the shared `admin-row-action` classes (also the tree's), named like the tree's
    (`admin.tree.action.{edit,delete}` with the e-mail). An action one may not take is a locked button with its
    reason (`admin.users.locked.*`): one's own Delete; Edit and Delete of a user whose role one cannot grant (whose
    e-mail is plain `admin-row-title`). Delete asks in `users/delete-dialog.php` (a full page `users/delete.php`
    without JS, Cancel back to the user's form; the full edit form has a red Delete link, not for oneself); a done
    or refused delete (oneself, by a forged post) and one's own confirmation URL go to the list with the toast.
- Pagination: `partials/pagination.php` (`$page`, `$pages`, `$url`, optional htmx `$target`, used by the media picker)
  over `Pagination::window()`. Empty states: `admin-empty` (icon, sentence, primary action when allowed).
- Dashboard (Plan 36; `dashboard.php`, `dashboard/*.php`, data from `Admin\Dashboard\DashboardData::for()` into the
  read model `Dashboard`; the whole page is the container `admin-dash`, its two-column rows stack below 880px of it).
  Each block shows only for whom it applies, and the rows adapt (`admin-dash-split` gets `admin-dash-split-two` only
  with two cards; the tiles and cards rows are `auto-fit` grids; every card's `admin-dash-title` leads with its icon:
  Activity `chart-no-axes-column`, Content `layers`, Translations `languages`, Popular tags `tags`, Latest messages
  `inbox`, In progress `pencil-line`, Recent activity `history`):
  - header `admin-dash-header`: "Hello, {name}!" and today's date in words (site time, the interface language);
    the quick start `admin-dash-quick` (a `nav`, "Quick start"): a pill per collection the user may add to (a single:
    "Edit", a plain single only with `canEditPublished()`), its `plus`/`pencil` in a circle of the collection's tone,
    named "Add new: {label}" / "Edit: {label}" (`aria-label` containing the visible label);
  - tiles `admin-dash-tiles` ("At a glance"): Published (`eye`, the accent; public entries of the viewable
    collections in every language, singles excluded, "+N in 30 days", a 30-day sparkline of publications), Drafts and scheduled
    (`file-text`, the warning colour; a `dl` of both counts), Unread messages (`messages.manage`; `inbox`, tone 6; "N
    received in 30 days" and its sparkline; the label links to the inbox), Photos and files (`media.upload`;
    `images`, tone 5; the file count and the storage used; links to the library). Sparklines (`admin-spark`, SVG,
    `aria-hidden`: the note says the same in words) fill the tile's bottom edge; a tile's label link covers the tile;
  - "Activity" (`#dashboard-activity`, viewable collections only): edits (stored versions) and publications per day,
    per week over 90 days (13 columns), as bars (`admin-chart-bar-{edits|publications}`: publications the accent,
    edits `--edits`, the accent mixed with `--chart-wash`), the legend with each total, the scale's top and middle
    values outside the plot at its start; the range switch `admin-dash-range` (7 / 30 / 90 days, plain GET links
    `?days=` with ids, `aria-current="true"` on the current one; htmx swaps only the card with `hx-select`, pushes
    the URL and keeps the focus on the link by its id). The SVG (`admin-chart-svg`, `preserveAspectRatio="none"`,
    `aria-hidden`) stretches to the plot; the HTML columns `admin-chart-hits` (one per bar pair, flex, `aria-hidden`)
    carry the `title` tips ("Oct 3: 5 edits, 1 publication") and align with the bars at any width; a visually hidden
    `<table>` (caption, newest first) holds the same numbers; the axis labels every fifth day (every third week),
    every second one hidden on a narrow card (`admin-chart-axis-minor`); with no data a quiet line inside the plot;
  - "Content" (`#dashboard-content`): a row per viewable collection but singles, its tone dot, its list link, its
    total and a bar split by status (`admin-dash-seg-{published|scheduled|draft}`: the accent, the warning colour,
    `--faint`; a 2px surface stroke between segments) on the largest collection's scale; the numbers are the row's
    title and visually hidden text;
  - cards `admin-dash-cards`: Translations (multilingual sites with a viewable translatable collection; per site
    language its flag or code and "N of M" translation groups, a bar of tone 4), Popular tags (when a viewable
    collection has a tags field; the content language's top 8 by viewable entries, bars of tone 2, the names in the
    content language's `lang`/`dir`), Latest messages (`messages.manage`; the newest 3, unread ones bold with an
    accent dot and a hidden "(Unread)", each row a link to the message, laid out in the interface direction with the
    visitor's name and text in `<bdi>`; "All messages");
  - "In progress" (`#dashboard-progress`): one card with the forms' tabs (`data-tabs="admin.tabs.dashboard"`,
    `tabs.js`, remembered): Recently updated / Drafts / Scheduled, 5 entries each (`DashboardData::PER_LIST`), the
    drafts and scheduled totals as `admin-tab-count` pills, each row with its collection's dot (without JavaScript
    the three lists under their headings); beside it "Recent activity" (`audit.view`): the 6 newest history rows with
    the avatar (photo or initials and the role dot; a gear for rows without a user), the name, the action and the
    item in the history screen's words (`audit/subject.php`, shared) and the time; "Open the activity history".
  Times in RTL: the server lays out every SVG from the right (`I18n\TextDirection` of the interface language), the
  HTML columns follow the direction by themselves; nothing is mirrored by CSS.
- Machine translation (Plan 38, user decisions; `translate.js`, `Admin\MachineTranslation`,
  `partials/machine-translation.php`): wherever a missing translation is offered ("Copy for translation": the offer
  toast, the separate mode's Translations line) the same form has a second submit button `name="machine"
  value="1"` with `data-machine-offer`, `data-machine-from` (the item's language) and `data-machine-to`, rendered
  `hidden` ("Translate (machine translation)" with the `languages` icon; in the line the twin segment). In the toast
  (a user decision after Plan 41) both actions are one wrapping row of `admin-button-compact` buttons under the text,
  Translate first: visible labels are short (`admin.machine.offer_short` "Translate", `ToastForm::$shortLabel`
  `admin.content_locale.create_short` "Copy"), the full labels are their `aria-label` and `title` (tooltip).
  Translate is `admin-button-primary`; Copy (`admin-toast-copy`) is primary too, and only while the twin is shown
  (`.admin-toast-form:has(> [data-machine-offer]:not([hidden]))`) it turns link-style (no border or background, the
  accent text, `--accent-soft` on hover and focus-visible): without JavaScript or a translator Copy is the one primary
  action, by CSS only. The Translations line's segments keep their own style. `translate.js`
  shows it (`hidden = false`) only where Chrome's on-device Translator API exists (`Translator` global, feature
  detected) and `Translator.availability()` of the pair is `available`, `downloadable` or `downloading`; Firefox,
  Safari, phones and no JavaScript see the copy only. The translate action with the marker opens the copy's form with
  `?machine={source}&start=1` (the linked top bar's single copy with `?machine={source}`). That form (entry form,
  full term and section form; not the term dialog, not tags) renders, inside the form above its columns, the panel
  `admin-machine` `data-machine` (hidden; `data-machine-from`/`-to`, `data-machine-start`, `data-machine-slug` for a
  draft entry that is not a single or a preset page, `data-machine-fields` and `data-machine-slides` as JSON names
  from `MachineTranslation::forEntry()`/`forTerm()`: no title for a preset page's copy, which carries its label in its
  language already; a term's copy only its name): the `languages` icon, the note `admin-machine-note`
  ("Machine translation from {language}: check the texts before saving or publishing."), the button "Translate from
  {language}" `[data-machine-run]`, a `<progress class="admin-machine-progress" data-machine-progress hidden>` (the
  model download, then the share of the texts) and the two toast templates. The script shows the panel for the same
  states; the click creates the translator (the user gesture a download needs); with `start` and an `available`
  model it starts by itself; `start` leaves the address bar at once and `machine` after a translation
  (`history.replaceState()`), so Back or a reload never translates again. Texts: the named inputs and textareas by
  value (read-only while it runs), the slides' title, subtitle and button text (`slides.js` refreshes the row on
  `input`), rich text through `editor.js` (`rich-text-translate` on its textarea, the editor read-only meanwhile):
  block by block, each element holding text (not a code block) as its inline HTML (`editor.data.stringify()`),
  parsed back by `editor.data.parse()` and taken only with exactly the block's markup (the same inline elements,
  texts only with attribute sets the block had: no new element or attribute, no changed link address), else, and
  for a block with inline code, its text nodes one by one; all blocks in one `model.change()` (one undo step). A
  value without a letter or that looks like an address, e-mail or domain stays. All or nothing: every text first,
  then `machine-translated` on the form (`autosave.js` stops autosaving for the page view, so only Save stores the
  translation and leaving asks first; the term and section form gets the same leave warning from `translate.js`),
  then the editors (one that fails undoes the others), the plain values with `input`/`change`, the emptied address
  (the draft's Save makes it from the translated title), the button hidden and the success toast; a failure applies
  nothing and shows the error toast. The term dialog's machine segment is shown once htmx loads the dialog
  (`htmx:afterSettle`). The copy itself is made first and keeps its state (user decision): an entry's copy is a
  draft, but a plain single's, a preset page's, a term's and a section's copy is public at once, so until it is saved
  after translating, the site shows the copied source-language text in that language.
- Messages (Plans 23 and 52, `messages/index.php`, `show.php`, `delete.php`; opened from the top bar's inbox):
  with more than one site form a row of plain links filters by form (`admin-filter-tabs` nav named "Forms", links
  `admin-filter-tab` with `aria-current="page"` on the current one and the unread count as `admin-tab-count`;
  `?form=key`, works without JavaScript); the list table (sender — the name, else the e-mail, else the phone — with
  `admin-message-email`, the form badge `admin-chip admin-chip-form` in the unfiltered list, `admin-message-preview`
  of the first line or the fields' values, language, received; unread rows `admin-message-unread` with
  `admin-chip-unread`), 30 per page, an empty state; a message page (`admin-message` card: `admin-message-meta` grid
  with the form, the fields table `admin-message-fields` in the form's order (`MessageFields`), `admin-message-body`
  keeps the visitor's lines and own direction) marks it read and has Call (`tel:`, with a phone), Reply (`mailto:`,
  with an e-mail), Mark as unread (POST) and Delete… (a confirmation page like menu items); results are toasts.
- Entry form: `EntryForm::layout($collection, $values, $errors, $canPublish, $preset)` returns `FormLayout` (`main`, `side`,
  `unplaced`) of `FormTab`s (`name`, `builtins`, `fields`, `leadFields`, `errors`, `count`). Main: `content` (title +
  default fields), `media`; side: `settings` (what an editor picks first on top, a user decision: tags fields
  (`leadFields`), terms, then status, published_at (only with the right to publish), parent and order + the other
  `settings` fields), `seo` (the sharing preview `share`, meta title/description, slug last); there is no Social tab
  (Plan 20: the sharing image is generated); the tab of each built-in input is `EntryForm::BUILTIN_TABS`. The `share`
  block (collections with a route, not plain; the template's `$shareImage`) is the SEO tab's first child and shows only
  the saved entry's generated card (user decisions after the Plan 20 check): `admin-share-preview` with the image
  (`admin-share-image`, `/uploads/share/…`, `width`/`height` 1200 × 630, at most 480px wide) and one `admin-help` line
  (`admin.form.share.generated`: made from the main photo and the title for search engines, or the entry title when
  that is empty); no heading, no link preview card, and nothing at all without a generated card (no main photo, a
  title with nothing to draw, a new entry). It shows the saved state (it changes after saving).
  Fields choose with `Field::tab('content'|'media'|'settings')`
  (`Field::TABS`); empty tabs are dropped, `content` and `settings` always stay. The tab lists are named
  `admin.form.sections_main` / `_side`. The page form's "Order" input explains itself (`position_help`); the term form
  has none (a new section goes last, the order screens move it).
  A plain single (`Collection::plain()`) has no built-in input: one column (`admin-form-layout-wide`), only the
  fields; the Translations line stays. A field's `help()` is an `admin-help admin-field-help` line under its widget; a
  rich-text field (`admin-field-rich-text`, a two-column grid with `grid-auto-flow: row dense`) puts it on the left of
  CKEditor's word count (`:root .admin-field-rich-text > .ck.ck-word-count { grid-column: 2; }`), with no JS change. A
  tall field (`Field::tall()`, `admin-field-tall`) gets a 28rem editor and a 24-row fallback.
  A slides field (Plan 30, `Field::slides()`, user decision) never sits among the other fields: `EntryForm::layout()`
  appends one main tab per slides field after `content`/`media`, named `slides-{field}` (ids `tab-slides-{field}`,
  `panel-slides-{field}`) and labelled by the field's label (`FormTab::$field`; the built-in tabs keep
  `admin.form.tab.*`), whatever its `tab()`; its errors count there. So a plain single with one (the skeleton's home
  page) shows "Content | Slides" tabs in its one wide column; without one it stays tab-less. The field's own label
  line is left out (`fields/field.php`, like a boolean): the tab and the list (`aria-label`) name it.
- Slides widget (`fields/slides.php`, `slides/item.php`, `slides/dialog.php`, `slides.js`, Plan 30): one
  `admin-slides-item` per slide (`SlidesType::rows()`, lenient: the stored list or the posted rows, ordered, deleted and
  empty rows out). Without JavaScript each is an `admin-slides-fieldset` with the inputs
  `fields[{field}][{i}][image|video|title|subtitle|url|button|position]` (the image and the optional video as their
  numbers in "Photos and files", the thumbnail beside them), an order number and a delete box (`[delete]`), plus one empty "New slide" group
  (`admin-slides-blank`); `SlidesType::normalize()` sorts by the order numbers and drops deleted and empty rows. Under
  `.js` the fieldsets and the empty group are hidden from the first paint and each slide is an `admin-slides-row`
  (`display: none` without `.js`, listed in `HIDDEN_WITHOUT_JS`): the drag handle (SortableJS), the thumbnail
  (`admin-slides-preview`, the `admin` variant; a slide with a video adds `admin-slides-video`, a round `video` badge
  over the thumbnail's corner, `role="img"` named "Video: {file}" by `aria-label` and `title`, hidden without a
  video; `slides.js` sets them as properties from the widget's `data-has-video` and the row's `data-video-file`), the title (or "Untitled slide") and the muted subtitle (ellipsised,
  `unicode-bidi: plaintext`), then 40px icon buttons Move up, Move down (disabled at the ends), Edit and a red Delete
  (no confirmation: nothing is saved before Save). `slides.js` removes the empty group, unhides "Add slide"
  (`admin-slides-add`, disabled at the field's maximum, `data-max`) and keeps the order inputs, the moves and the empty
  state (`admin-slides-empty`) current. Add slide and Edit open the field's `<dialog class="admin-dialog
  admin-slides-dialog" data-slides-dialog="{field}">`, rendered after the entry form (its close button is a
  `method="dialog"` form, which cannot nest in the entry form) in the shared layout: the media widget
  (`media/field.php`, single image, kind `image`; `media.js` opens the picker above it), the optional Video under it
  (Plan 31: the same widget with kind `video`, so its picker lists and uploads into the video group only, and a help
  line: it plays instead of the picture, which is the poster; a chosen video's chip card IS a player
  (`admin-media-video`, `controls preload="metadata"`, no autoplay, no name — the card widens, the remove button
  keeps its corner; `media/field.php` renders it for `kind` `video`, `media.js` and `slides.js` build the same card
  from the picker tile's `data-url` or the row's `data-video-url`, the name travels in `data-name`, and closing the
  dialog pauses it); each widget sits in a `data-slides-media="image|video"`
  field; the server refuses a non-video with `validation.slides.video`), title, subtitle (a single-line input: line
  breaks would be lost), link (`dir="ltr"`)
  and button text with help lines, Cancel and Done. Its inputs have no names; Done (an image is required: an
  `admin-field-error` at the image, `ariaInvalid`) copies them into the slide's inputs (a new slide is the widget's
  `<template data-slides-template>` with `__index__` replaced by `data-next`), the row's texts (`textContent`) and the
  picked thumbnail (cloned), then focuses the slide's Edit; Cancel, Close and Escape drop them and focus the opener. No
  request is sent: every change fires `change` on the widget (autosave and "unsaved changes" hear it), and preview and
  autosave post the rows like any field. The server answers a refused save with the rows as posted, the error under the
  widget (`aria-describedby` on `admin-slides`).
- Tags widget (`fields/tags.php`, `tags/pill.php`, `tags.js`, Plan 35, user decisions: our own control after Tom
  Select's tagging example, no library): the text input `fields[{name}]` (`admin-tags-value`, comma-separated names)
  is the posted value and, with its help line (`admin-tags-help-plain`), the whole widget without JavaScript. Under
  `.js` both are hidden from the first paint and the server-rendered tag box `admin-tags` (`display: none` without
  `.js`, in `HIDDEN_WITHOUT_JS`; the content language's `lang`/`dir`) shows: the frame `admin-tags-box` (the input look;
  it draws the focus ring and, in `admin-field-invalid`, the danger border) holds the pill list `admin-tags-list`
  ("Chosen tags", a pill `admin-tags-pill` per chosen tag, its × `admin-tags-remove` named and titled "Remove “x”"),
  the query `admin-tags-query` (`id="field-{name}-query"`, no `name`, `maxlength` = `TermService::MAX_NAME`, an ARIA
  1.2 combobox: `aria-autocomplete="list"`, `aria-expanded`, `aria-controls` the listbox, `aria-activedescendant`
  through `ariaActiveDescendantElement`, `aria-describedby` the help line, the limit line and the error) and the popup
  `admin-tags-listbox` (`role="listbox"` "Suggestions", absolute under the frame; options `admin-tags-option`, the
  active one `admin-tags-option-active` with `ariaSelected`); then a visually hidden `role="status"` span, the help line
  `field-{name}-help` (with `->max()` "Up to N tags.") and, with a max, the limit line `admin-tags-full` (hidden and
  empty until full: the query names it in `aria-describedby`, which reads a hidden element's text too) and three `<template>`s (pill, option, "Add “x”" option `admin-tags-create`). The suggestions are the
  taxonomy's tags of the entry's language, by name, in `data-tags-names` (JSON; `EntriesController` passes them as the
  widget's `options`); matching folds case and accents like `utf8mb4_unicode_ci` (NFD without marks, lower case),
  those that start with the query first, at most 50, the chosen ones left out. An existing tag keeps its spelling, a
  chosen one is ignored ("is already chosen"), a full box takes no more (the query `readOnly`, the limit line shown).
  "Add “x”" comes first for a name no tag has; after typing (or ArrowDown) the first tag that starts with the query
  is highlighted (user decision), else "Add" (only "contains" matches never win Enter), and a press in the frame
  highlights nothing. Keys: the list opens on typing, ArrowDown and a press in the frame (not on keyboard focus); Enter
  adds the active option or the typed text, closes a list with nothing active, and with an empty query and a closed
  list keeps its default (Save); a comma (in the `input` event) and a paste with commas or line breaks add each part
  (the remainder cut to `maxlength`); nothing happens while an IME composes (`isComposing`, keyCode 229); blur (Tab, a
  click on Save) adds what was typed, but not when the window itself loses the focus (`document.hasFocus()`); Backspace on an empty query removes the
  last pill; ArrowUp/ArrowDown move; Escape closes, then clears; × removes and focuses the query. The script keeps the
  carrier equal to the pills (", "), fires `input` and `change` on it after each add or remove and stops the query's
  own events (typing alone is no change for autosave), moves `required` from the carrier to the query (while no pill),
  points the field label at the query (`htmlFor`) and rebuilds the pills from the carrier on load. The server marks
  both the carrier and the query with `aria-invalid`.
  A preset page (Plan 29, `Collection::presets()`, `Entry::isPreset()`) is an ordinary entry form without the status
  and publication date inputs (always published, like a plain single: saving needs the publish permission), its
  address shown `readonly` with `aria-describedby` to its help line (`admin.entries.preset.slug_help`, id
  `slug-preset`), and a locked Delete in the action bar (`admin-locked`, `aria-disabled`, the reason
  `admin.entries.preset.delete_locked` as `title` and through `aria-describedby="entry-delete-reason"`). In the list
  its Author cell (a preset page has no author) holds the lock chip `admin-chip admin-chip-preset` (the `lock` icon, "Preset", the explanation
  `admin.entries.preset.title` as `title` and visually hidden after the word; above the row link, so the tip shows).
  The server ignores a posted slug or status for it and refuses its delete (`validation.entry.preset`).
- `tabs.js`: each column `[data-tabs="admin.tabs.{collection}.{main|side}"]` becomes an ARIA tablist (arrows, Home,
  End) remembering its tab under that `localStorage` key; a tab with server errors wins; the first invalid control on
  submit opens its tab. Draft hides the date (`[data-status-date]`); choosing Published fills an empty date from the
  input's `data-now` (site time). Without JS every panel shows under its `admin-tab-heading`. The server opens the
  first tab with errors of each column, else the first (`aria-selected`, `admin-tab-panel-open`); until `tabs.js` has
  set a column up (`admin-tabs-ready`, added to the column by `tabs.js`) only the `admin-tab-panel-open` panel shows,
  so the page does not flash every panel. Trade-off: if `tabs.js` fails to load with JS on, the other panels stay hidden.
  An empty key (`data-tabs=""`, the section form, the map dialog) remembers nothing: the tab the server opened
  (`aria-selected="true"`) shows. Elements of a column outside its panels marked `data-tab-for="{tab}"` (the map
  dialog's footer buttons) show only with that tab (`hidden`). `tabs.js` sets up every `[data-tabs]` column
  that is not `admin-tabs-ready` on load and again on `htmx:afterSettle`, so a form the dialog loads gets its tabs.
- Contact page (Plan 26 and its addendum; `Settings\ContactPage`, `content.contact_page`, a routed plain single, so
  never an autosaved draft): its entry form's side
  column ends with three tabs, Address | Contacts | Social (`SettingsForm::CONTACT_TABS`, `contactFields()`, the
  `settings/field.php` widgets with the input names of the old settings tabs: `address`, `opening_hours` with the
  language badge, `phone`, `email`, `whatsapp`, `viber`, `telegram`, the switch `chat_button` after the messengers (Plan 47), `social_*`), shown only to who may save the
  page's published entry (`EntryAccess::canEditPublished()`, a deliberate collection-level right, no `edit_others`:
  the details and the map are site-wide and the page's entry may not exist yet; others see neither the tabs nor the
  map, and the map routes answer 403). A plain single then gets a side column (no `admin-form-layout-wide`). The
  entry form's Save checks them first (`SettingsService::checkContacts()`; a refusal stays at its field, counts on
  its tab and in the summary, and nothing is saved), saves the entry, then writes them (`saveContacts()`; no nested
  transactions). The Address tab ends with the map (`map/panel.php`, `admin-map-panel`, `#contact-map`, no card of
  its own): the site's one map as an `admin-map-frame` iframe (4:3, the interface language, `sandbox`,
  `loading="lazy"`) or `admin-map-none` ("No map yet"), then "Add map", or "Change" and a red "Remove map" on one
  row (`admin-map-actions`), `data-dialog-open` links to `/admin/contact-map` and
  `/admin/contact-map/delete`. The column is links only, so it can sit inside the entry form; the page's
  `<dialog data-dialog>` (with its `<template data-dialog-toast>`) follows the entry form, and `dialog.js` is loaded.
  The dialog (`map/dialog.php`): a tab per way (`admin.map.tab.{embed,link,address,coordinates}`, `data-tabs=""`,
  ids `tab-map-*`/`panel-map-*`), each panel its own `admin-map-form` (`#map-form-{way}`, hidden `method`, the inputs
  of `map/fields.php`, `map-{input}` ids, `dir="ltr"` but the address), the footer's Cancel and one "Save map" per
  form (`form="map-form-{way}"`, `data-tab-for`); the server opens the way the map was entered (else the first, or the
  refused one with its summary alert and errors at the fields). A save or a removal answers htmx with the new column
  (`HX-Retarget: #contact-map`, `HX-Reswap: outerHTML`) and the `atar-dialog-done` event (see Dialog), so the entry
  form's unsaved text stays. Without JavaScript `map/form.php` (a card per way, each with its Save map) and
  `map/delete.php` are full pages that redirect to the entry form with a flash.
- Section form (Plan 14; `terms/tabs.php`, only for the sections, `Taxonomy::isRootTree()`): one tab column "Main |
  SEO" (`admin.form.tab.main`, `admin.form.tab.seo`, the list named `admin.sections.form_tabs`) with the entry form's
  markup (`tab-main`/`panel-main`, `tab-seo`/`panel-seo`, badges, `data-errors`); Main holds `terms/fields.php`, SEO
  `terms/seo.php`: the title (`meta_title`, 255) and description (`meta_description`, 500) for search engines, each
  with one short `admin-help` line and `aria-invalid`/`aria-describedby` on an invalid input (the title's help does
  not explain `{title}` and nothing previews what an empty text inherits: user decisions; `{title}` is explained in
  the skeleton HANDBOOK). The server opens the first tab with errors, else Main; the tab is not remembered, so the dialog
  always opens on the name. On the full page each panel is an `admin-card` (no outer card); in the dialog the panels
  sit in the dialog. The full page and the Sections screen load `tabs.js`; `dialog.js` focuses the first invalid
  `input`, `select` or `textarea`. Other taxonomies keep the single-card form without tabs.
- Term pickers (`entries/terms.php`, Settings tab): a `role="group"` of checkboxes indented with `admin-depth-{n}`; the
  taxonomy of the route's placeholder (`Collection::$routeTaxonomy`, sections) is instead one required
  `<select id="terms-{taxonomy}" name="terms[{taxonomy}][]">` shaped as the tree (`— ` per level, terms with children
  `disabled` unless chosen, a "Choose…" option whose empty value posts no term), with `aria-invalid`/`aria-describedby` on the select.
  No script and no new class.
- Action bar `admin-action-bar`: sticky, rounded card at the bottom of the form; status text on the left ("Saved …" /
  "Not saved yet"), buttons right in the order Delete… (danger link; never on a `single()`, a fixed page such as the home page or the contacts: `EntryAccess::canDelete()` refuses it), Earlier versions, Preview, Save (primary).
  Preview (collections with a route) is a submit button with `formaction` (`admin.entries.preview[.new]`),
  `formtarget="atar-preview-{collection}-{id|new}"` (one tab per entry: pressing again refreshes it) and
  `formnovalidate`: the unsaved form rendered by the theme (`EntryPreviewController`), also without JS. A visually
  hidden Save is the form's first child, so Enter saves instead of previewing.
- Earlier versions (`entries/revisions.php`, `EntryActionsController::revisions()`/`restore()`): the entry's stored
  versions as a plain table in an `admin-table-card` (numeric time with an "· autosave" marker where one was, author,
  title; `admin-empty` with the `history` icon when there are none), breadcrumbs collection → entry → Earlier
  versions and an Edit action in the page header. For a user who may edit the entry each row ends in an inline POST
  form to `admin.entries.restore` ("Restore", `history` icon); a restore saves the snapshot (title, slug, fields,
  SEO, terms — status and publishing kept) as a new version through `EntryService::restore()` (audit
  `entry.restored`, toast `admin.entries.flash.restored`), a stale revision id redirects back to the list. Only the
  last `content.revisions` versions are kept (default 20).
- Autosave (`autosave.js`, Plan 15): the entry form (`[data-entry-form]`) of a new entry or a stored draft carries
  `data-autosave` (never published or scheduled entries, nor plain singles); while "Draft" is chosen the form posts
  itself there 30 s after the last change and on `visibilitychange`/`pagehide` (`fetch` with `keepalive`), JSON only
  (`EntriesController::autosave()`/`autosaveNew()`: 200 with the new version and addresses, 422 `{"invalid"}` skipped
  quietly and tried again 30 s later, 409 `{"error"}` for a stale version or a non-draft; a request gets 15 s). The first
  autosave of a new entry creates it and the form switches to it (`form.action`, `data-autosave`, the Preview's
  `formAction` and `formTarget`, `history.replaceState()`). The status
  `[data-autosave-status]` keeps the server's text until the first autosave of the page view, then its
  `[data-autosave-text]` (now `aria-hidden`) shows "Saving…" / "Saved just now" / "Saved # minutes|hours ago" from
  the server's `PluralForms` (`data-saved-minutes`, `data-saved-hours`, picked with `Intl.PluralRules`), refreshed
  every 30 s, and the hidden `role="status"` span announces each save. Failures clone `<template data-autosave-failed>`
  into the toast stack (409 shows the server's reason, 419 or a redirect to the login page `data-expired`, 403/404
  `data-forbidden`, then autosave stops for the page view; a network or server error keeps trying every 30 s) and a
  success removes it. `editor.js` syncs on the cancelable `entry-form-sync` event (cancelled while an upload
  runs) and reports edits as `input`; `media.js` fires `change` on a widget's hidden input. Unsaved changes that are
  not autosaved (another status, a published entry, autosave stopped, the last autosave failed) ask before leaving
  (`beforeunload`); a page restored from the back/forward cache reloads only when its version is known to be old.
- Menu location switch: `admin-switch admin-list-switch` on the Menus screen (one item per location), 32px items
  (`--control-height-compact`), wraps. Languages are never switched per screen (the top bar does it).
- Media: drop zone form `[data-media-dropzone]` (htmx post with a hidden `folder`: uploads go into the folder shown;
  choosing files uploads at once; `[data-upload-submit]` hidden under `.js`); `<progress data-upload-progress>` only
  moves forward; the results (a success and one error per refused file) arrive as toasts in a
  `<template data-upload-toast>` that `media.js` moves into the page's stack or the picker dialog's own stack; a failed
  request clones the form's `<template data-upload-failed>` and clears the file input (choosing the same files again
  uploads them).
- Media browser (Plan 16; `media/index.php`, `media/library.php`, `media/picker.php`): `admin-media-browser` is two
  columns, the folder tree `media/folders.php` in an `admin-media-sidebar` card (fully open, the levels joined by
  vertical guide lines, the current folder `aria-current="page"`; in the library each folder row has a pencil,
  `admin-media-folder-edit`, shown on hover and keyboard focus, always where there is no hover, that opens the folder's
  form in the dialog) and the `admin-media-content` column; one column below 768px. On the library page
  (`admin-media-page`, grid areas) the heading spans both columns; the sidebar starts level with the drop zone and is
  sticky below the top bar; the content column holds the drop zone, the toolbar and `#media-results`. The toolbar
  `media/toolbar.php` is one GET form `[data-media-filters]`: row 1 the path `media/trail.php` ("All files › A › B",
  `chevron-right` separators), then the view and order dropdowns; row 2 the search box (`admin-media-search`: the
  search icon, the placeholder "Name or description", no label above, and the scope dropdown "Current folder" / "All
  folders" inside it on the right; the scope applies only to a search, `MediaListQuery::searchesEverywhere()`, and the
  count then reads "N files found in all folders"), the type dropdown (All, Images, Videos, Documents: `MediaQuery` groups image/*, video/* and the rest; an image-only or video-only picker, kind `image` or `video`, shows it fixed to Images or Videos,
  `admin-media-dropdown-fixed`, its title saying why) and the "Unused" toggle (`admin-toggle`, a `role="switch"`
  checkbox drawn as a track). The dropdowns (`media/dropdown.php`,
  `admin-media-dropdown`, `[data-media-dropdown]`) are `<details>` with radio options of the form and a check mark on
  the chosen one; the summary shows its icon (view: `layout-grid`/`list`; order: `calendar-arrow-down`,
  `calendar-arrow-up`, `arrow-down-a-z`, `arrow-down-wide-narrow`), its text (scope) or both (type: `files`, `image`, `video`,
  `file-text`). `media.js` copies a chosen option into the summary, closes it, keeps one open at a time (outside click,
  Escape) and submits the form on any change and 300 ms after typing once the search holds 3+ characters or was emptied;
  htmx swaps only the results (`hx-select` of the same id), so the search keeps its focus, and the library pushes the
  URL. Without JavaScript the form's submit button (`admin-media-apply`, hidden under `.js`) applies the choices. The
  server remembers the view in a cookie, so the first paint is right. Uploads include the toolbar's fields
  (`hx-include`) and swap the results. The library's list view is an `admin-table` (a 48px square thumbnail, name as
  the name (a label of the row's checkbox, ellipsised with the full name as its title), type, size, dimensions, date; uses as an `admin-media-uses` pill, an empty cell when unused); the grid
  has 128px tiles. Bulk bar (library): `#media-bulk` (`admin-action-bar admin-media-bulk`, floating at the bottom of
  the results) with "N selected", "Move to" + folder select (`admin-media-bulk-target`), Move (`folder-input`) and
  Delete… with `media.delete` (a confirmation page, `media/delete-many.php`); the items' checkboxes (`media/check.php`,
  `admin-media-select`, in a card's corner or in the first table column) join it with
  `form="media-bulk"`; `media.js` shows the bar (`admin-media-bulk-active`) only while files are checked (without
  JavaScript it is always there and the server answers an empty selection with an error toast). A checked file's card
  has an accent border, its row an accent tint (library and picker alike). In the library a click on a card (a
  `<label>` of its checkbox) or anywhere in a row (`data-media-row`, `media.js`) only checks the file; its page opens
  from the pencil icon link (`media/open.php`, `admin-media-open`: the card's top-right corner, the table's last column). Folders: New folder and the pencil's
  Rename or move open in the page's dialog; the folder form has a red Delete (a link to the confirmation, in the dialog
  too, or a locked button while the folder holds subfolders or files, `media/folder-delete-button.php`; see "Locked
  buttons"). Disabled buttons are dimmed (`.admin-button:disabled`). The dialogs (`dialog.js`, `media/folder-dialog.php`,
  `media/folder-delete-dialog.php`, shared fields `media/folder-fields.php`; a save, a refusal without a field and a
  done or refused delete answer `HX-Redirect` to the library with the flash); without JavaScript the full pages
  (`media/folder-form.php`, `media/folder-delete.php`). The current folder's subfolders open the results of the
  library and the picker (`media/folder-items.php`: tiles in the grid, rows in the list, `admin-media-folder-tile`/
  `admin-media-folder-name`, the picker's through htmx) — navigation only, no checkboxes, shown only on the first
  page of a plain browse (no search, not the unused filter). The media item page plays a video file
  (`admin-media-player`, `controls preload="metadata"`, no autoplay) where an image shows its preview, and shows the file's folder, the absolute address and "Copy link" (`copy`,
  `[data-copy-link]`, rendered `hidden` and shown by `media.js` where `navigator.clipboard` exists; its result clones
  `<template data-copy-toast>` / `data-copy-failed`); "Used in" lists one "Site settings — …" line per role the file
  plays there (logo, site icon, sharing image; `SiteSettings::mediaUses()`), and such a file is never "unused" and
  cannot be deleted. Thumbnails are 4:3
  `object-fit: cover`. Widgets `[data-media-field]` clone the Remove button (32px circle, top-right) from
  `<template data-media-remove-template>`; galleries show no "Main" marker (the first photo is still the main one
  for themes; a user decision); they sort with SortableJS, and their help line "Drag the photos to change their
  order." (`admin-media-order-help`) shows only on an `admin-media-enabled` gallery that holds a photo (CSS `:has()`,
  user decision); the ids input stays for users without a Choose button. Choose is a compact button
  (`admin-button admin-button-secondary admin-button-compact admin-media-choose`, the `folder-open` icon) under the
  chosen items, at the start of the line.
- YouTube widget (`fields/youtube.php`, `youtube.js`, Plan 42 and the video card of 2026-10-14, user decisions): the
  input (an id or a link, the server stores the id) and its help line, then the card `admin-youtube`
  `[data-youtube]` (at most 480px wide), rendered for the video the value names (`YouTubeVideo::parse()`: the stored
  id, or a refused form's posted link) and `hidden` without one, with `data-youtube-input` (the input's id),
  `data-youtube-embed` (`VideoUrl::YOUTUBE_EMBED`), `data-youtube-thumb` (`YouTubeVideo::THUMBNAIL`, `{id}` for the
  id) and `data-youtube-watch` (`YouTubeVideo::WATCH_BASE`) and `data-youtube-id` (the video shown): the 16:9 box `admin-youtube-preview` with the cover
  `admin-youtube-thumb` (`hqdefault.jpg` from `i.ytimg.com`, `alt=""`, `object-fit: cover` crops its letterbox) and
  over it the round accent button `admin-youtube-play` (`play` icon, "Play video" as `aria-label` and `title`); then
  the line `admin-youtube-meta` (no id: the input shows it, user decision): the link "Open on YouTube" `admin-youtube-open` (new tab,
  `external-link` icon) and the compact red link button "Remove video" `admin-youtube-remove` (`admin-button-link admin-button-danger`, `trash-2`, like the form's Delete; user decision) at the end; a
  `<template data-youtube-player>` with the `<iframe class="admin-youtube-frame">` (title "Video preview", the
  public player's `allow` plus `autoplay`, `referrerpolicy="strict-origin-when-cross-origin"`, no `src`) and a
  `<template data-youtube-invalid>` of `partials/toast.php` with `validation.field.youtube`. Play and Remove show
  only under `.js`; without JavaScript the card shows the cover and the link. The form's response alone allows
  `i.ytimg.com` in `img-src` (`EntriesController`, `CspSources`): the covers load when the form opens (user
  decision), the player only on Play. `youtube.js` reads the id 300 ms after typing stops, the way
  `YouTubeType::normalize()` and `VideoUrl::parse()` do (the link split as `parse_url()` splits it and the query read
  as `parse_str()` reads it, not by the URL parser: a new shape accepted by the server must be added there too), and
  updates the cover (fetched again only for another id) and the link, or hides the card; any typing puts the
  cover back in place of a running player. Leaving the input (`change`) with a value that names no video shows the
  error toast (a toast, user decision; the widget's previous one is replaced). Play clones the player in place of the
  cover with `src` = embed base + id + `?autoplay=1` (a property) and focuses it; Remove empties the input and fires
  `input` and `change` (autosave hears it). The entry form loads the script only with a YouTube field.
- Rich text (`fields/rich-text.php`, `editor.js`): the textarea carries `data-editor`, the JSON of `EditorConfig`
  (`editorConfig()` helper, memoised per request: interface language, `upload` and `library` only with
  `media.upload`, the theme's `classStyles`, `templates` and `classes` (`RichTextTheme::themeClasses()`, for GHS), its
  `fontSizes`, `fonts`, `colors` and `markers` (`null` = CKEditor's defaults, `[]` = off), `codeLanguages`,
  `textLanguages` (`View\EditorLanguages::ALL`, always the whole list), the video embed bases, labels incl. `newTab` and
  `imageFull`). The entry form loads `editor.js`, `media.js`, `vendor/ckeditor5-48.5.2.css` and the theme's
  `editor.css` files (`themeStyles`, `editorStylesheets()`), renders the picker dialog for rich text too, and carries
  `data-content-locale`. Toolbar (two rows split by `'-'`, each measured to fit the editor at a 1280 px window; items
  of a feature that is off are left out with their doubled separator, empty groups go): row 1 undo/redo, headings
  (paragraph, H2–H4), Style (theme styles; table styles only inside a table), bold, italic, font size, font colour,
  the "More formatting" group (underline, strikethrough, subscript, superscript, code, font family, background
  colour, highlight, outdent, indent, text language, remove format), alignment; row 2 lists (bulleted, numbered with
  style/start/reversed, to-do), link (with "Open in a new tab"), "Photos and files" (icon button, the picker in insert
  mode), table, media embed (YouTube/Vimeo only, live previews), "Ready-made blocks" (icon dropdown, theme
  templates), the "Insert" group (quote, code block, horizontal line, special characters), the "Tools" group (find
  and replace, select all, show blocks), source, fullscreen. Group labels are `admin.editor.group.*` (`labels` of
  `data-editor`), their icons CKEditor's named `text`, `plus`, `threeVerticalDots`; `admin.css` narrows the heading
  (6em) and Style (5em) labels so the rows fit. A minimal field (`Field::minimal()`, `profile: "minimal"`,
  `editorConfig(true)`, a 4-row fallback textarea) gets undo/redo, bold, italic, font size and font colour only, the
  word count, no uploads, and the class `admin-editor-minimal` (a 5rem editable). Image toolbar: in text, wrap left,
  centred, wrap right, full width, resize (original, custom, 25/50/75 %, drag handles), alt text, caption, link. Table
  toolbar: column, row, merge, table properties, cell properties, caption. The word count sits under the editor, the
  message line under it. Character counter (Plan 42, user decisions): a rich-text field with `->max()`, minimal or
  full, carries `data-max` on its textarea; CKEditor's own "Characters" item is off there and `editor.js` appends
  `admin-editor-count` to the word count line: "412 / 500" (`aria-hidden`, `dir` ltr), a visually hidden "412 of 500
  characters" (`labels.count`/`countOver` of `data-editor`, `admin.editor.count`/`count_over`, `#count` and `#max`
  filled in) and a visually hidden `role="status"` span that speaks only when the count crosses the limit, either
  way. It counts the editor model like the server (`RichText::text()`, `mb_strlen()`): each block and soft break one
  space, whitespace runs one space, trimmed, code points. Past the limit `admin-editor-count-over` (`--danger`,
  bold). The server stays the judge (`validation.field.too_long` at the field); without JavaScript there is no
  counter. Dropped or pasted images upload to `POST /admin/media` (JSON, `X-CSRF-Token`) with CKEditor's
  progress bar; failures and "still uploading" show in an `admin-field-error` line, and a submit during an upload is
  blocked. If CKEditor cannot start, the textarea stays.
- Picker selection (Plan 16, `media.js`, `media/dialog.php`): the dialog layout of "Dialogs" (the body
  scrolls, the header, the toasts and the footer stay in view). The files look as in the library: each is a checkbox
  `[data-media-pick]` in an `admin-media-select` corner of a card (the card is its label) or in the first table column
  (the name is its label; a click elsewhere in the row is forwarded to it); `[data-media-item]` marks a card or row.
  The selection is a `Map` in click order kept across folders, pages, searches and filters (`markTiles()` after
  every `htmx:afterSettle`): a click selects, a second click deselects (one file: a click replaces the selection);
  Space toggles the checkbox; one file: a double click or Enter chooses at once; a gallery: Enter toggles. A gallery's
  files are `disabled` with "Already in the gallery" (`admin-media-already`); a single field starts with its file
  selected and Choose with it unchanged only closes; uploaded tiles (`data-uploaded`) join the selection (one file:
  the first). The footer `[data-picker-footer]` has the `role="status"` line ("Nothing selected" / "Selected:
  photo.jpg" / "Selected: 3"), Clear selection (galleries), Cancel and Choose / "Add (3)" (disabled while nothing is
  selected); its texts come as data attributes with `#` for the name or the count. Choose appends in click order and
  never twice. The picker opens in the last folder (a cookie) and never manages folders. The dialog has a fixed
  height (90vh), so loading its content never resizes it. The count row (`media/count.php`) carries "Select all" /
  "Clear selection" (`admin-media-select-actions`, rendered `hidden` and shown by `media.js`; library and gallery
  picker only): Select all checks the shown page (skipping "Already in the gallery"), each disabled when it would
  change nothing.
- Picker insert mode (`media.js`): `editor.js` dispatches `media-pick` (`{locale}`) on `document`; the dialog opens
  with `kind=image&locale=…` in single mode, tiles carry `data-url` and `data-alt` (the alt text in that locale;
  search, pages and uploads keep `locale`), and Choose (or a double click, Enter) dispatches `media-picked`
  (`{url, alt}`) and closes the dialog. A closed dialog sends nothing.
- The media tab's counter (`[data-tab-counter]`, hidden at 0; `admin.form.files_chosen`) is rendered on the media tab
  of every entry form; `media.js` updates its numbers (`[data-tab-count]`) whenever a widget changes.
- Trees (`tree/editor.php`): one-line card rows (the meta text follows the label on its line, cut with an ellipsis):
  drag handle (JS), the label, then the accordion's toggle (JS), a segmented move control `admin-segmented
  admin-tree-moves` and the row's actions in `admin-tree-controls` (they wrap below the label as one group), nested
  lists indented with a left guide line (none when empty; on a phone a level indents 16px instead of 30px, so the
  controls stay in their columns a few levels deep). Every item that may take children has a nested list; an empty one
  takes no room, also during a drag (rows are spaced alike, pinned or not, and nothing shifts under the pointer when a
  drag starts); SortableJS drops into it when the pointer comes within `EMPTY_INSERT_THRESHOLD` (16px,
  `emptyInsertThreshold`) of it, near the bottom edge of the item's row. The handle and the row actions are compact
  (32px, `--control-height-compact`, icons 1rem; a user decision of Plan 13); the moves are secondary and smaller (24px,
  `--control-height-tiny`, icons .875rem; Plan 13b). The row has the same padding on both sides (8px), so the space
  before the handle equals the space after the last button. `tree.js` disables the moves that would change nothing (up
  for a first item, down for a last, outdent at the top level, indent for a first item or past `data-max-depth`) on
  load and after every drop; the server renders them all enabled. An item with `pinned` (`data-tree-pinned`: the main
  menu's fixed items on the Sections screen) moves only up and down (its outdent and indent are locked buttons without
  a `name`, whose reason is the item's `locked` `outdent`/`indent`, `admin.sections.pinned_level`, so its moves line up
  with the other rows) and has no nested list; `tree.js` keeps it at the top level and disables indenting the item
  below it. During a drag, resting on an item opens it only when the dragged item could go into it (`enterable()`:
  neither pinned, its children's level within `data-max-depth`; checked after the drop rule in `onMove`). When a save
  result replaces a toast that held the focus (its "Reload the page"), the new toast's first button takes it.
- Locked buttons (Plan 17, user decision): an action that exists but is refused here (a pinned item's Add, Edit,
  Delete, outdent and indent; Delete of a section with subsections or entries; the media folder form's Delete of a
  folder that is not empty; the entry form's Delete of a preset page; the Users screen's Edit and Delete of a user
  whose role one cannot grant, and one's own Delete, `admin.users.locked.*`) is a `<button type="button" class="… admin-locked" aria-disabled="true">`, never
  `disabled`: it stays in the Tab order and does nothing, its reason is its `title` and its description
  (`aria-describedby` to a visually hidden span right after it, `tree-{id}-{action}-reason` / `folder-delete-reason` /
  `entry-delete-reason`),
  the shared tooltip (`tooltip.js`, see Shell) shows that title on hover and on keyboard focus (Plan 27 replaced the
  old focus-only `::after` tip). It looks like a disabled button (`--border-strong` in the tree, `--muted` for an `admin-button`). Moves that
  would change nothing are still `disabled` by `tree.js`; the server disables no button (test).
  Accordion (Plan 13b): the row of an item with children is a toggle: a click anywhere on it shows or hides the
  children, except on its handle, name link, moves, actions and anything else focusable (`INTERACTIVE` in `tree.js`),
  and except for a click that ends a text selection or a drop; under `.js` such a row has a pointer cursor and a darker
  border on hover (`--border-strong`). The keyboard and accessible toggle is a text button that ends the label line
  (`admin-tree-toggle`, `[data-tree-toggle]`, `aria-expanded`, `aria-controls` = its nested list's id
  `tree-{id}-items`): "2 subsections" and a chevron (right when collapsed, down when open), muted small bold text
  without a border, 24px high (`--control-height-tiny`), its visible text its only name (no `title` or `aria-label`).
  `margin-left: auto` pushes it to the end of `admin-tree-text`, so on a wide screen it sits right before the moves
  (which keep their columns) and on a phone, where the controls wrap below, opposite the name. The handle has a
  negative inline margin (`--space-2`): its grip icon is narrow, so the visible space around it matches the row's
  padding while its hit area stays 32px. The text is the ICU message `countKey` of
  `tree/editor.php` (`admin.sections.children` "# subsections" on the Sections screen, else `admin.tree.children`
  "# nested items") for the item's direct children (`[data-tree-count]`); a drag changes the numbers, so `tree.js`
  rewrites them (`recount()`, `textContent`, during and after a drag) from the form's `data-tree-counts`: the message's
  plural forms in the interface language by CLDR category, "#" for the number (`AtarCms\I18n\PluralForms`, from the
  smallest whole number of each category), picked with `Intl.PluralRules` of the page's `lang`. The button is rendered
  for every item but a pinned one and drawn only while the item has children (`:has()`, so also mid-drag). Rows are
  8px apart (`.admin-tree` `gap` and a nested list's `margin-top`, pinned or not). Pinned items have no toggle.
  Every item starts collapsed: under `.js` the stylesheet hides a nested list with items unless its item has
  `admin-tree-open`, so the first paint is already collapsed. The items remembered in `localStorage` under
  `admin.tree.open:{form action}:{locale}` (a list of ids; every access in try/catch) are opened before the first
  paint by `tree-open.js`: a classic, parser-blocking script (nonce'd, not a module) right before the editor's list,
  whose `MutationObserver` adds `admin-tree-open` and `ariaExpanded` to each remembered item as the parser inserts it
  (observer callbacks run before rendering) and stops at `DOMContentLoaded`; the handle and the toggle show under
  `.js` (not under a class `tree.js` adds), so the rows are final at the first paint and a cold load has no layout
  shift (Plan 13b final review, measured). `tree.js` toggles `admin-tree-open` and `ariaExpanded` on a click and
  stores the open items again. During a drag a collapsed item opens after the pointer rests on it for `OPEN_DELAY`
  (600 ms; Sortable's `onMove`, the item of `event.related`, which is the list itself over an empty nested list;
  while dragging, a document listener for `dragover`, `pointermove`, `mousemove` and `touchmove` forgets the hovered item and its timer once the point is neither on its row nor on the
  dragged item (which a swap puts next to it), e.g. in empty space), and the item a drop lands in is opened (`onEnd`),
  as is the item above an item indented by its arrow and the item whose "Add inside" was clicked
  (`[data-tree-opens]`), so a moved or new child is never hidden. A list that holds the dragged item (`.sortable-ghost`)
  is never hidden. Collapsed children stay in the DOM: they are serialized and move with their item. Without
  JavaScript every list is open and the toggle is not drawn; if `tree.js` fails with JS on, nested items stay hidden
  (the trade-off of a collapsed first paint).
  Saving: the arrows submit the form (no JS needed, saved at once, flash and redirect; a refused post shows the screen
  again with its errors as error toasts, `FlashMessage::errors()`). There is no "Save order" button and no status bar:
  `tree.js` saves a drop 500 ms later in the background (`fetch` of the form's own action with its fields, the CSRF
  field included, `Accept: application/json`; `TreeResponder` answers `{"version"}` or 422 `{"error"}`), one request
  at a time (a drop during a save is saved after it, an arrow pressed during a save is submitted after it, a pending
  save is dropped when an arrow posts the whole tree), puts the new version into `[data-tree-version]` and shows the
  result as a toast cloned from the form's `<template data-tree-saved>` ("Order saved.") or `<template
  data-tree-failed>` (the reason from the JSON, else its own generic text, with a "Reload the page" button); each
  result replaces the previous toast of the form. Leaving the page before a drop is saved asks first (`beforeunload`).
- Sections screen (`sections/index.php`, `/admin/sections`): its page header with the language's pill, a help line, the tree editor of the
  sections of that language with the pinned items (label in the content language, like the public main menu; no meta), and "Add section" at the
  end of the title row (`partials/page-header.php` actions, a link with `data-dialog-open`, as on the other list
  screens; the empty state has its own). Each row ends with four fixed slots
  in this order, whatever its depth, so the buttons form columns: Order articles (`arrow-down-wide-narrow`,
  `admin.sections.order_articles`; a full page, no `data-dialog-open`; only while some collection lists its entries on
  the section pages, else the slot is left out on every row; locked on a section with subsections,
  `admin.sections.order_parent`, and on the pinned items), Add a subsection (`plus`, the new-section form with
  `?parent=` and the row's section chosen as the parent, offered while a child still fits `TreeOrder::MAX_DEPTH`),
  Edit (`pencil`) and Delete (`trash-2`, red: `--danger`), each an icon link (`admin-row-action`, 32px, `title` and
  `aria-label` with the item's name). A null URL with a reason (`locked`) is a locked button (above): a pinned item's three say it is
  always in the menu; Delete of a section with subsections or with entries that take their address from it is locked
  up front (`TermService::deleteBlockers()`: the subsections from the loaded list, the entries in one query; the
  server still refuses such a delete). A null URL without a reason keeps an empty `admin-row-slot`. A name opens the
  content where the user may see it (else it
  is plain text): a section the list of the first collection routed by the sections (config order) filtered by it
  (`?term=`; when that collection is not `translatable()`, a section of another language is plain text: its entries
  exist in the default language only and take only its sections, Plan 17), a pinned single its entry's form (in the
  screen's language, else the default one's; "Add new" before it exists; plain text without the right to edit or
  create it), a pinned archive its list. The term forms and delete pages of the sections post to `/admin/sections/…`
  (`TermRoutes::of()` in `terms/form.php`, `terms/delete.php` and their dialog versions). `?parent=` is taken only for
  a term of the same taxonomy and language (`TermsController::create()`), else the parent stays empty.
- Order articles (`sections/articles.php`, `/admin/sections/{id}/order`, Plan 49): the page header ("Order of the
  articles in “…”" with the section's language pill), breadcrumbs Sections (of that language) › the section, a help
  line, and the tree editor as a flat list (`tree/editor.php` `flat` with `maxDepth` 1: only the up and down moves, no
  toggle, no nested lists; `tree.js` unchanged) of the section's articles of its language in any status, each with
  its status chip (the item's `status`, `admin-chip-{status}` like the entries list) and its name linking to its form
  where the user may edit it; an empty section shows the empty state. Saved like every tree screen (arrows without
  JavaScript, a drag in the background, refusals as toasts). Switching the content language there opens the Sections
  screen of the other language (`ContentLocaleSwitch`).
- Menus screen (`menus/show.php`, `/admin/menus/{location}`; Plan 32): its page header with the language's pill,
  "Add item" of the shown location and language at the end of the title row (`data-dialog-open`, like the Sections
  screen), the location switch, the tree editor (counts
  "2 subitems", `admin.menus.children`) and the screen's dialog shell. Each row ends with the last three slots of the
  Sections screen: Add a subitem (`admin.menus.add_inside`; the new form with `?parent=`, offered while a child still
  fits `MenuService::MAX_DEPTH`), Edit and Delete; the label is plain text (an item has no page of its own), its
  target as meta when it has its own label. The item form (new and edit) opens with an "Inside" select like the
  sections' form, whose parent select also comes first
  (`admin.menus.field.parent`, first option `no_parent` "— top level —"): the location's items of that language in
  tree order, indented by depth and named like the tree's rows (`MenuItemLabels`), without the edited item, its
  subitems and every item too deep to hold it (its subtree's levels within `MenuService::MAX_DEPTH`). `?parent=`
  preselects an item of the same location and language (`MenuItemsController::create()`). `MenuService::create()` and
  `update()` check the parent again (`validation.menu.parent`, `validation.tree.depth`, errors at the select); a new
  item, and an edited one whose parent changed, goes after the parent's last child (or last at the top level); an
  unchanged parent keeps the place. A refused parent is no option, so the re-rendered select shows the top level and
  the next submit goes through.
- Dialog (`dialog.js`, `AtarCms\Admin\Dialog`; the Sections screen, the Menus screen, the Users screen, the terms lists, the media library's folders and the contact page's form): links with `data-dialog-open` ("Add
  section", "Add item", Add a subsection or subitem, Edit, Delete, and the Translations line's links inside the dialog) load their own URL through `htmx.ajax` into the
  page's `<dialog class="admin-dialog" data-dialog>`, an empty shell (`<div class="admin-dialog-content"
  data-dialog-content>`) that each fragment fills with the whole dialog layout (see "Dialogs"); requests
  with `HX-Request` get the fragment (`terms/dialog.php`: the header with `#dialog-title` (and the language's pill), the Translations line (not for a tag, nor for a
  section) and the close button, then the `hx-post` form `.admin-dialog-form` whose body holds the summary
  and the fields of `terms/{alert,fields}.php`, in the tabs of `terms/{tabs,seo}.php` for the sections, shared
  with the full form, and whose footer holds Cancel `[data-dialog-close]` and Save; `menus/dialog.php` (the fields
  of `menus/fields.php`, shared with `menus/form.php`; the menu screen of the item's language is where a done or
  fieldless refused form goes), `terms/delete-dialog.php`, `menus/delete-dialog.php`,
  `media/folder-dialog.php`, `media/folder-delete-dialog.php`, `map/dialog.php`, `map/delete-dialog.php`, `users/dialog.php` (the fields of `users/fields.php`, shared with `users/form.php`), `users/delete-dialog.php` alike, the confirmations with Cancel focused). A refused form
  comes back into the dialog (status 200: htmx swaps no error response) with the focus on its first invalid input; a
  saved form, a done delete and a refused delete (subsections or entries that appeared after the screen was loaded,
  since Delete is disabled for the known ones) answer `HX-Redirect` back to their screen (the Sections screen of its
  language, the menu's screen, the users list, a term list through `Dialog::back()`, the media library), with the
  flash (a refusal is an error toast, and the reloaded screen disables that Delete), as does a refused form whose
  errors have no field (a stale version, a gone section). Without JavaScript a refused delete (entries, terms, files)
  flashes its reason and redirects to where the confirmation's Cancel leads (the item's form; an entry's list when it
  may not be edited; the dashboard for a file when the user lacks `media.upload`, which its page and the library
  need). Escape, the
  close button and Cancel close it; closing empties it and focuses the link again. A modified click opens the full
  page; a fragment that fails to load opens the full page; a dialog form that fails (an expired session or token)
  reloads the screen. One request runs at a time: from `htmx:beforeRequest` for the body to the request's `loadend`
  (a form emptied out of a closed dialog sends no htmx events up), `data-dialog-open` clicks are swallowed. Closing
  while it runs drops its answer: `htmx:beforeSwap` sets `shouldSwap = false` (so the dialog does not reopen) and an
  error is not followed by the fallback; an `HX-Redirect` (a finished save) still reloads the screen.
  A done form that must not reload the screen (the map column, Plan 26) answers markup for the screen
  (`HX-Retarget`/`HX-Reswap`) and `HX-Trigger-After-Settle: {"atar-dialog-done": {"toast": "…", "focus": "{id}"}}`
  (ASCII: `json_encode()` escapes the toast's letters): `dialog.js` clones the success toast of the dialog's
  `<template data-dialog-toast>` into the page's stack with that text, closes the dialog and focuses the first
  `data-dialog-open` link of the element with that id (the replaced opener's successor).
  Without JavaScript the links are the full pages (their own templates, ordinary page blocks, no dialog layout).
- Dialogs (a user rule after Plan 17; every admin modal: `dialog.js`'s, the media picker and the slides dialog; test:
  `DialogLayoutTest`): a header `.admin-dialog-header` with the title (`h2`, named by the dialog's
  `aria-labelledby`) and the close button (`partials/dialog-close.php`, a `method="dialog"` form, pinned to the
  header's top right corner), the body `.admin-dialog-body` and a footer `.admin-dialog-footer` with the action
  buttons; the header and the footer are set off by border lines and always stay in view, only the body scrolls
  (`max-height: 90vh`, the open `<dialog>` and `.admin-dialog-content`/`.admin-dialog-form` are flex columns). A form
  wraps the body and the footer (`.admin-dialog-form`), so the footer's buttons submit it without JavaScript of
  their own. The picker (`media/dialog.php`, `admin-dialog admin-media-dialog`) uses the same classes, with its own
  size (60rem × 90vh, fixed), a body that is a size container (`admin-media-dialog-body`) and its own toast stack
  between the body and the footer. Never a sticky element inside the scrolling body.
- Site settings (Plan 18; `settings/form.php`, `settings/field.php`, `/admin/settings`): the fields are listed once in
  `Admin\Settings\SettingsForm` (tab, per language or site-wide, kind, labels, help). The content language is the
  top bar's (no pill by the title: a mixed form). The form takes the
  entry form's container (`admin-entry-form`) and `admin-form-layout`: on the left one tab column
  `[data-tabs="admin.tabs.settings"]` (remembered) with General | SEO | Integrations (the contact details are on the
  contact page's form; the screen's Save keeps the stored ones). Integrations is ordered by service (Plans 40 and 41, user
  decisions; no headings; pinned by `AnalyticsSettingsTest`): Google's Search Console code, the GA4 measurement ID and
  the Google Ads conversion ID, then Yandex's Webmaster code, the Metrica counter number and the Webvisor switch, then
  the Meta Pixel ID, the VK Ads pixel ID and the TikTok Pixel ID, then the cookie banner's text (kept per language); the
  counters' help lines say they load only after a visitor accepts cookies and that signed-in users are not counted, the
  pixels' that they load only after a visitor allows advertising cookies and that signed-in users are not tracked
  (`admin.form.tab.*`, the entry form's tab markup and error badges; the server opens the first tab with errors, else
  General; no line explains the badge, a user decision); on the right one `admin-card admin-form`
  "Images" with the logo, the site icon and the sharing image (`media/field.php`, `kind` `image`); below 880px of its
  width the card follows the tabs. A field kept per language carries `admin-locale-badge` (the code, `aria-hidden`,
  its `title` and a visually hidden twin say "kept separately for each language"). Help lines are short (user decisions):
  the names show their limit ("Up to N characters", `admin.settings.help.max_chars`), the home title, the description
  and the logo have none; the theme colour's says it also picks the default site icon's and the admin's colour (Plan
  34). The theme colour ("Theme color", `palette` icon) is a colour input plus a checkbox `theme_color_on` ("Use
  theme"; unchecked = no colour; a user decision) and, JavaScript only (`.admin-color-presets`, shown by `.js
  .admin-color-presets`), a "Preset" dropdown (`media/dropdown.php` with a `placeholder`: the button names the preset
  matching the current colour, else "Preset") of the eight admin presets, each a swatch and its name, valued with the
  preset's `logoColor()` (which `AdminAccent::nearest()` maps back to it); choosing one makes `media.js` set the colour
  input's value, dispatch `input`/`change` and check `theme_color_on` — the radios `theme_color_preset` are never
  stored; all in an `admin-color-field` row. The admin colour is no setting since Plan 34 (each user's own, on the
  Profile). "Hide from search engines" is an `admin-toggle` switch. After the theme colour, "Accessibility toolbar" (Plan 28,
  kind `toolbar`, one `role="group"` named by its heading with the `accessibility` icon): one
  `admin-toolbar-row` with the `admin-toggle` switch (named by the heading, on by default: only "off" is stored; its
  visible word `admin-toggle-on`/`admin-toggle-off`, "On" or "Off" by CSS from `:checked`, `aria-hidden`) and
  "Position:" with the media dropdown of names (kind `position`, drawn inside the group: Top left, Bottom
  left, Top right, Bottom right, stored as `top-start`…`bottom-end`, mirrored in right-to-left languages; not
  disabled while the switch is off), then one help line and the errors of both. Field errors stay at their
  fields (`aria-invalid`/`aria-describedby` `{name}-error`, the media widgets through `invalid`), the summary counts
  them, image errors count on no tab; `locale` or any other key is a toast (`FormErrors::split()`). One Save stores
  the site-wide values and the shown language's.
- Dashboard notice (Plan 18, user decision): while the site is hidden from search engines, users with
  `settings.manage` see an `admin-alert admin-alert-warning` (`role="status"`, eye icon) above the greeting, linking to
  the settings. The admin brand shows the default language's short name, else its site name, beside its mark (Shell).
- Menu item form (`menus/form.php`, `menus/dialog.php`, fields in `menus/fields.php`): the target radios (entry,
  term, URL) each show their `[data-target]` block; under `.js` an `admin.css` `:has()` rule per target hides the
  others (no script, so it works in the dialog too), without JS all show.
- Single-card forms (terms other than the sections, menu items, users): `admin-form-card`. Delete pages: `admin-card admin-card-danger
  admin-confirm`; they name the item's language in itself (the `languageName()` helper, as the content language menu does).
  Guests (login, errors): `admin-guest` layout with the `admin-login` / `admin-error` card, no sidebar; the login card
  starts with the brand mark and the site name (`admin-login-site`) and names `bin/atar user:login-link` only outside production (`Config::environment()`).

## User preferences

- Two modes, a build choice of the site (`locales.admin` in `config/locales.php`, `Admin\LanguageMode`, read at boot;
  anything but `'linked'` or `'separate'` stops it; the skeleton sets `'linked'`, the core default; the test fixture
  pins `'separate'`, its linked suites are `tests/Functional/LinkedLanguage/`). **Separate** is the two preferences
  below, as before Plan 37. **Linked** (`Admin\LinkedLanguage::enabled()`: the mode on a site with more than one
  language; a one-language site is always separate) has one language, the content language of the screen, switched
  only by the top bar: `AdminLocale` decides it before the controller from the matched route — the item's language
  (`Admin\ItemLocale`: an entry of a translatable collection the user may view, a term or section with
  `taxonomies.manage`, a menu item with `menus.manage`; an untranslated collection's entries lend none) → a valid
  `?locale=` (any admin screen) → the remembered `users.content_locale` → the browser among the site locales → the
  site default (a non-GET without either takes a valid posted `locale`, storing nothing). An item's or `?locale=`'s
  language is remembered on GET/HEAD only, once the screen answered below 400 (the request's `User` is replaced with
  `User::withContentLocale()` first), and the screen's language is put on the request (`Admin\ScreenLocale`, read
  first by `ContentLocale::current()`). The interface speaks it when `InterfaceLanguages` has its catalog, else English
  (`LinkedLanguage::interfaceOf()`; `<html lang dir>` names the catalog used, content inputs keep the content
  language's `lang`/`dir`). `users.locale` is not read (the column stays for the separate mode). Admin error pages:
  the remembered language (signed in) or `LinkedLanguage::guest()`; guests (the login page carries `AdminLocale` too,
  and guest error pages) get the browser's language among the site locales with a catalog → the site default when the
  admin speaks it → English (the separate mode keeps the login page in the site default). No Translations line, no
  Languages column on the term lists (the entry lists keep theirs, plain flags), no locale pills, no Profile language (see Shell). A translatable single without the requested language's version: the top bar's switch
  creates the translation (`ContentLocaleSwitch`, `EntryService::createTranslation()`, for users who may translate)
  and opens its form; a GET to its form with `?translate={locale}` (the sidebar link, the Sections screen) redirects to
  "Add new" in that language with `?translate={id}`, whose form shows the "Copy for translation" toast (only for users
  who may create; others keep the form of the item's language). Either way the new entry joins the single's
  translation group: `EntryService::create()` gives a translatable single's new entry the group of its existing ones.
- Interface language `users.locale` (`NULL` = none chosen), one of the admin catalogs (`I18n\InterfaceLanguages::ALL`,
  pinned against `resources/lang/*.php`; independent of the site locales): `Admin\InterfaceLanguage` picks the saved
  one, else the browser's `Accept-Language` (`I18n\AcceptLanguage`), else the site default when the admin speaks it,
  else English. `AdminLocale` (route middleware of the `/admin` group, after `RequireAuthentication`) swaps
  `RequestLocale` with it (it need not be a site locale); public routes keep their URL locale; admin error pages rendered
  by `ErrorMiddleware` get the same language through `AdminErrorLocale` (the `/admin` branch of the error renderer looks
  the signed-in user up from the session; else the browser's choice; else the site default). Set on the Profile
  together with the admin colour by `POST /admin/profile` (`admin.profile.save`, `PreferencesController::save()`,
  `UserManager::savePreferences()`: both checked before either is stored, unknown language
  `validation.locale.interface`, unknown colour `validation.profile.accent`, 422 on the Profile; no audit row), which
  returns to the Profile. Role labels are keys `admin.roles.*` (`Roles::DEFAULTS`).
- Admin colour `users.accent` (`NULL` = the site colour; an unknown stored value reads as `NULL`), an `AdminAccent`
  value: `<html data-accent>`, the brand mark and the admin tab's favicon of every admin page of that user, also their
  admin error pages (`AdminErrorLocale`).
- Content language `users.content_locale`: switched by the top bar (`POST /admin/preferences/content-locale`,
  `admin.preferences.content_locale`, unknown = 422) and kept on the screen by `Admin\ContentLocaleSwitch` (the
  `return` path of `/admin` only, matched as a GET admin route: an entry, term or section form, versions or delete
  page goes to the translation the user may edit, else to the list with `?translate={id}` (a single stays on its form
  with `?translate={locale}`); a menu item page goes to its menu; any other screen reloads without `locale`, `page`,
  `term`, `parent`, `translate`). `?translate=` makes the list (or the single's form) show an `info` toast with a
  "Copy for translation" POST form (`TranslationOffers`, the rights of the translate action; `FlashMessage::$form`,
  `ToastForm`, the `copy` icon; it stays until closed) with the hidden machine translation button (see Machine
  translation). `ContentLocale::resolve()` through `EntryRequest`/`TermRequest`/
  `MenuRequest::contentLocale()`: a valid `?locale=` (remembered) → the remembered value → the interface language when
  the site has it → the site default (unsupported stored values are ignored). Entry and term edit pages call
  `rememberLocale()` (`ContentLocale::rememberRequested()`), which stores `?locale=` only when it equals the item's
  locale. Breadcrumbs and redirects that carry an item's locale switch it on purpose. A translatable single's sidebar
  link opens the entry of the content language. Neither preference is audited.

## Catalogs

- `resources/lang/{en,ru,he,de,fr}.php` (the interface languages, `InterfaceLanguages::ALL`): plain language for
  non-technical editors, polite "вы" in Russian, formal "Sie" in German and "vous" in French with buttons in the
  infinitive ("Speichern", "Enregistrer"; Plan 39, whose plan holds the German and French glossary), the masculine
  imperative in Hebrew ("שמור", "מחק", "בחר", "הזן"; user decision of Plan 22: entry = רשומה, section = מדור, the
  glossary is in the Plan 22 plan); keys are never renamed. Hebrew plurals use `one`, `two` (only for a real dual such
  as "שעתיים") and `other` (`testHebrewPluralsUseHebrewCategories`). A few Hebrew strings hold invisible LEFT-TO-RIGHT
  MARKs before a number or code that starts with "+", "@", "#" or "-" ("+972 …", "@club"), so it keeps its sign in
  place. German quotes are „…“; French uses « … » and a no-break space (U+00A0) inside them and before `:` `;` `!`
  `?`. German and French plurals use `one` and `other` (`testGermanAndFrenchPluralsUseTheirCategories`; French
  `many`, only exact millions, falls back to `other`). The skeleton theme's catalogs
  (`skeleton/themes/default/lang/{en,ru,he,de,fr}.php`, `SkeletonCatalogTest`) hold the collection, section, field and
  editor labels the admin shows; the skeleton site's demo content stays en/ru/he.
- `CoreCatalogTest`: identical keys, valid ICU, every used key exists, every key is used (quoted in `src` or the admin
  templates, or under a run-time prefix of `CoreCatalogTest::DYNAMIC`), a jargon regex per language
  (`testTheAdminSpeaksPlainLanguage`: no "slug", "taxonomy", "canonical", "audit", "meta title", "term", "collection",
  "revision"…; in German only the plural "Tags" and "Tag-" compounds, as "Tag" is also "day"), required shell keys, role labels. Languages are named in themselves by `I18n\LanguageNames::endonym()` (no catalog keys).
- Where labels live: `admin.menu.group.*`, `admin.form.tab.*`, `admin.theme.{title,light,dark,system}`,
  `admin.roles.*`, `admin.status.*` (status explanations), `admin.layout.*` (shell).

## Right to left

A Hebrew interface (any language `I18n\TextDirection::of()` calls `rtl`; nothing tests for "he") lays the whole admin
out right to left with the same stylesheet and scripts.

- Direction sources: `<html lang dir>` from the interface language (`layouts/admin.php`, `langAttrs($this->locale())`;
  the shell, the login page and the admin error pages; dialogs and the media picker load into the page); content
  inputs carry `lang`/`dir` of the content language (`langAttrs($locale)`, Plan 21); names and titles of content
  take their direction from their own text (`unicode-bidi: plaintext` on page titles, breadcrumbs, list titles, tree
  labels, file and folder names: one rule in `admin.css`); addresses, emails, phones, usernames, codes, the YouTube
  field and numeric dates are `dir="ltr"` (`SettingsForm` fields with `ltr`; the last sign-in is `dir="auto"`). In
  the linked mode the interface is the content language, so switching to Hebrew in the top bar turns the whole
  screen right to left; a content language without a catalog keeps an English, left-to-right shell.
- Inserted text: `Translator` wraps every plain `{name}` string argument of a right-to-left message in FIRST STRONG
  ISOLATE … POP DIRECTIONAL ISOLATE (U+2068/U+2069), so an English title or a Russian language name keeps its order in
  a Hebrew sentence; numbers and plural/number arguments are left alone, left-to-right languages are unchanged. The Translator is shared, so the same isolates appear on public theme pages whose content locale is right to left (theme catalog messages with `{name}` arguments).
- CSS: logical properties only (`margin-inline-start`, `padding-inline`, `inset-inline-end`, `border-inline-start`,
  `border-start-end-radius`, `text-align: start`, `float: inline-start`…); shorthands give both inline sides the same
  value. `AdminStylesTest::testTheStylesheetHasNoPhysicalHorizontalProperties` refuses physical sides and asymmetric
  shorthands; a sideways move (`translateX`, `translate`) is allowed only in its `MOVED` list, each rule with a
  `:dir(rtl)` twin (the rail toggle, the phone drawer, the switch knob), and a mirror (`scale: -1`, `scaleX(-1)`, `matrix()`) only in its `FLIPPED` list (the mirrored icons); `!important` is ignored, and `justify-*: left|right` and 4-value `border-width`/`-color`/`-style` count as physical too. The breadcrumb's `›` mirrors by itself.
- Icons: `AdminIcon::MIRRORED` (see Icons).
- Scripts read the direction, never set it: `tabs.js` swaps the left and right arrows when `getComputedStyle(tab).direction` is `rtl` (not `:dir()`, which older browsers lack and would throw on; WAI-ARIA
  tabs); the tree drag, the drawer, dialogs and the media picker need nothing (no horizontal geometry in JS).
- CKEditor: the interface language's translation (`editor.js` `TRANSLATIONS`: `ckeditor5-48.5.2-{ru,he,de,fr}.js`,
  vendored byte-exact from the npm tarball's `dist/translations/`; every interface language but English has one,
  `EditorAssetsTest`) also turns the editor's
  own interface right to left; the content's direction comes from the content language.
- Fonts: the system stack covers Hebrew (Segoe UI, SF / Arial Hebrew, Noto Sans Hebrew); no font is vendored.
- Adding an interface language: `resources/lang/{code}.php` with every key, `InterfaceLanguages::ALL`, the
  `CoreCatalogTest`/`SkeletonCatalogTest` lists (with a jargon regex and the plural categories of the language) and
  the skeleton catalog, the lists of `SkeletonSectionsTest`, `SkeletonEditorThemeTest` and `ProfilePageTest`, its
  CKEditor translation in `TRANSLATIONS` (vendored from `https://registry.npmjs.org/ckeditor5/-/ckeditor5-48.5.2.tgz`,
  checked against npm's `dist.shasum`, pinned in `EditorAssetsTest`, `.gitattributes -text`), a stemmer in
  `TextNormalizer::STEMMED` when Snowball has one, and the theme's flag `assets/flags/{code}.png` (optional: without
  it the code or the `languages` icon shows). Arabic is deferred until a native speaker reviews it.

## Rules the tests enforce

- CSP: no `style=`, no `<style>`, no `hx-on`, no inline `<script>` except the theme bootstrap. Test scope:
  `AdminShellTest` checks the inline scripts, `style=` and `<style>` on the pages it renders; `MediaAssetsTest` checks
  the media templates (inline scripts, styles, `hx-on`); everywhere else it holds by convention.
- Scripts never inject markup (`innerHTML`/`outerHTML`/`insertAdjacentHTML`) and never use `.style` or network URLs;
  build DOM with `createElement`/`textContent`, clone server `<template>`s, set ARIA through properties
  (`ariaExpanded`) (`ShellAssetsTest`, `TabsAssetsTest`, `MediaAssetsTest`, `TreeAssetsTest`, `EditorAssetsTest`,
  `DialogAssetsTest`, `AutosaveAssetsTest`, `ListFiltersAssetsTest`, `SlidesAssetsTest`, `TagsAssetsTest`, `TranslateAssetsTest`, `ConsentAssetsTest`, `YouTubeAssetsTest`); `tree.js` sends its one `fetch` only to its form's own action,
  `autosave.js` its one only to the form's `data-autosave`, `dialog.js` none (htmx loads), `slides.js`, `tags.js`, `translate.js` and `youtube.js` none at all
  (also no `setAttribute`; `SlidesAssetsTest` checks its templates for inline code and the dialog for the shared layout,
  `TagsAssetsTest` the tag box's templates).
  `setAttribute` is forbidden in `shell.js`, `tabs.js`, `media.js`, `tree.js`, `tree-open.js`, `editor.js`, `dialog.js`,
  `autosave.js`, `list-filters.js` and `tooltip.js` (their asset tests; `TooltipAssetsTest` also pins the skeleton theme's copy).
  `editor.js` holds exactly one markup string, the video preview `<iframe class="admin-editor-video">` without `style`
  (CKEditor renders it), and no `http://`/`https://` (the embed bases and the link protocol come from `data-editor`);
  its toolbar, plugin list and feature configs are checked by `EditorAssetsTest::testTheEditorHasTheFullToolbar`.
- CSP: `style-src 'self'` without a nonce, plus the sha256 hashes of the two `<style>` elements of CKEditor's colour
  picker (`SecurityHeadersMiddleware::STYLE_HASHES`; `EditorAssetsTest` recomputes them from the vendored build, so an
  upgrade fails until they are updated); `style-src-attr 'unsafe-inline'` lets style attributes apply: the sanitized
  ones of rich text (editing view and public pages) and CKEditor's own (resize handles, the upload progress bar,
  `aspect-ratio` of sized images). It is no licence for the admin: templates and admin scripts still never write
  `style` (the rules above). `frame-src` always allows `https://www.youtube-nocookie.com` and `https://player.vimeo.com`
  (`VideoUrl::FRAME_SOURCES`), so editing an entry with a video contacts YouTube or Vimeo.
- No JS = the server output: everything is reachable without scripts; rules that hide or move things for JS start with
  `.js` (`AdminStylesTest::testRulesThatNeedJavaScriptAreGatedByTheJsClass`, a curated list).
- Classes use the `admin-` prefix; ids and `name`s never start with `admin-` (the style test reads every `admin-*`
  token as a class). `AdminStylesTest` checks both directions: every `admin-*` token in templates, scripts and
  `AdminIcon` has a rule, and every rule has a user. Prefixes completed at run time (`admin-chip-`, `admin-role-`, `admin-toast-`,
  `admin-depth-`, `admin-accent-swatch-`, the dashboard's `admin-tone-`, `admin-chart-bar-`, `admin-dash-key-`,
  `admin-dash-seg-`) are listed with their suffixes in `AdminStylesTest::DYNAMIC`.
- Targets are 40px (`--control-height`; `testCompactControlsKeepTheTouchTargetHeight`); exceptions: the menu location
  switch (32px), the translations switch's segments (24px), the × of a tag pill (24px inside its 32px pill,
  user decision of Plan 35; `testTheTagPillRemoveButtonIsTheTinyException`), the media Remove corner button (32px), the media widget's Choose
  (`admin-button-compact`: 32px, `--control-height-compact`, .8125rem text, a 1rem icon; under the chosen items,
  never beside the label; `testTheMediaChooseButtonIsCompact`), the translation offer toast's Translate and Copy
  (`admin-button-compact`, one wrapping row; `testTheTranslationOfferIsOneRowWithOnePrimaryAction`) and the tree editor's row controls (32px: handle, Add a
  subsection, Edit and Delete, accepted by the user in Plan 13; 24px: the moves, secondary
  controls the user asked to be smaller in Plan 13b, and the accordion's text button before them, whose row is a
  toggle too). The sidebar toggle is a 40px button around a 32px circle.

## How-to

**Add a screen.** Controller renders through `AdminView::render()` with the request. The template calls
`$this->layout('layouts/admin', ['title' => …, 'breadcrumbs' => [[$parent, $url], [$current]]])`, then
`partials/page-header.php` (`title`, `actions`: `label`, `url`, `icon`, `primary`, `dialog` for `data-dialog-open`). Use existing classes (`admin-card`,
`admin-table`, `admin-form-card`, `admin-empty`, `admin-alert`, `admin-action-bar`); a new class needs a rule in
`admin.css`, colours from tokens. Page scripts go in the layout's `scripts` (module files in `assets/`, each with an
asset test). Add a functional test for the markup and keep the page working without JS.

**Add a field widget.** The template file is named in kebab-case after `FieldType::name()` (`richText` →
`fields/rich-text.php`, converted by the `widget` helper in `AdminModule`); it gets `$field`, `$name` =
`fields[{name}]`, `$id` = `field-{name}`, `$value`, `$options`, `$kept`, `$invalid` and `$aria` (with an error, the
`aria-invalid`/`aria-describedby` attributes for the primary control, else `''`; print it raw). Core type: add the
template and its icon in `FieldIcons::ICONS`, keyed by the camelCase `FieldType::name()`. Module type: return a
directory from `Module::adminTemplatePaths()` with `fields/{kebab-type}.php` (icon `circle`); the core templates take
precedence, so a module cannot override a core `fields/*.php`. The config picks the tab with `->tab()`; label, error
line and invalid class come from `fields/field.php`, the control's marks from `$aria`.

**Add a menu item or module icon.** In `Module::adminMenu(AdminMenu $menu)`: `$menu->add('mymodule.menu', 'route.name',
'permission', AdminMenu::MODULES, [], icon: 'calendar')`; the item appears under "Modules". Collections and
taxonomies use `->icon('…')`. The symbol must be in `icons.svg` (add it there first).
