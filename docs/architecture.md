# Architecture reference

How each subsystem works: the classes, flows, routes and rules that are not obvious from one file. Read the
section before changing its area. The hard rules themselves live in the development repository's `CLAUDE.md`;
public themes are `docs/theme-guide.md`, the admin UI is `docs/admin-ui.md`.

## Boot and kernel

Every environment, `bin/atar` included, boots from the sources: `Application::fromSite()`, or
`Application::boot(new Paths($site, storage: $tmp))` for a test site with its own storage (optional config
overrides as a second argument). Production relies on plain OPcache — no compiled cache, no preloading, no build
step. The environment is read only through `Config::environment()` (`app.env`, set from `APP_ENV` by
`config/app.php`; trimmed and lower-cased, missing or empty means production). Boot also checks `app.url`
(`Config::appUrl()`: the absolute http(s) URL of the site root, the base of every absolute URL) and
`app.timezone` (`SiteTime`) and `locales.admin` (`Admin\LanguageMode`), for the web and `bin/atar` alike. Route attributes must be plain data (content
routes carry collection/taxonomy names). Console commands declare `#[AsCommand(name, description)]` and are
constructed only when they run (`bin/atar list` needs no database). `list` and `help` are built into `Cli\Console`
and reserved (`CommandList::BUILT_IN`); `help <command>` and `<command> --help|-h` (before `--`, also `doctor`)
print the description and its usage, the tail after `": <name>"` (`CommandList::splitUsage()`), without
constructing the command; `--help`/`-h` alone list the commands. Per-request memoisation for view helpers:
`RequestMemo::through($request, $key, fn)`; services stay stateless.

## Database and migrations

`Database\Connection` wraps one PDO (utf8mb4, exceptions, real prepares; `time_zone '+00:00'` and a strict
`sql_mode` set at connect): `select`/`selectOne`/`scalar`/`execute`/`insert` with positional or named bindings
(`DateTimeInterface` becomes UTC `Y-m-d H:i:s`), the static `quoteIdentifier()` (backticks,
`[A-Za-z_][A-Za-z0-9_]*` only) and `transaction(fn)` — never nested, a nested call throws. Rows come back as
`Database\Row` (typed accessors, DATETIME read as UTC); `TimeSlot` buckets times into 15-minute UTC slots in SQL
(the dashboard's activity chart).

Migrations are files `YYYY_MM_DD_HHMMSS_description.php` returning `new class implements Migration` with `up()`
only: every module's `migrationPaths()` (the core: `resources/migrations`) plus the site's
`database/migrations`, applied in file-name order across all directories and recorded in the `migrations` table.
`bin/atar migrate` then syncs the preset pages; `migrate:status` lists `[x]`/`[ ]`; the doctor checks pending
ones read-only. An `->indexed()` field is a VIRTUAL generated column `f_{name}` on `entries`
(`JSON_VALUE(data, '$.name')`, indexed with collection and locale; text/select VARCHAR(191), number DOUBLE,
date DATE, datetime DATETIME; other types cannot be indexed, and a same-named field in any other collection must
keep the same column type — the column is computed for every row). `bin/atar collections:sync` diffs the config
against `information_schema` and writes the ALTER statements as one site migration (drops before adds; its id is
max(now, newest id + 1 s), so it sorts after everything discovered; it refuses to run while migrations are
pending) for `migrate` to apply; the values are computed by MariaDB (10.11+, the doctor checks), so no data
reindex exists or is needed. `ContentQuery::where()`/`orderBy()` accept only these columns and
`Collection::SORTABLE`.

## HTTP, sessions and security

Global middleware order: SecurityHeaders → Locale → Error → SearchVisibility → RouteMatcher → Session → Csrf →
Authentication → router. Admin routes use `RequireDefaultLocale`, `RequireAuthentication` and
`RequirePermission::on()`. `Route::stateless()` (GET only, never with `RequireAuthentication`/`RequirePermission`)
skips the session: media files, robots, sitemaps, feeds. `Route::publicForm($form)` (POST only) is a stateless
public form: `CsrfMiddleware` refuses it when `Sec-Fetch-Site` is cross-site or `Origin` is not `app.url`'s, and
its controller checks the signed `Http\Security\PublicFormToken` (`{time}.{HMAC}` with `Kernel\AppKey`, the
secret in `storage/app.key`; under 3 s = spam, over 24 h = send again); a stateless POST without it is refused.

Sessions use PHP's native save handler (`NativeSessionStorage`, `session.save_path`; PHP's own cookie handling
off): cookie `__Host-atar` (`atar` only on plain-HTTP local), `Path=/; HttpOnly; SameSite=Lax` plus `Secure`, a
64-hex id regenerated at sign-in. `config/session.php` sets `idle_timeout` (default 7200 s) and
`absolute_timeout` (43200 s, also PHP's GC lifetime; idle may not exceed it); an expired record is destroyed on
read, an unchanged session re-saved at most every 300 s, and session responses are `no-store, private`.

`SessionMiddleware` renders errors inside the session stack through `ErrorMiddleware::respond()` (so a logout or
id change before an error is saved, and error pages of a session get `no-store`). Anonymous login failures
(forged Google callbacks, invalid login links) are logged, not audited; audit rows are for identified actors.

The CSP is a host allowlist with the nonce (no `'strict-dynamic'`). A response widens it only through
`Http\Security\CspSources`, a mutable request attribute that `SecurityHeadersMiddleware` puts on every request and
merges into that response's `script-src`/`img-src`/`connect-src`/`frame-src`/`frame-ancestors` (host sources only,
each once); the only writer is `consentHead()` (see "Analytics counters, advertising pixels and the cookie banner"), so every other
response, the admin's included, keeps the same policy. `ErrorMiddleware::respond()` resets them before it renders an
error page (a page that failed after `consentHead()`), whose own render may add them again.

## Users, roles and sign-in

Roles come from `config/roles.php` (label and permission patterns, validated by `Auth\Roles`; without the
config, `Roles::DEFAULTS`): the skeleton's admin is `*`; editor `entries.*.*`, `media.*`, `taxonomies.manage`,
`menus.manage`, `messages.manage`; author entry view/create/edit and `media.upload`. Permissions are
dot-patterns (`PermissionPattern`: a bare `*` matches everything, otherwise each `*` exactly one segment):
`entries.{collection}.{view|create|edit|edit_others|publish|delete}` (`Collection::permission()`),
`media.{upload|delete}`, `taxonomies.manage`, `menus.manage`, `messages.manage`, `settings.manage`,
`users.manage`, `audit.view`. `Roles::grants($actorRole, $role)` — the actor's patterns cover every pattern of
the target role — gates who may hand out which role. Per-entry rules are `Content\EntryAccess`: someone else's
entry needs `edit_others`, saving or deleting a published or scheduled one needs `publish`, and `canDelete()` is
never true for a `single()` or a preset page. Checks run through `Auth\Authorizer` (`can()` = signed in, active,
role allows) and the route guard `RequirePermission::on()` after `RequireAuthentication` (a signed-out GET is
redirected to `admin.login?redirect=…`, another method refused, an htmx request answered with `HX-Redirect`).

There are no passwords. Sign-in (`Auth\LoginService::logIn()`: refuses a disabled user, audits `auth.login` with
the method before the session exists, regenerates the session id, rotates the CSRF token) comes three ways:
login links (`LoginLinks`, `bin/atar user:login-link <email>`: a 64-hex token of which only the SHA-256 is
stored, 10 minutes, consumed on first use — `SELECT … FOR UPDATE`, deleted valid or not); Google
(`GET /admin/login/google[/callback]`, 404 unless `ATAR_GOOGLE_CLIENT_ID`/`_SECRET` are set: state and PKCE kept
in the session, the ID token validated — issuer, audience, 60 s leeway, `email_verified` required — and
`ATAR_GOOGLE_ALLOWED_DOMAINS` checked against the `hd` claim; `GoogleLogin` never creates users: it finds them
by `google_sub`, else by e-mail while their `google_sub` is still NULL and binds it with a compare-and-set); and
the one-click dev login (`POST /admin/login/dev`, `APP_ENV=local` only, 404 elsewhere). Failures map to
`auth.failure.*` keys on the login page; failures of an identified user are audited `auth.login_failed`,
anonymous ones (invalid links, forged OAuth state) only logged.

A Google sign-in stores the ID token's `picture` as the admin avatar (`Auth\Avatar\GoogleAvatar`:
`AvatarDownloader` fetches only https `googleusercontent.com` addresses, no redirects, 2 MB on the wire, a 5 s
wall-clock deadline with 3 s read timeouts; `Avatars` (its own 4 MP limit) re-encodes a 96 px WebP square to
`storage/avatars/{id}.webp` and sets `users.avatar_version`; failures are logged, never fail the sign-in; login
links and the dev login leave the avatar alone), served to signed-in users by `GET /admin/avatar/{id}`
(private, `?v=`).

Users (`users`: unique e-mail, name, role, unique `google_sub`, active or disabled, interface and content
locale, accent, `last_login_at`, `avatar_version`; `bin/atar user:create <email> --role= [--name=]` and
`user:disable <email>`; `user:create` saves the site's default language as the interface language when it has an
admin catalog, so a single-language site's admin speaks the site's language and not the browser's — users made
in the admin keep the browser-following null) are managed through `Auth\UserManager` (the CLI and `/admin/users`, `users.manage`):
`create()` and `update()` validate the e-mail, name and role and, with an actor, require `grants()` for the old
and the new role; no one changes their own role or status (audited `user.created`, `user.updated`,
`user.enabled`/`user.disabled`; a user's own locale, content locale and accent are preferences, not audited);
`canManage()` (oneself, a role the actor's role grants, or a stale role that no longer exists), `canDelete()`
(the same, never oneself). `delete()` is
a hard delete (Plan 33): it refuses oneself (`validation.user.self_delete`) and a role the actor cannot grant, removes
the row and records `user.deleted` with `{email, name, role}` (the history's item name) in one transaction (login
links CASCADE; entries', revisions' and media's authors and audit actors SET NULL, so content stays without an
author and the deleted user's old audit rows show no actor), then removes `storage/avatars/{id}.webp`. `AuthenticationMiddleware` loads the user
on every request, so a deleted (or disabled) user's session is invalidated on its next request.

## Languages and locale routing

`config/locales.php` (`I18n\Locales`: codes like `en` or `pt-br`, unique, the default among them) gives the
default language no URL prefix and every other `/{code}/…`. `LocaleMiddleware` — second in the pipeline, outside
the error renderer, so error pages speak the visitor's language; it never throws — reads the first path segment:
a supported code is stripped from the router's view (`RequestPath::withoutPrefix()`) and stored as
`RequestLocale` (code + prefixed); an unknown prefix is just a path (a route or a 404); a GET/HEAD under the
default language's own prefix 301-redirects to the unprefixed path (and `/{code}/` to `/{code}`), query kept.
`RequestLocale::of($request)` is the one way to read it. Admin and auth routes carry `RequireDefaultLocale`
(404 when prefixed), which also marks a route non-localizable. URLs: `LocalizedUrls::path($name, $params,
$locale)` — the `url()` helper — prepends the prefix for non-default languages of localizable routes.

Alternates (`LocalizedUrls::alternates()`): a controller-set `Alternates` request attribute wins — entry pages
list the translation group's public translations, term pages every term translation, previews, error pages and
an empty single none — else the matched named route in every language; fewer than two gives `[]`.
`hreflangs()` adds `x-default`, the default language's URL (the `hreflang()` helper; the sitemap mirrors it).
Translations link rows through `translation_group` (16 random bytes as hex, unique per locale; relation ids
resolve to the query locale's public translation in `ContentQuery::byIds()`); a collection that is not
`translatable()` exists in the default language only (other locales 404).

`I18n\Translator` merges catalogs lowest first — `resources/lang`, then the active theme's `lang/` — so the
theme wins; lookups fall back requested locale → site default → `en` → the key itself (misses logged with
`app.debug`). Messages are ICU (`MessageFormatter`; a format error logs a warning and returns the raw pattern),
and for a right-to-left locale string parameters are wrapped in FSI/PDI isolates (U+2068/U+2069). The admin
interface languages are `InterfaceLanguages::ALL` (en, ru, he, de, fr — `resources/lang`), independent of the
site's content languages; `TextDirection` names the right-to-left languages by primary subtag (he, ar, fa, yi…),
`languageName()` gives a language's endonym (`LanguageNames`), `AcceptLanguage::negotiate()` picks from the
browser (RFC 9110, `iw` → `he`). Identical keys are enforced by `CoreCatalogTest` (core), `SkeletonCatalogTest`
(the skeleton theme) and `theme:check` (any theme against the site's languages).

## Content model and entries

Collections live in `config/collections.php` (`Collection`/`Field` builders, validated by `Collections`).
Entries are written only through `EntryService` (errors are translation keys, ICU params in
`ValidationFailed::$params`) and read publicly only through `PublicContent`/`ContentQuery` (the publishing rule
is `Entry::publicSql()` in SQL and `Entry::isPublicAt()` in PHP, with the `Clock`). Slugs another route would
shadow are refused through `ReservedSlugs` (entries and terms). `ContentModule` is always the last module, so
collection routes never shadow module routes.

`Collection::single()->plain()`: the entry form shows only the fields (`EntryForm::builtins()`, one wide
column), and `EntryService::plainInput()` stores the collection's label in the entry's locale as the title
(`Translator::label()`), published, without SEO texts or image (translations too); saving needs the publish
permission — a plain page is always public, so publishing rights gate it. `Field::richText()->tall()` (a 28rem
editor), `Field::help()` (a muted line under the input, beside the word count of rich text).

## Preview and autosave

The entry form's Preview (a submit button with `formaction`/`formtarget`/`formnovalidate`, works without JS)
posts to `/admin/content/{collection}[/{id}]/preview` (`Admin\Content\EntryPreviewController`, the rights of
saving): `EntryService::preview()` builds a `Content\PreviewEntry` in memory (normalised like a save, best
effort, nothing written), attached to the request with the entry's `RequestLocale` and empty `Alternates`,
rendered with `ContentController::templates()`, `no-store` + `X-Robots-Tag: noindex`; the theme helpers `terms`,
`termsFor`, `entryUrl` answer from it and `editUrl` is null.

Drafts autosave (`autosave.js`, the form's `data-autosave`) to `/admin/content/{collection}[/{id}]/autosave`
(`EntriesController::autosave()`/`autosaveNew()`, JSON: drafts only, 409 for a stale version or a non-draft, 422
`{"invalid"}` skipped quietly, no flash); `EntryService::create()/update(…, autosave: true)` keeps one revision
per author between manual saves (`RevisionRepository::recordAutosave()`, `"autosave": true` in the snapshot),
audited `entry.autosaved` only when added. A successful autosave has no toast (the action bar status
"Saved N minutes ago" instead): the second exception to the every-message-a-toast rule.

## Audit log and revisions

`Auth\AuditLog` records identified actions in `audit_log` (actor — SET NULL when the user goes — action, subject
type and id, JSON meta, IP, time; nothing prunes it, the table only grows): `auth.*` (login, logout,
login_failed, login_link_created), `user.*`, `entry.*` (created, updated, autosaved, restored, deleted,
translated, preset_adopted, reordered), `term.*` (`entries_reordered`: a section's article order), `menu_item.*` and `menu.reordered`, `media.*` and
`media_folder.*`, `message.deleted`, `settings.updated`, `main_menu.reordered`, `demo.seeded`. Anonymous
failures stay out (see "HTTP, sessions and security"). `/admin/audit` (`audit.view`, 50 per page) names each
row's item through `Admin\AuditSubject` (the meta's title, name, label or e-mail; a sign-in's subject falls back
to the actor); the dashboard's activity feed shows the newest 6 rows to `audit.view` users.

Revisions: every manual save, create, translation, preset adoption and restore snapshots the entry into
`entry_revisions` (`data` JSON: slug, title, status, published_at, parent, position, SEO texts, field values,
term ids; `"autosave": true` marks autosaves). An autosave overwrites the same author's running autosave
revision instead of adding one (and the save is audited only when a revision was added). The newest
`content.revisions` are kept (default 20, at least 1, pruned on insert); deleting an entry locale cascades. The
Earlier versions screen and the restore flow are in `docs/admin-ui.md`; `EntryService::restore()` saves the
snapshot back — status, publishing, parent and position kept — as a new version, audited `entry.restored`.

## Admin content screens

`AtarCms\Admin\Content\*` controllers under `/admin/content/{collection}` (registered by `AdminModule`, before
the collection routes). Per-entry rules live in `AtarCms\Content\EntryAccess` (`edit_others`, `publish`); admin
reads in `EntryListing`; forms via `EntryForm` and widget templates `fields/{type}.php` (modules add more with
`Module::adminTemplatePaths()`). Flash messages: `AtarCms\Admin\Flash`.

Admin languages: `locales.admin` (`Admin\LanguageMode`, checked at boot) is `linked` by default — one language per
screen, the content language, which the top bar switches and the interface speaks (English without a catalog; the
catalogs are en, ru, he, de and fr, `I18n\InterfaceLanguages::ALL`);
`AdminLocale` decides it from the route's item (`Admin\ItemLocale`), `?locale=`, the remembered
`users.content_locale`, the browser and the site default, and puts it on the request (`Admin\ScreenLocale`). `separate`
keeps the Profile's interface language (`users.locale`, `InterfaceLanguage`) apart from the top bar's content language
(`ContentLocale`). Details: `docs/admin-ui.md`, "User preferences".

## Copy for translation and machine translation

"Copy for translation" (the translate actions `admin.entries.translate`, `admin.terms.translate`,
`admin.sections.translate`; `EntryService::createTranslation()`, `TermService::createTranslation()`) copies an item
into another language of its translation group: an entry as a draft (a plain single's and a preset page's copy are
published at once, as they have no draft), a term or section as it is (terms have no status). Machine translation
(Plan 38) adds nothing on the server: the offer toasts (`TranslationOffers`, `ToastForm::$machineFrom`) and the
separate mode's Translations line render a second, hidden submit button (`name="machine"`) that `translate.js` shows
only where the browser's on-device Translator API (Chrome's `Translator` global) can translate the pair; the action
then opens the copy's form with `?machine={source}&start=1` (`Admin\MachineTranslation::chosen()`/`query()`). The
linked mode's top bar, which creates a single's copy by itself (Plan 37), opens it with `?machine={source}` only. The
form renders the hidden panel (`partials/machine-translation.php`, `MachineTranslation::requested()` for another
site language, `forEntry()`/`forTerm()` name the inputs to translate) and `translate.js` translates them in the
page: plain inputs by value, rich text through `editor.js` (`rich-text-translate`: block by block with its inline
markup through the editor's data pipeline, kept only when the markup comes back unchanged, else text node by text
node; code untouched, one undo step), the slides' texts. Nothing is sent from the page and nothing is saved by itself
(`autosave.js` stops on `machine-translated`); a draft entry's empty address is regenerated from the translated
title on Save. Details: `docs/admin-ui.md`, "Machine translation".

## Dashboard

`AtarCms\Admin\Dashboard\DashboardData` (stateless) gathers one user's dashboard into the read model `Dashboard` with
a few aggregate queries per view and no cache, each in its table's repository: `EntryListing::statusCounts()`
(public now by `Entry::publicSql()`, waiting, drafts per collection), `publishedSlots()`, `translationCoverage()`,
`RevisionRepository::editSlots()` (edits are stored versions: the newest `content.revisions` per entry),
`MessageRepository::receivedSlots()`, `MediaRepository::totals()`, `TermRepository::popular()`,
`AuditLog::latest()` with `UserRepository::byIds()`. Entry numbers cover only `EntryAccess::viewable()`
collections, every language (popular tags: the content language); messages need `messages.manage`, the feed
`audit.view`, the media tile `media.upload`. Day buckets: SQL counts per 15-minute UTC slot
(`Database\TimeSlot`, `TIMESTAMPDIFF(MINUTE, '2000-01-01', col) DIV 15`; every zone offset is a multiple of 15
minutes), `Admin\Dashboard\DayBuckets` puts each slot on its site day through `SiteTime` (windows of 7, 30 or 90 days
ending today in site time; 90 as 13 weekly columns). `BarChart` and `Sparkline` compute the SVG geometry per
direction. A collection's colour is its config position (`admin-tone-1…8`, the eighth and later share the neutral).

## Taxonomies

`config/taxonomies.php` (`Taxonomy` builder, checked together with the collections by `Collections`);
collections use them via `->taxonomies([...])` or `Field::tags()`. Terms are per locale (`terms`, linked by
`translation_group`) and written only through `TermService`; an entry's terms (`EntryInput::$terms`) go through
`EntryService` → `EntryTerms` (`entry_terms`, snapshotted in revisions). Admin: `AtarCms\Admin\Taxonomy\*` under
`/admin/taxonomies/{taxonomy}` (`taxonomies.manage`). Themes: `terms()`, `termsFor()` (lists), `termUrl()`,
`ContentQuery::withTerm()`; term pages `taxonomies/{name}/show.php` → `term.php`. A tags field posts
comma-separated names; the admin edits it in a tag box (pills, suggestions of the taxonomy's tags of the entry's
language rendered into the page, `tags.js`; `docs/admin-ui.md`, "Tags widget").

## Sections and root routing

The hierarchical taxonomy routed at the root (`Taxonomy::ROOT_ROUTE` `/{slug}`, `Taxonomies::root()`,
`isRoot()`/`isRootTree()`), a collection routed there (pages) and the collections routed `/{sections}/{slug}`
(articles) share one root route, registered last (`/{slug:` `Slug::PATH_PATTERN` `}`: every chain of segments),
named after the collection with the taxonomy's name as a `Router::alias()`; the articles' own routes only build
URLs; archives and feeds register before every variable route. `Content\Http\RootController` resolves a path: a
page (one segment), a term whose trail is the path, an article by its last segment (`ContentController` 301s a
wrong or partial chain, preview 302 + `no-store`), a term by its last segment (`TermController` 301s to its
trail), else a previous slug (below), else 404.

Previous slugs (Plan 48): an entry or term is found by its last segment, so moving it (another section, parent or
section trail) needs nothing; only a changed slug of its own would leave its old address unanswered. The
`slug_history` table (`Content\SlugHistoryRepository`: kind `entry`|`term`, the collection or taxonomy, the locale,
the old slug, the owner's id with `ON DELETE CASCADE`, the last owner of a slug wins) records the old slug when
`EntryService::save()` (an update or a restore, never an autosave of a draft) changes the slug of an entry that is
public at that moment (a draft never had an address) and when `TermService::update()` changes a term's slug. When
`ContentController`/`TermController` find nothing by the slug (never on a preview), `Content\Http\SlugRedirects`
answers 301 to the owner's current path (an entry only while public), the query string kept, so a chain of renames
leads to the last one. A current slug always wins: the history is read only on a miss, and `RootController` defers
it (`SlugRedirects::defer()`) until no page, section or article answers, then asks the page collection, the root
taxonomy and the sectioned collections in that order. No admin screen lists or edits the history.

A section's URL is its trail (`TermUrls::slugPath()`, ancestors from `TermUrls::ancestors()`/
`TermRepository::ancestors()`, one recursive query; `paths()` for lists; `termUrl()`/`termTrail()` read the
taxonomy once per request). `RootSlugs` (through `ReservedSlugs::isReserved($owner, $slug, $locale, $topLevel)`)
keeps the top-level slugs of a root collection and the top-level terms of a root taxonomy apart per locale; a
subsection reserves nothing (`TermService` passes `$topLevel`, `reorder()` refuses moving a term to the top
level onto a page's slug, `validation.tree.reserved`).

A collection route may start with a placeholder of one of its `taxonomies()` routed at the root
(`/{sections}/{slug}`, `Collection::$routeTaxonomy`): that taxonomy is required and single-valued (`EntryTerms`,
`validation.terms.single`), the entry form shows it as a tree select (`entries/terms.php`, parents disabled),
the list adds its column, `TermService::delete()` refuses a term entries take their address from
(`validation.term.has_entries`), and `ContentController` 404s an entry without the term and 301s a wrong term
segment. `EntryUrls` builds such URLs from the route term's trail: lists use `paths()` (ancestors, route terms
and their ancestors batch-loaded; menus, search, sitemap, feeds, alternates, the `entryUrls()` theme helper),
single pages `path()`/`routeTerm()`. `->redirectToFirstChild()` (routed hierarchical taxonomies) 302s a term
with children to `TermTree::firstLeaf()`; the sitemap leaves such parents out.

Admin: `/admin/sections` (`Admin\Taxonomy\SectionsController`, `taxonomies.manage`) is the tree editor of the
sections with the main menu's pinned collections (ids -1, -2…, `MainMenuService`); the term forms of the
sections live under `/admin/sections/…` (`TermRoutes::of()`; `TermRequest` 404s the sections under
`/admin/taxonomies`). Its names open the content (a section the list of the first collection routed by it,
`?term=`; a pinned single its form, an archive its list), its title carries the language's pill (pinned items
are labelled in the content language) and "Add section" ends the title row, as on the other list screens. Each row has three
fixed action slots after "Order articles" (below) — Add a subsection (the new form with `?parent=`, taken only for a term of the same taxonomy
and language), Edit and Delete (red; locked where `TermService::deleteBlockers()` says delete() would refuse:
subsections, or entries that take their address from it, one query) — which open in a `<dialog>` (`dialog.js`,
`Admin\Dialog`: `HX-Request` gets the fragment, a save or a done or refused delete answers `HX-Redirect` with
the flash, a refused form comes back into the dialog with its errors; without JS the full pages); pinned rows
show them locked; a locked button is `aria-disabled` (focusable, never `disabled`) with its reason as `title`,
`aria-describedby` and a tip on keyboard focus (`docs/admin-ui.md` "Locked buttons"). Themes: `termTrail($term)`
for breadcrumbs.

Article order (Plan 49): a term page (`PublicContent::byTerm()`) lists the public entries of the collections it
lists (`Collections::listedBy()`: those using the taxonomy with a route) by `EntryRepository::TERM_ORDER`:
`entry_terms.entry_position` (a correlated subquery; `entry_terms.position` is the order of the terms within one
entry), then newest first. Every pair starts at 0, so an unsorted section (and every tag page) is newest first;
`TermEntryOrder::reorder()` numbers a term's entries 1..n (all of them in the term's language, any status, row
locks, a version of the loaded order, `validation.tree.stale`/`invalid`, one `term.entries_reordered` audit row, no
revisions, entries untouched), so a new or moved-in article (0) comes first. `TermRepository::assign()` keeps
`entry_position` for the terms an entry keeps (an entry save keeps its place). Admin: "Order articles"
(`/admin/sections/{id}/order`, `Admin\Taxonomy\SectionArticlesController`, `taxonomies.manage`), the first slot of a
section row on the Sections screen, offered on the sections without subsections (else locked with its reason; such a
section's page answers 404, as when no collection lists the sections); `ContentQuery` (`entries()->withTerm()`,
`latest()`) keeps its own order.

Section SEO: the root tree's terms carry `Term::$seo` (`meta_title`/`meta_description` columns, per locale,
written by `TermService` only for `isRootTree()` taxonomies, 255/500 characters like an entry's, not copied by
`createTranslation()`); the section form shows "Main | SEO" tabs (`terms/tabs.php`, `terms/seo.php`, `tabs.js`
with an empty `data-tabs` key: not remembered; set up again on `htmx:afterSettle` for the dialog); the SEO tab
has one short help line per input (the title's does not explain `{title}`) and no preview of what an empty text
inherits (user decisions).

## Main menu

`config/content.php` `'main_menu' => ['home', 'news', 'contacts']` (`Content\Menu\MainMenu`, checked at boot:
collections that are a routed `single()` or have an archive, and a root tree taxonomy) makes the theme's `main`
location the sections tree with those collections pinned among its top level: `PublicMenus` builds it (two
queries), `MainMenu::topLevel()` places the pinned items at their positions (`SiteSettings::mainMenuOrder()`,
locale-independent; first in config order until saved), labels are the collections' translated labels.
`MainMenuService::reorder()` splits a submitted tree into the sections (`TermService::reorder()`) and the pinned
positions (`SettingsService::saveMainMenuOrder()`, audited `main_menu.reordered`); pinned items stay at the top
level without children (`validation.sections.pinned`; `tree.js` `data-tree-pinned`). Without the key `main` is
an ordinary menu.

## Menus and tree ordering

The theme's `theme.php` declares menu locations (`AtarCms\View\ThemeConfig`, a container service); the Menus
screen edits `MainMenu::menuLocations()` (all but `main` with a main menu) and leaves the sidebar without such
locations. Items live per location and locale in `menu_items`, written only through `Content\Menu\MenuService`
(URLs pass `MenuUrl`'s allowlist) and read by themes with `$this->menu('main')` (`PublicMenus` → `MenuLink`s,
batch-loaded). The Menus screen works like the Sections screen: each row's Add a subitem (`?parent=`), Edit and
Delete, and "Add item", open `MenuItemsController`'s pages in the screen's dialog (`Admin\Dialog`), full pages
without JavaScript; the item form's "Inside" select chooses the parent (`MenuItemInput::$parentId`;
`MenuService::create()`/`update()` check its location, language, that it is not the item or a subitem, and that the
subtree fits `MAX_DEPTH`; a new or moved item goes last among the parent's children). Menus sits right after
Sections in the sidebar.

Trees (menus, hierarchical pages, hierarchical terms) are reordered through `TreeOrder` and
`MenuService`/`EntryService`/`TermService::reorder()` (the whole tree as JSON plus a version token, checked
under row locks): the arrow buttons post the form, `tree.js` saves a drop in the background
(`Admin\TreeResponder`: `Accept: application/json` → `{"version"}` or 422 `{"error"}`; no "Save order" button,
no status bar — the result is a toast cloned from the editor's `<template>`s, a refused post's errors are error
toasts through the layout's `toasts`), and items with children are an accordion (a click on the row or on its
"2 subsections" button before the moves toggles it, the plural forms for `tree.js` from `I18n\PluralForms` in
`data-tree-counts`; collapsed by default under `.js`, the open ones remembered per screen and language in
`localStorage` and reopened before the first paint by `tree-open.js`, opened by hovering during a drag). Admin:
`/admin/menus/{location}` (`menus.manage`), `/admin/content/{collection}/order`,
`/admin/taxonomies/{taxonomy}/order`, the shared partial `tree/editor.php` and `tree.js` (vendored SortableJS);
admin menu order bands are the `AdminMenu` constants.

## Media

`AtarCms\Media` (core `MediaModule`). Files are written only through `MediaService` → `MediaUploader` (finfo
MIME allowlist from `config/media.php` — finfo decides the type and the stored extension, never the client;
pixel limit checked before decoding, GD re-encode in `ImageProcessor`; videos (`video/mp4`/`video/webm`) and
PDFs are stored as uploaded, with the video size limit `media.max_video_size` (default 100 MB,
`MediaConfig::maxSizeOf()`/`largestUpload()`) apart from the general `max_size`; random `MediaPath`s written
atomically by `MediaStorage`); SQL on `media`/`entry_media` only in `MediaRepository`. The `doctor` warns when
PHP's `upload_max_filesize` is below the video limit; the shipped web/PHP body limits fit the default
(`DeployConfigTest` pins them across nginx/Caddy/Apache/php.ini/FPM).

Serving: originals are `/uploads/{path}`; variants come from `theme.php` `images` (plus the reserved `admin`
thumbnail, 320 px contain, pre-generated as WebP at upload, and the site icon's PNG crops
`icon_{32,180,192,512}`), generated by `/media/{variant}/{path}` into `storage/cache/media/` on the first
request (`MediaFileController::variant()`, an atomic write; a failed decode logs and 404s): `contain` (default)
fits inside the box, `crop` covers it and crops the centre, neither ever enlarges; quality per variant (default
82, JPEG progressive, metadata stripped). Every image URL has a WebP twin (`{path}.webp`) for `img()`'s
`<source>`, and — where `ImageFormats` says GD writes AVIF (`imageavif()`, `gd_info()`'s "AVIF Support" and a 1×1
test encode run once per request when an image first asks, unless `media.avif` is false) — an AVIF twin (`{path}.avif`, WebP originals included, never for the icon variants; GD at
speed 6 with the variant's quality × 0.7, made on the first request like the others; without AVIF such URLs 404
and `img()` adds no AVIF `<source>`). `img()` (`ImageTag`) also does art direction: its `sources` option maps media
queries to a variant or a `[Media, variant]` pair, each a group of `<source media>` elements (AVIF, WebP, original)
with their own srcset, `width`/`height` before the default ones. `?v=` is a hash of the variant's settings — but the disk cache is keyed by path alone, so after
changing a size run `bin/atar media:variants:clear [--variant=]` (originals untouched, files regenerate on
request; deleting a media item removes its original and every cached variant). File responses are
`public, max-age=31536000, immutable` with `Content-Security-Policy: default-src 'none'; sandbox`. Generated
share images are `/uploads/share/{id}-{fingerprint}.jpg` (`MediaStorage::share()`, stateless route `media.share`
for stacks without nginx; no `?v=` — the name changes with the content). `MediaFileController` sends images and
videos inline (the `Content-Disposition: attachment` rule is PDF-only, in the PHP fallback and the three web
server configs alike) and answers single byte ranges (206 with `Content-Range`, 416 past the end, malformed →
200; `FileRangeStream` streams the window and survives the emitter's rewind; Safari refuses to play video
without Range).

Entries store media ids (`Field::image/gallery/file`, the slides' image and video); `EntryService` writes each
entry's references (fields and rich-text links) to `entry_media` through `EntryMedia`, and `ON DELETE RESTRICT`
foreign keys keep used files; `bin/atar media:references:prune` releases references of collections or fields
removed from the config. Themes: `mediaFor()` (batch), `img()`, `mediaUrl()`, `ogImage()`.

Leftovers: deleting files is best effort (a failed `unlink()` leaves an orphan rather than failing a committed
delete) and a killed process can leave a staged upload or an atomic write's temporary file. `bin/atar media:cleanup
[--dry-run]` (`Media\Cleanup\MediaCleanup`, a manual sweep, no cron) lists the files with
`MediaStorage::inventory()` — only strictly named ones: originals, variants, share images, `storage/tmp/uploads/`
staged uploads, `.<name>.<12 hex>.tmp` files; never symbolic links, dot files such as `.gitkeep`, other names,
`storage/tmp/doctor-probe.json` or avatars — then reads the database and deletes originals and variants without a
`media` row for their path, share images no `entries.share_image` names (of any collection, configured or not),
staged uploads and temporary files. Every writer puts the file in place before its row, so files modified less than
a day ago (`MediaCleanup::MIN_AGE`) are never touched, and `MediaStorage::removeStored()` keeps a file whose
modification time changed since the listing (a share image's name is deterministic, so a reverted entry may write
and record the very file being swept); no locks beyond that. A restore that preserves modification times (`tar`,
`rsync -a`) makes restored files look old while the database is not restored yet: never run the sweep during a
restore. `removeStored()` also refuses a file that does not lie where its display name says. Each deleted path is
printed with a count per kind; a file that cannot be deleted is printed on stderr, the sweep goes on and the exit
code is 1. Variants of a variant name the theme dropped have a row and stay (`media:variants:clear`).

Admin: `/admin/media` (`media.upload`; delete `media.delete`), widgets and picker in `media.js` (also the
rich-text editor's image picker), rich-text image uploads in `editor.js`; admin page scripts go through the
layout's `scripts`/`styles`, and vendored ESM files carry their version in the file name. Folders:
`media_folders` (any depth, sibling names unique ignoring case and accents through `parent_key`) and
`media.folder_id` (null = "All files"), database only, so moving never changes a file's path or URL; written
only through `MediaFolderService` (create, rename and move in one update with a version, cycles refused, only
empty folders deleted; audited `media_folder.*`), SQL only in `MediaFolderRepository`; files move through
`MediaService::move()` (`media.moved`). Folders are managed with `media.upload` on `/admin/media/folders/…`
pages, opened in the library's dialog (`dialog.js`; delete `media.delete`), never in the picker. The current
folder's subfolders also open the results of the library and the picker (`media/folder-items.php`: tiles in the
grid, rows in the list, navigation only, first page of a plain browse).

`MediaRepository::page(MediaQuery)` lists a folder's own files, a search the folder and its subfolders
(`MediaFolderTree::withDescendants()`) or everything ("everywhere"), by type group (`image` = image/*, `video` =
video/*, `document` = the rest), newest/oldest/name/size, and "unused": no `entry_media` row and none of the
site settings' uses — logo, icon, sharing image (`MediaQuery::$alsoUsed`, filled from `SiteSettings::mediaUses()`,
which `MediaService::delete()` also refuses and the usage lists show). The library and the picker share `Admin\Media\MediaListQuery` (GET state built into every link) and the
partials `media/{folders,trail,toolbar,dropdown,folder-items}.php` (one self-submitting GET toolbar whose htmx
requests swap only the results); the grid/list view (library and picker apart) and the picker's last folder are
cookies the server reads (`MediaPreferences`, set by a request with `?view=` / the picker's `?folder=`, `0` =
the root). Bulk move and delete (`MediaBulkController`, checkboxes joined to `#media-bulk` with `form=`, delete
through a confirmation page, used files kept and counted in a toast). The picker's selection lives in `media.js`
(a `Map` in click order, re-marked after every htmx swap, gallery files "Already in the gallery", uploads and a
single field's file pre-selected, double click / Enter choose one file) with the footer of `media/dialog.php`;
the picker kinds `image`/`video` list and upload only their group. A video's item page plays it
(`admin-media-player`); a chosen video's chip card in widgets is a player too.

## Slides and the hero slider

`Field::slides()` — a list of `{image, title, subtitle, url, button, video}` in the entry's data (image
required — it is the poster and the no-JS/reduced-motion fallback; the other keys optional, lengths
255/500/255/100, `url` through `Content\Menu\MenuUrl`, `video` an optional media id of a library video, at most
`max ?? 10`; `SlidesType` is idempotent and survives crafted posts, the no-JS `position`/`delete` keys sort and
drop rows; `Slide` objects from `Entry::field()`; not in the search index — hero is a showcase, not content).
`EntryMedia::validate()` has its own slides branch before the generic one (a missing image refuses the save, a
restore drops only that slide; a gone video clears the key and keeps the slide; a non-video in the video key is
`validation.slides.video`; the generic branch would replace the value with a bare id list). Both the image and
the video are referenced in `entry_media`.

Each slides field is its own main entry-form tab (`FormTab::$field`, labelled by the field's label; a `plain()`
form with one gets tabs, without one stays a single column). Widget `fields/slides.php` + `slides/{item,dialog}.php`
+ `slides.js` (`SlidesAssetsTest`): rows with drag (SortableJS) and arrows; Add/Edit open the dialog rendered
after the entry form (forms cannot nest; only `<input>`s — a textarea's line breaks would be lost copying into
the row's inputs), Done writes into the row's inputs and fires `change` for autosave — nothing is saved before
the entry's Save; without JS fieldsets with order numbers and delete boxes. The dialog's optional Video picker
shows the chosen video as a player chip card (no name, the remove button in the corner; closing the dialog
pauses it); the row shows a video badge named after the file.

The skeleton's home `hero` is a slides field: `partials/hero-slider.php` + `js/slider.js` (6 s autoplay, dots,
direction-aware arrows, a quiet icon-only Pause with a tooltip (hidden via `[hidden]` + its CSS twin when
still), pauses on hover/focus/hidden tab, still under `prefers-reduced-motion` and `a11y-still`; without JS the
first slide; one picture slide = still without controls, one video slide = a Pause-only bar (moving content
must be stoppable); a slide with `url` + `button` draws the button, with `url` alone the whole slide is the
link, named `aria-label` "N of M" when it has no texts), loaded only on pages with `data-slider`. The first slide is
the page's LCP: a picture slide's `<img>` is eager with `fetchpriority="high"`, a video slide's poster is preloaded
from the `<head>` (`collections/home/show.php` passes `preloadImage` to the layout: `<link rel="preload" as="image"
fetchpriority="high">`, the poster's exact URL); the other slides stay lazy. A slide's
video renders `<video muted loop playsinline preload="metadata" poster aria-hidden>` — never an `autoplay`
attribute: `slider.js` alone plays the shown slide's video while motion is allowed. No YouTube/Vimeo in slides
(iframes make poor backgrounds; `Field::youtube` remains for ordinary embeds). `demo:seed` fills 3 picture
slides per language (GD cannot make videos). The slides data does not dictate a carousel — the theme guide's
"Hero slider" lists the other patterns (one headline + rotating pictures, rotating texts over one picture…).

## Rich text

`Field::richText()` is edited with CKEditor 5 48.5.2 (GPL, vendored byte-exact in
`resources/admin/assets/vendor/` with `ckeditor5.LICENSE`/`ckeditor5.COPYING.GPL`; glue `editor.js`, settings
from `Admin\Content\EditorConfig` in the widget's `data-editor`; without JS a plain HTML textarea). The theme's
`theme.php` `editor` key (`View\EditorSettings`: `css` inside `assets/`, scoped to `.ck-content`; `styles` with
distinct labels; `templates` as UTF-8 `.html` files that must survive the sanitizer; each mistake is a
`ConfigException` at boot) feeds the Style and Ready-made blocks lists; `colors`, `fonts`, `fontSizes` and
`markers` feed the palette and font, size and highlighter lists (absent: CKEditor's defaults; `[]`: off).

The toolbar is the full word-processor set in two rows with "More formatting", "Insert" and "Tools" dropdown
groups (labels `admin.editor.group.*`, layout pinned by `EditorAssetsTest`); `Field::richText()->minimal()`
gives a field the minimal profile (editor: bold, italic, font size and colour; stored: `p`, `br`, `strong`,
`em`, `span` with a size class or `color`), and `->max()` on rich text limits its visible text
(`RichText::text()`). Features write classes where CKEditor can (`View\EditorClasses`: alignment, indentation,
image styles, to-do lists, table alignment, code languages, sizes, markers) and inline styles only where it
cannot (colours, font families, image and table sizes, table borders and cells).

Saving runs `Content\Field\RichTextSanitizer` (allow-list; `style` only through `StyleAttributeSanitizer`,
per-element properties with `View\CssValue` grammars; fixed-value attributes through
`KeywordAttributeSanitizer`; every `<input>` a disabled checkbox) in `RichTextType::normalize()`, then
`Content\RichTextTheme::filter()` in `EntryService` (only `classes()`, the theme's and the features', and
`fontFamilies()`; colours are never filtered). Videos (YouTube and Vimeo only, `Content\Field\VideoUrl`) are
stored as canonical `<oembed url>` and read as `<iframe class="rich-text-video">` (`VideoEmbeds` in
`RichTextType::read()`); the CSP always allows their two hosts in `frame-src` (`security.csp.frame_src` adds
others), `style-src` is `'self'` plus the two hashes of CKEditor's colour picker
(`SecurityHeadersMiddleware::STYLE_HASHES`, pinned against the bundle by `EditorAssetsTest`) and
`style-src-attr 'unsafe-inline'` lets the sanitized style attributes apply.

## Search

`AtarCms\Search` (core `SearchModule`). `EntryService` keeps `search_index` current through `SearchIndexer` in
the entry's transaction: the title and the `text`/`textarea`/`richText` values (`EntryText`) as stems of the
entry's locale (`TextNormalizer`, Snowball via `wamania/php-stemmer` for `ru`/`en`/`de`/`fr`); every status is indexed and
`Entry::publicSql()` filters at query time. SQL on `search_index` only in `SearchRepository` (InnoDB FULLTEXT in
boolean mode; stems under 3 characters are neither indexed nor queried, so a query of only such words never
reaches the database). Routed collections are searched unless `->searchable(false)` (`Collections::searchable()`).
`/search` renders the theme's `search.php` (404 without it; it passes `robots` to the layout for `noindex`);
`bin/atar search:reindex` rebuilds the index (needed once after a stemmer change, as Plan 39's German and French
stemming: entries indexed before it keep their unstemmed words until reindexed).

## Site settings

`AtarCms\Settings` (core `SettingsModule`) — the `settings` table (`name`, `locale` `''` for site-wide; new
settings are new rows), SQL only in `SettingsRepository`, read through `SiteSettings` (`of($locale)` →
`Settings`: per-locale `siteName`, `siteShortName`, `metaTitle` (the home page's whole title),
`metaDescription`, `address`, `openingHours`; site-wide `logoId`, `iconId`, `themeColor`, `titlePattern`,
`hideFromSearch`, `ogImageId`, `phone`, `email`, messengers (`whatsapp`/`viber` as `+` and digits, `telegram`
without `@`), `socialLinks`/`messengers` as `list<SocialLink>` in `Settings::SOCIAL_NETWORKS`/`MESSENGERS`
order, `yandexVerification`/`googleVerification`, the accessibility toolbar switch and corner, the chat button switch
`chatButton` (Plan 47: `contact.chat_button`, site-wide, off unless `'1'`; a contact detail saved by the contact
page's form after the messengers, kept by the Site settings screen, `demo:seed` and `settings:import` key
`chat_button`; the skeleton theme draws a round button in the bottom end corner, above the toolbar's when that sits
there, whose `<details>` panel lists the filled `messengers` as plain links, or that is the link with one
messenger: no widget script, no cookie, no CSP host; see `docs/theme-guide.md`, Chat button), the analytics counters
`yandexMetrica`/`yandexWebvisor`/`googleAnalytics` and the per-locale `consentText` (Plan 40), the advertising pixels
`metaPixel`/`googleAds`/`vkPixel`/`tiktokPixel` (Plan 41), and `accent()`, the site
colour: `AdminAccent::nearest($themeColor)`, the preset whose logo colour is nearest in OKLab with lightness weighted
by 0.1, green without a theme colour); `forRequest()` memoised; `siteName($locale)` = the
setting, else the theme catalog's `site.name`, else `app.name`; `pageTitle()` by the pattern, default
`{title} — {site}`; `mediaUses()`: logo, icon and sharing image are uses, never "unused", refused by
`MediaService::delete()`), written only through `SettingsService` (normalisers in `SettingsValues`; the icon a
square PNG/JPEG/WebP of at least 512 px; one transaction, audited `settings.updated`).

Admin: `/admin/settings` (`Admin\Settings\SettingsController` + `SettingsForm`, `settings.manage`, the gear in
the Settings sidebar group), three tabs (General, SEO, Integrations; the contact details live on the contact
page — see "Contact details and the Google map") and an images card, per content language (the top bar's
switch). The admin brand (`adminBrand` helper, `Admin\AdminBrand`) is the default language's short name (else
its site name) beside the site icon or, without one, the AtarCMS logo (the aleph of Cardo Bold on the admin
colour, pre-rendered per preset in `resources/admin/assets/logo/`, byte-exact, `Settings\AtarLogo`); the admin
colour is `<html data-accent>` on every admin page and the presets are tokens in `admin.css`. Since Plan 34 it is
personal: the signed-in user's `users.accent` (the Profile's Interface tab; `AdminErrorLocale` passes it to their
admin error pages as the request attribute `AdminAccent::class`), else the site colour (`Settings::accent()`) — the
login page, guests and users without a choice. The theme colour field offers the presets as ready-made colours
(JavaScript only) and its help line says it picks the site colour.

Theme helpers (`SettingsViewHelpers`): `siteHead()` (icon 32/180, or without a site icon the logo SVG + PNG
32/180 in the site colour; manifest, `apple-mobile-web-app-title`, `theme-color`, the verification metas, the
last two only when set), `pageTitle($title)`, `siteName()`, `siteLogo()`, `socialLinks()`, `messengers()`, plus
`siteSettings()`; none on a 5xx page. The icon's square PNG variants `icon_32`/`icon_180`/`icon_192`/`icon_512`
are reserved in `ImageVariants` (refused in `theme.php`, outside srcset families) and served only with a `.png`
suffix (`x.jpg.png`); `/manifest.webmanifest` and `/favicon.ico` (302 to `icon_32`) are stateless
`SiteIconController` routes (default language, `max-age=3600`; without an icon the logo's PNGs of the site
colour). `hide_from_search`: `SearchVisibilityMiddleware` adds `X-Robots-Tag: noindex, nofollow` to public
responses (not `/admin`, `/uploads`, `/media`, nor one with its own header) and leaves out the `/llms.txt` `Link`
header and the snippet directives it otherwise adds to public HTML pages (see "AI readiness"), `robots.txt` disallows everything,
the sitemaps 404, and the dashboard tells `settings.manage` users. `SocialMeta` (`og:site_name` = the site name)
falls back to the site title, description and image; feeds are named after the site; theme layouts fall back for
`<meta name="description">` themselves (not on 5xx pages).

`bin/atar settings:import <json> [--dry-run] [--user=] | --example` (`Settings\SettingsImport` shape,
`SettingsService::import()` shares `save()`'s validation, one transaction, audit `settings.updated` with
`source: import`).

## Contact details and the Google map

One Google Maps iframe for the whole site (no key), the setting `contact.map` (locale `''`,
`Settings\GoogleMap::toArray()` as JSON; `Settings::$map`): Google's embed (its `pb`, kept as pasted, the
language inside it) or a query (an address or `"lat,lng"` with 6 decimals, a zoom 3–19) embedded as
`maps?q=…&z=…&hl={page locale}&output=embed`; `linkUrl()` (Open in Google Maps) for a query or an embed with
`!2d!3d`; `wazeUrl()` (Directions in Waze, `https://waze.com/ul?…&navigate=yes`): `ll=` the point (a "lat,lng" query or an
embed's `!2d!3d` centre), else `q=` the query; null for an embed without a centre (the admin map panel then says so
above the map, else links the route there). `GoogleMapInput::parse($method, $input)` reads the four ways (`embed`: the whole `<iframe>`, its src
or the bare pb, only `https://www.google.com/maps/embed?pb=` with the pb charset; `link`:
`www.google.<tld>/maps…`/`maps.google.<tld>`, the marker `!3d!4d`, else `@lat,lng,Nz`, else `q`/`query`, else
`/place/<name>/` or `/search/<text>`; short links refused `validation.map.link_short`; `address`;
`coordinates`: a pasted pair split, a decimal comma refused) plus `query` (the stored shape, `settings:import`),
errors `validation.map.*` under the input names.

`config/content.php` `'contact_page' => 'contacts'` (`Settings\ContactPage`; a routed plain single, checked at
boot; without it no contact details or map UI, they are written only by `settings:import`, and `contactMap()` is
null). The contact page's entry form ends its side column with the tabs Address | Contacts | Social
(`SettingsForm::CONTACT_TABS`/`contactFields()`; shown only with `EntryAccess::canEditPublished()`,
`settings.manage` not needed — the details belong to the page's editor): its Save checks them first
(`SettingsService::checkContacts()`; a refusal stays at its field and nothing is saved), saves the entry, then
writes them (`saveContacts()`, audited `settings.updated` with the locale and the changed names; no nested
transactions); the Address tab ends with `map/panel.php` (`#contact-map`, Change and Remove on one row).
`Admin\Content\ContactMapController` (`/admin/contact-map`, `/admin/contact-map/delete`, rights
`EntryAccess::canEditPublished()` — collection-level on purpose, no `edit_others`: the map is site-wide and the
entry may not exist yet — else 403; an unknown way 400 `Http\Exception\BadRequest`) opens in the form's dialog
(tabs per way, each its own form, footer buttons `data-tab-for`), saves through
`SettingsService::saveMap()`/`removeMap()` (audited `settings.updated`, `fields: ['contact.map']`) and answers
htmx with the new column (`HX-Retarget`/`HX-Reswap`) and `HX-Trigger-After-Settle` `atar-dialog-done`
(`dialog.js`: toast from `<template data-dialog-toast>`, close, focus), so the form's unsaved text stays;
without JS full pages back to the form. Theme helper `contactMap()` → `?MapView` (`embedUrl`, `linkUrl`, `wazeUrl`);
`GoogleMap::FRAME_SOURCE` (`https://www.google.com`) is always in `frame-src`.

## Forms and messages

`AtarCms\Messages` (core `MessagesModule`, Plan 52). **Definitions**: `Form\FormDefinitions::fromConfig()` reads the
site's `config/forms.php` (`forms`: key => `recipient` (null = the settings e-mail) and `fields` (name => `type`,
`required`, `options`, `min`, `max`)) and is built at boot, so a mistake stops the site with a message naming the
form and the field (`ConfigException`): keys and names `[a-z][a-z0-9_]*` (keys ≤ 40), the nine `Form\FieldType`s
(text, email, tel, textarea, date, time, select, radio, checkbox), the reserved `name`/`email`/`phone`/`message`
with their fixed types (stored in the message's own columns), no field `website` (the honeypot) or `form` (the
form-level error key), options only on select/radio (distinct `[a-z0-9][a-z0-9_-]*`), bounds only on date
(`today`, `+N days`, `Y-m-d`) and time (`H:i`) with `min <= max`, a required `email` or `phone` per form, no
unknown keys. The built-in `contact` form (name, email required; phone; message required, ≥ 10 characters) is
first in `all()`; a `contact` entry replaces its fields. `configured()` lists the forms of the file.

**Routes**: per configured form (not `contact`) `GET /forms/{key}` (`forms.show.{key}`) and `POST /forms/{key}`
(`forms.send.{key}`, `Route::publicForm($key)`), locale-prefixed, the key as the `FormDefinition::class` route
attribute (`Http\FormController`); an unknown key is the router's 404, and `ReservedSlugs` keeps the root slug
`forms` while a form exists. `content.contact_form` still names the routed `single()` whose page shows the contact
form; its address takes the POST (`messages.send`, `ContactFormController`: 303 `?sent=1#contact`; a refusal renders
the page again through `ContentController::single()` with a `Form\FormState` attribute, 422, 429 for the limit).
`FormController::show()` renders the theme's `form.php` (404 without it) with `form`, `X-Robots-Tag: noindex`, no
canonical. `send()` takes only `application/x-www-form-urlencoded` (else 415); with `Accept: application/json` (the
dialog) it answers `200 {"ok":true,"message":forms.{key}.sent}` or `422/429 {"ok":false,"errors":{field: text},
"form": text|null,"token": a fresh token}` (texts translated in the request's language, never the input;
`Cache-Control: no-store`), otherwise 303 to `/forms/{key}?sent=1` or the page again with `FormState` (422/429). The
message's `page_url` is the `Referer` when it has `app.url`'s origin (the page the dialog was opened on), else the
form page.

**Submitting**: `Form\FormService::submit($form, FormInput, $locale, $pageUrl, $ip)`: the `PublicFormToken` is
checked with the form's key (a token of another form is expired), honeypot `website` and too-fast tokens are
discarded as if sent, `Form\FieldValidator` checks and cleans each configured field (posted keys not configured
are ignored; a non-string value is empty): one-line text (`name` ≤ 100, other text/tel ≤ 200, `phone` the phone
pattern ≤ 50), e-mail ≤ 200, textarea ≤ 5000, options against the list, real `Y-m-d` dates and `H:i` times within
their bounds ("today" is the site's day: `SiteTime::today()` with the `Clock`; date/time values are wall-clock and
never converted; date bounds in errors through `SiteTime::readableWallDate()`), a checked checkbox stored as `1`;
validation keys `validation.field.*`, `validation.email.invalid`, `validation.message.*` and `validation.form.*`.
At most 3 per 10 minutes and 20 a day per `ip_hash` (an HMAC; no plain IP) over all forms; anonymous (no audit).
The reserved fields go to their columns, the others to `messages.fields` (JSON, the form's order, empty ones left
out); SQL on `messages` only in `MessageRepository` (`latest()`/`count()` per form, `unreadCountsByForm()`).
`Message` has `form`, nullable `name`/`email`/`body`, `fields`, `sender()` (name, else e-mail, else phone).

**Mail**: `MessageMailer` (symfony/mailer, `mail.dsn`/`mail.from` from `MAIL_DSN`/`MAIL_FROM`, `MailTransport`, 5 s
timeout) mails a message in the default language to the form's `recipient`, else the settings e-mail, `From` the
site, `Reply-To` the visitor's e-mail when given; the subject is the theme's `forms.{key}.subject` (`{name}` the
sender, `{site}`), else the core's; the body lists the fields in the form's order with `Form\FormLabels` (the theme's
`forms.{key}.{field}`, option labels `forms.{key}.{field}.{option}`, a checkbox as yes; the contact form keeps the
core `mail.message.*` labels; fields no longer configured under their names), the page and the time, then the
message. A failure is logged and the message kept (`mailed_at` null); `bin/atar mail:test <to>`.

**Themes**: helpers `form($key)` and `contactForm()` (`Form\FormViews` → `Form\FormView` with `Form\FormFieldView`s;
never touch the session); the skeleton's `partials/form-fields.php`, `form.php`, `partials/form-dialog.php` and
`assets/js/forms.js` (theme guide, Forms). `theme:check` (`ThemeChecker`) wants `form.php` when a form has its own
page and warns about missing `forms.{key}.*` texts of the forms of `config/forms.php`.

**Admin** `/admin/messages` (`messages.manage`, `Admin\Messages\MessagesController`, the top bar's `inbox` link
first, before the theme and the content language, with the unread count of all forms, `unreadMessages()`; no sidebar
item): with more than one form a row of plain links filters by form (`?form=key`, unread counts) and a badge names
each message's form; `MessageFields` gives a message's rows in the form's order and the list's preview; opening marks
read, mark unread, Reply (`mailto:`, with an e-mail), Call (`tel:`, with a phone), delete on a confirmation page
(audited `message.deleted`, named by the sender). Inbox operations are `MessageService`'s.

## SEO, share images and page titles

Public content controllers attach the page's absolute canonical URL as a request attribute
(`AtarCms\Http\CanonicalUrl::attach()`): entry pages (never previews), `single()` pages once their entry exists,
archives and term pages (`?page=N` from page 2 on, built by `EntryUrls::archive()`/`TermUrls::absolute()`);
search, preview and error pages have none. Theme layouts print `$this->canonical()` and
`$this->socialMeta($title, $description, $entry, 'large')` (`Content\SocialMeta`: Open Graph and `twitter:card`
tags, only on pages with a canonical URL, which is their `og:url`; the image through `Media\OgImage`, shared
with `ogImage()`) next to `$this->hreflang()`.

Structured data: after its tags `socialMeta()` prints the page's schema.org JSON-LD
(`Content\StructuredData\StructuredData`, so no theme prints it itself): one `<script type="application/ld+json">`
data block with an `@graph` list, on the same pages (with a canonical URL), nothing when the graph is empty. It is
never executed, so it needs no nonce (`ThemeChecker` exempts it too); `json_encode` escapes `<`/`>` (`JSON_HEX_TAG`,
so no text can close the element) and the line terminators, and replaces invalid UTF-8. The home page (the `single()` collection routed at `/`) is the
`WebSite` of its language (`@id` its canonical URL + `#website`, such as `https://example.com/#website` and
`https://example.com/ru#website`: one node per language, as its name, URL and language differ; `name` the site name,
`url` the canonical URL, `inLanguage`, `publisher` the site's
organization by its `@id`; no `SearchAction`: Google retired the sitelinks search box), followed by that organization
(`Content\StructuredData\Organization`, Plan 46): its type is `content.organization_type` (default `Organization`;
a local business sets `LocalBusiness` or a subtype such as `Restaurant`, a schema.org type name, checked when a page
prints it), `@id` `{app.url}/#organization`, `name` the site name, `url` the site root, and from the settings of the
page's language what is filled: `logo` (the site logo, else the icon, absolute), `telephone`, `email`, `address` (the
multi-line text on one line, not split into street and city), `geo` (only when the map is a "lat,lng" point) and
`sameAs` (the social links; messengers are not). The opening hours are left out: they are free text, and
`openingHours` wants `Mo-Fr 09:00-18:00`. An entry of an `articleLike()` collection (one with an archive or a route taxonomy,
never a single; plain and nested pages are not) is an `Article`: `headline` its own title, `description` and `image`
as `og:description`/`og:image` (left out when empty), `datePublished`, `dateModified` (the later of `updated_at` and
`published_at`), both UTC ISO 8601 (`StructuredData::time()`), `inLanguage`, `url`, and the site's organization
(`Organization::reference()`: its type, `@id`, name and `app.url`) for `author` and `publisher` (user decision:
editors' names are not published). Any other entry page (plain and nested pages, singles other than the home page)
is a `WebPage` and a page without an entry (an archive, a term or section page) a `CollectionPage`: `name` the page
title as `og:title` has it, `description` as `og:description` (left out when empty), `url`, `inLanguage` (the entry's,
else the request's) and `isPartOf` the `WebSite` of that language by its `@id` (with its type, the site name and the
home page's URL in that language, `{app.url}/` or `{app.url}/{locale}`, like an article's organization); no query of their own. A
`BreadcrumbList` comes from the `Content\StructuredData\PageTrail` request attribute, which `ContentController::show()`
and `TermController::show()` attach next to the canonical URL (never on previews), built by `Breadcrumbs` from what
they have already loaded: a sectioned entry below its route term's trail, another entry of a collection with an
archive below the archive (the collection label in the entry's language), a nested page below its public parents (a
draft parent is left out; the controller loads the ancestors once and hands them to `EntryUrls`), a term of a
hierarchical taxonomy below its parents (a section from the tree already read, other taxonomies with one ancestors
query); each item has its absolute URL, the page's is its canonical one, and there is no "Home" item. It is printed
only with an ancestor (at least two items), so flat pages, top-level sections, tags and archives have none. Themes
read the same trail through `pageTrail()` (its items, `[]` without one), so visible breadcrumbs can match the
JSON-LD; the skeleton's `partials/breadcrumbs.php` uses it when it has an ancestor and keeps the template's own
items otherwise (and on previews, which have no trail).

Share images: there is no manual SEO image; after each save (create, update, restore, translation; never
autosave), outside the transaction, `Content\ShareImage\ShareImages::sync()` gives an entry of a routed
collection (not a plain single) with a main photo (`MainPhoto`: `Collection::shareImage(...$fields)`, default the
first image and the first gallery field; the admin entry lists show the same photo as a thumbnail, loaded for a
whole page by `ShareImages::mainPhotos()`) a 1200 × 630 JPEG titled by its title for search engines, else by its own
title (a user decision after Plan 20, which needed the former), drawn by `Media\Share\ShareCard` (plain
GD, needs GD with FreeType; Open Sans Bold vendored byte-exact in `resources/fonts/`, pinned by
`ShareFontAssetTest`; layout `band`/`bottom`/`panel` from `theme.php` `share_image`, `ThemeConfig::$shareLayout`;
the brand: the site icon or the AtarCMS logo of the site colour (`Settings::accent()`), the site name, the domain of `app.url`;
characters the font lacks, such as emoji, are dropped through `FontGlyphs` (its cmap), and a title left empty
makes no card; right-to-left text is wrapped in logical order, then each line is reordered by `I18n\Bidi` (a
UAX #9 subset on `IntlChar`: Hebrew, Latin, numbers, brackets, isolates; no Arabic shaping) in the direction of
the entry's language (`TextDirection`, in `ShareCardInput`/`ShareBrand` and the fingerprint), a right-to-left
language mirrors the whole layout (`ShareCard::x()`: the title right-aligned, the brand's logo at the right with
both lines flush against it, the panel and the accent bar on the right; never the photo or the logo themselves);
geresh and gershayim fall back to `'`/`"`; `ShareCard::VERSION` in the fingerprint redraws on the next save),
named `{id}-{fingerprint}.jpg` in `entries.share_image` (set by `EntryRepository::setShareImage()`, a
compare-and-set that never touches `updated_at`; not in revisions); an unchanged fingerprint skips the work, a
failure is logged and leaves no image, deleting the entry deletes the file;
`bin/atar share-images:rebuild [--collection=]` after brand, layout or drawing (`VERSION`) changes. `og:image`
of an entry: the generated image (with `og:image:width`/`height`, no query), else the main photo in the theme's
variant, else the site's sharing image. The entry form's SEO tab starts with the saved entry's generated card
and one line under it, and shows nothing there without one (user decisions: no heading, no link preview card,
no fallback picture).

An empty `single()` advertises no alternates; the sitemap adds `x-default` to translated pages like
`LocalizedUrls::hreflangs()`. Page titles and descriptions: `ContentController` (show,
single) and `TermController` pass `$seo` (`Content\PageSeo`, from terms they already loaded, no query) and theme
templates hand `$seo->title`/`$seo->description` to the layout: an entry's own texts win; else an article takes
the nearest section (its route term, then the parents) whose title holds `{title}` (`PageSeo::PLACEHOLDER`,
filled once with the page's title; a section title without it applies to that section's own page only) and,
separately, the nearest section description; a section page its own texts (its own title filled with its name),
then its parents'. A null description falls back to the site's in the layout and `SocialMeta`; the site title
never takes part.

## Feeds, sitemap and robots

Feeds are RSS 2.0 (the Atom namespace only for the self link), opt-in per collection: `->feed()` needs a route
and an archive and is refused on a `single()` (`Collections`). `content.{collection}.feed` serves
`{archive}/feed.xml` per language (stateless; a collection that is not translatable has it only in the default
language) with the newest 20 public entries (`FeedController::ITEMS`): title, absolute `<link>` and
`<guid isPermaLink>`, `pubDate`, and the entry's meta description when it has one — never the body. The channel
is "{collection label} — {site name}", `lastBuildDate` the newest entry's time. No helper links it: a template
passes `'feed' => $this->url('content.news.feed')` to the layout, which prints the
`<link rel="alternate" type="application/rss+xml">`.

`/sitemap.xml` (`content.sitemap`, stateless, default locale) lists the public entries of every routed
collection and the terms of every routed taxonomy, in every language (`Content\Sitemap`); terms a
`redirectToFirstChild()` taxonomy only redirects (those with children) are left out. Only the configured languages
(`Locales::all()`) count: a site that drops a language keeps its rows, which have no pages any more, so
`PublicContent`'s sitemap reads and `TermRepository`'s sitemap counts and chunks leave them out (and with them the
sitemap's alternates and the paging of `/llms.txt` and `/llms-full.txt`); `PublicContent::translations()` does the
same for the markdown twins' translation links (page hreflang alternates were always filtered by `LocalizedUrls`,
which `ContentController` now also feeds through it). Each `<url>` carries
`<loc>`, `<lastmod>` (`updated_at`) and, for a translation group in more than one language, `xhtml:link`
hreflang alternates plus `x-default` (the default language's URL when it exists). Up to `content.sitemap_limit`
URLs (10–50000, the default and cap; out of range fails the boot) it is one `<urlset>`; past it an index of
pages `/sitemap-{entries|terms}-{source}-{n}.xml` (`SitemapPage`: whole translation groups per page, collections
before taxonomies in config order; a page out of range is a 404). `/robots.txt` (`RobotsController`) is the
`content.robots` string verbatim when set, else "Disallow: /admin" and the site's Content Signals
(contentsignals.org, `Content-Signal: search=yes, ai-input=yes, ai-train=no` in the `User-agent: *` group: search
and AI answers may use the content, model training may not; no setting) plus the `Sitemap:` line; "Hide from search
engines" overrides all of it (`Disallow: /`, sitemaps 404, `X-Robots-Tag` — see "Site settings").

`/llms.txt` (`content.llms`, stateless, default locale; `Content\Llms\LlmsTxt`) is the sitemap's content as a
markdown map for LLM agents (llmstxt.org): the site's name, the default language's meta description as a
blockquote, the language list, then, language by language (the default first, suffixes only on a multilingual
site), one `## {label} ({locale})` section per routed collection and routed taxonomy with `- [title](URL)` per
public entry or term (the sitemap's chunks, so nested paths and redirect-only terms behave the same; empty
sections are left out; `Content\Llms\LlmsContent` gathers both, shared with `/llms-full.txt`). After
`content.llms_limit` links (default 1000) it stops and names the sitemap. "Hide from search engines" makes it a 404.
`/llms.txt` also names `/sitemap.md` (`content.sitemap_md`, stateless, default locale, `text/markdown`;
`Content\Llms\MarkdownSitemap`, Vercel's Agent Readability check): the same map without the llms-specific lines —
`# {site} — sitemap`, the description and language lines, the same sections, order and limit
(`LlmsContent::listing()`); a 404 while hidden. The literal route reserves the root slug `sitemap` (a root page's twin
would be `/sitemap.md`; `ReservedSlugs`).

`/llms-full.txt` (`content.llms_full`, stateless, default locale; `Content\Llms\LlmsFullTxt`) is the full-text companion
docs sites publish beside llms.txt: the same header plus a line naming `/llms.txt`, then every public entry page in
`/llms.txt`'s order as its markdown twin (below; rendered with the ancestors its sitemap chunk loaded and without the
twin's frontmatter) with
`Source: {URL}` under its `# title`, blocks separated by `---`. The sections stay flat — no `##` section headings,
each block keeps its twin's heading levels and is a document of its own; terms have no markdown and are left out.
After `content.llms_full_limit` entries (default 100: a few queries each, so the response stays well under a second
on a small VPS) or once the text passes 1 MiB (`LlmsFullTxt::MAX_BYTES`, about 250k tokens) it stops and names
`/llms.txt`. "Hide from search engines" makes it a 404. The admin's counterpart for AI agents is `/admin/ai-guide`
(docs/admin-ui.md "AI guide").

Every entry page has a markdown twin at its address + `.md` (`content.{collection}.md`, stateless; `/llms.txt`
says so): `/news/hello.md`, `/ru/about/team.md`, a single at `/` answers at `/index.md` (slugs never contain dots,
so no other address gains one). `ContentModule` registers a twin per entry route (singles' with the static
archives and feeds, the root route's after it — pages and articles only, never section pages); the route attribute
`EntryMarkdown::class` makes `ContentController` resolve the entry exactly as for the page and answer with
`Content\Markdown\EntryMarkdown` instead (`text/markdown`, `X-Robots-Tag: noindex`, `Link: <page>; rel="canonical"`): no
preview, a previous slug or a wrong chain 301s to the canonical twin, "Hide from search engines" makes every twin
a 404. Public entry and single pages carry their twin's path as the `Content\Markdown\MarkdownTwin` request
attribute, and the theme helper `markdownAlternate()` prints it as `<link rel="alternate" type="text/markdown">`
(nothing while the site is hidden from search engines); `ContentController` sends the same as an HTTP header under the
same conditions, `Link: </news/hello.md>; rel="alternate"; type="text/markdown"` (root-relative like the describedby
value, added beside it; never on a markdown response), so agents that read headers only find it. The page's own address negotiates too: when the `Accept`
header prefers `text/markdown` to `text/html` (`Http\AcceptHeader`: q-values, the most specific range wins; a tie,
`*/*` and browsers' headers get HTML) it answers with the twin's markdown and `Link` canonical but without the twin's
`noindex` (that address is the canonical page, which a crawler preferring markdown must not drop), and the HTML and markdown variants of
every entry and single page send `Vary: Accept`; never with `?preview=1` or while the site is hidden (plain HTML,
no `Vary`), a single without its public entry stays HTML, and its redirects (previous slug, wrong chain) go to the
page addresses, which negotiate again. A CDN or cache that ignores `Vary: Accept` (Cloudflare's "Cache Everything",
for one) could serve the markdown variant to browsers: there, leave the HTML pages uncached or bypass the cache when
the request's `Accept` holds `text/markdown`. A literal `{slug}.md` segment reserves the slug (`ReservedSlugs`: with a single at `/`, no page takes
`index`). The document (served at both addresses) opens with YAML frontmatter (`EntryMarkdown::document()`,
`Content\Markdown\Frontmatter`): `title`, `description` (when set), `url` (the canonical page), `lang`, `published`
(UTC ISO 8601, not on a plain single) and `updated` (JSON-LD's `dateModified` rule), each a one-line double-quoted
YAML scalar (whitespace collapsed, quotes, backslashes and non-printable characters escaped, invalid UTF-8 replaced),
so no title can end the block or become YAML syntax. Then `# title`, the meta description as a blockquote, facts (publication date via
`SiteTime`, except on a plain single; language with links to the public translations' twins; one line per
`taxonomies()` taxonomy, linked when routed; one line per scalar field), then a `## {label}` section per block field
(rich text, textarea, gallery, slides). Rich text goes through `Content\Markdown\RichTextMarkdown`, a DOM walk over the
sanitizer's element set (tables stay HTML, videos become links; no markdown library).

## AI readiness

Three surfaces meet AI agents, all built in and all hidden by "Hide from search engines" where public:
`/admin/ai-guide` (the admin described for a signed-in browser agent, filtered by the user's permissions; the
page footer's "AI guide" link and `<link rel="help">` point to it — details in docs/admin-ui.md "AI guide"),
`/llms.txt` (the public site as a markdown map), `/llms-full.txt` (its full text), `/sitemap.md` and the `.md` twins
of entry pages with their frontmatter and `Link` alternate header (all above, in "Feeds, sitemap and robots"). Every public HTML page (2xx `text/html`, not the admin) points to
`/llms.txt` with a `Link: </llms.txt>; rel="describedby"` header (`SearchVisibilityMiddleware`, added to a `Link`
header the response already has; none on a response with its own `X-Robots-Tag` such as a preview or a form page,
and none while the site is hidden, when `/llms.txt` is a 404) — a header, not a
`<link>` tag, so themes need nothing. The same pages allow full snippets in search results and AI answers with
`X-Robots-Tag: max-snippet:-1, max-image-preview:large, max-video-preview:-1` (`SearchVisibilityMiddleware::SNIPPETS`;
`content.snippet_directives` replaces it, `''` sends none, anything but a one-line string fails the boot, `ContentModule::routes()`);
since the middleware checks for an existing `X-Robots-Tag` before adding either header, previews, form pages and twins
keep their `noindex` and a hidden site its `noindex, nofollow`. The admin's no-JavaScript rule and semantic markup are what make browser agents reliable there, and
`skeleton/.agents/skills/building-atarcms-sites` is the agent skill that builds sites. The README's "AI-ready"
section is the owner-facing summary. Decisions: one known guide URL instead of hidden per-page hints (hidden text
never reaches an agent reading the accessibility tree and pollutes screen readers); browser agents only — an editing
API/MCP is a deliberate non-goal until there is a real use (noted in the development repository's backlog).

## Preset pages

`Collection::presets(['privacy' => 'site.presets.privacy'])` (slug => label; `Collections` refuses them without
a route ending in `/{slug}`, on a single or hierarchical collection, with an invalid slug or a slug declared
twice site-wide; `ContentModule::checkPresets()` at boot refuses a slug another route answers or a locale code,
`ReservedSlugs::byRoutes()`). `entries.preset` (unique per collection and locale), `Entry::$preset`,
`Entry::isPreset()` (a key no longer configured: an ordinary page). `Content\Preset\PresetPages::sync()` (run by
`bin/atar migrate` after the migrations, and by `DemoSeeder` after the wipe) creates every missing one in every
language of its collection through `EntryService::createPreset()` (label in that language, published, empty, no
author, audited `entry.created` without an actor, linked as translations) or adopts the group's translation or
the entry at its address (`adoptPreset()`, `entry.preset_adopted`); a top-level section or another page at the
address is reported (`PresetChange` `refused`, on stderr, exit 0); a second run changes nothing.

Locks in `EntryService`: `delete()` refuses (`validation.entry.preset`; `EntryAccess::canDelete()` false), every
save keeps the slug and publishes (a posted slug or status is ignored, `presetInput()`; restore too),
`createTranslation()` makes the same preset at the same address; translating a plain single or a preset takes
the publish permission (`EntryAccess::canTranslate()`, also for the Translations line and the offer toast);
`theme:check` reports a preset label key missing from a catalog; a sync that loses the unique-key race joins the
other run's translation group; `ReservedSlugs` reserves the slugs for their collection in every locale and
`RootSlugs` for the other owners of `/{slug}`. Admin: the lock chip `admin-chip-preset` (`lock` icon) in the
list's Author cell, the form without status and date, the address `readonly`, a locked Delete. Theme helpers
`presetEntry($slug)`/`presetUrl($slug)` (`PublicContent::presets()`, one query per request, null when missing or
not public). The skeleton's `pages` has `privacy` and `accessibility`; the footer links both (`footer-legal`);
`demo:seed` fills them (`DemoTexts::PRIVACY`, `STATEMENT`) and keeps them out of the menus.

## Tooltips

`resources/admin/assets/tooltip.js` (every admin page, guests too) and its byte-identical copy
`skeleton/themes/default/assets/js/tooltip.js` (pinned by `TooltipAssetsTest`) show every `title` in one shared
`#tooltip` (manual popover, `textContent` only), placed by CSS anchor positioning
(`[data-tooltip-anchor] { anchor-name: --tooltip }`, `position-area: block-start` with flips) in `admin.css` and
`site.css`; hover (mouse/pen, 300 ms) and `:focus-visible`; the title waits in `data-tooltip-title` while shown
(an element named only by it gets it as `ariaLabel` meanwhile) and always comes back (hide on leave, Escape,
other keys but Tab, a captured `toggle`, press, scroll, resize, `htmx:beforeSwap`, dialog `close`, `pagehide`;
dismissed under the pointer the title stays parked until it leaves); the tip hides at once (transition only on
`:popover-open`); Escape with a tip open closes only the tip. Labelled admin icons are `role="img" aria-label`
(never an SVG `<title>`, which browsers show as a box). Skips `.ck`, `option`, `iframe`, `[data-no-tooltip]`;
without popover, `anchor-name` or `position-area` support or JS the native title stays. The file names no
`admin-` class; templates keep plain `title`s (plus `aria-label` for icon-only controls). See
`docs/admin-ui.md` (Shell) and `docs/theme-guide.md` (Tooltips).

## Accessibility toolbar

Site-wide setting `site.accessibility_toolbar` (`Settings::$accessibilityToolbar`, on by default: only "off" is
stored as `'0'`; kept by `demo:seed` (`DemoWiper::KEPT_SETTINGS`)); its corner
`site.accessibility_toolbar_position` (`Settings::TOOLBAR_POSITIONS` `top-start`|`bottom-start`|`top-end`|
`bottom-end`, logical so RTL mirrors, default `bottom-end` not stored, `SettingsValues::toolbarPosition()`,
admin field kind `position` under the switch, import key `accessibility_toolbar_position`, kept by `demo:seed`;
the partial's root carries `data-a11y-position` and `site.css` places the button and opens the panel towards the
page, clear of the admin bar and the header); Site settings → General switch; `settings:import` key
`accessibility_toolbar`, `""`/`null` = on.

The skeleton theme's own toolbar (no third-party overlay): `partials/a11y.php` (a fixed round button and a
non-modal `role="dialog"` panel: `aria-pressed` option buttons, −/+ text size with `aria-disabled` limits and an
`<output>`, Reset, the statement link) and `assets/js/a11y.js` (classes `a11y-text-1..3`, `a11y-spacing`,
`a11y-font`, `a11y-contrast`, `a11y-grayscale`, `a11y-invert`, `a11y-links`, `a11y-focus`, `a11y-cursor`,
`a11y-still`, `a11y-guide`, `a11y-no-images` on `<html>`, stored as JSON in `localStorage` `atar-a11y`; Escape
on `window` after `tooltip.js`; the reading guide's `--a11y-guide-y` through a constructable stylesheet); the
layout's bootstrap applies the stored classes (only the known names) before the first paint unless the toolbar
is off; filters go on `.site-header`/`.site-main`/`.site-footer`, never `<html>`/`<body>`
(`SkeletonThemeAssetsTest`); big cursors `assets/cursors/*.svg` drawn from Lucide. Statement: the preset page
`accessibility` of `pages`, linked from the panel by `presetUrl('accessibility')` while it is published in the
page's language. See `docs/theme-guide.md` (Accessibility toolbar).

## Analytics counters, advertising pixels and the cookie banner

Settings (Site settings → Integrations, Plans 40 and 41), all site-wide and kept by `demo:seed` except the text, audited
`settings.updated` with the other settings. Statistics: `analytics.yandex_metrica` (digits, `SettingsValues::metrica()`,
`validation.settings.metrica`), `analytics.yandex_webvisor` (`'1'`, stored only with a counter),
`analytics.google_analytics` (`G-` and 4–20 letters or digits, upper-cased, `validation.settings.google_analytics`).
Advertising: `analytics.meta_pixel` (digits, at most 20), `analytics.google_ads` (`AW-` and 4–20 digits, upper-cased),
`analytics.vk_pixel` (digits, at most 12), `analytics.tiktok_pixel` (10–30 letters or digits, upper-cased), each with
its `validation.settings.*` key. `analytics.consent_text` per locale (a line of at most 500 characters; empty = the
theme's text). `settings:import` keys `site.yandex_metrica`, `site.yandex_webvisor` (bool), `site.google_analytics`,
`site.meta_pixel`, `site.google_ads`, `site.vk_pixel`, `site.tiktok_pixel`, `locales.{code}.consent_text`. Microsoft
Clarity and Google Tag Manager are not offered (GTM needs `unsafe-eval` or inline code: an accepted ruling).

`AtarCms\Analytics`: `Counters::of(Settings)` holds the ids in two consent categories, `statistics()` (`metrica`,
`google_analytics`) and `advertising()` (`meta`, `google_ads`, `vk`, `tiktok`), `services()` both; the script URLs
(`METRICA_SCRIPT`, `GTAG_SCRIPT` without the id, `META_SCRIPT`, `VK_SCRIPT`, `TIKTOK_SCRIPT`) and the CSP hosts
(`allowIn(CspSources)`, each service's only when it is set; sources in the plans): Metrica's `script-src` mc.yandex.ru,
mc.yandex.com and yastatic.net, the 18 official `mc.yandex.*` hosts in `img-src`/`frame-src` and, https and wss, in
`connect-src` (tag.js opens `wss://mc.yandex.ru/solid.ws` without Webvisor too); Webvisor adds mc.webvisor.com/.org
(https and wss in `connect-src`, and in `frame-src`); Yandex's frame-ancestors list (Metrica's click maps and Webvisor's player frame the site; the framed
page is always anonymous, the session cookie being `SameSite=Lax`) with any Metrica counter; GA4
www.googletagmanager.com in `script-src`, plus `*.google-analytics.com` in `img-src`, plus `*.analytics.google.com` and
`*.google.com` in `connect-src`; Meta connect.facebook.net (`script-src`, `connect-src`) and www.facebook.com (`img-src`,
`connect-src`); Google Ads www.googletagmanager.com, www.googleadservices.com and www.google.com in `script-src`, those
with googleads.g.doubleclick.net, pagead2.googlesyndication.com and the country domains www.google.ru, .co.il, .de, .fr
and .co.uk (Google's www.google.<TLD>, not wildcardable) in `img-src` and `connect-src` (plus ad.doubleclick.net),
www.googletagmanager.com and td.doubleclick.net in `frame-src`; a Google tag (GA4 or Ads) beside any advertising pixel
adds `*.g.doubleclick.net` to `img-src` and `connect-src` (GA4's advertising features); VK top-fwz1.mail.ru and
privacy-cs.mail.ru (`script-src`, `connect-src`), top-fwz1.mail.ru and ad.mail.ru (`img-src`); TikTok
analytics.tiktok.com (`script-src`, `img-src`, `connect-src`). `Consent` (registered by `SettingsModule` with the admin
assets' `AssetUrl`) backs the theme helpers: `consentHead()` → the loader `<script type="module"
src="/admin-assets/consent.js?v=…" nonce data-atar-consent data-metrica data-metrica-src data-webvisor data-ga
data-google-ads data-gtag-src data-meta data-meta-src data-vk data-vk-src data-tiktok data-tiktok-src>` (each pair only
with its service) and the hosts in this response's CSP, `''` without a service or for a signed-in user (whose policy
stays the plain one); `consent()` → `?ConsentView` (`metrica`, `webvisor`, `googleAnalytics`, `metaPixel`,
`googleAds`, `vkPixel`, `tiktokPixel`, `statistics`, `advertising`, `services`, `text`, `privacyUrl` = the preset page
`privacy`, `state` = `?ConsentChoice` from the `atar_consent` cookie, informational), null without a service or for a
signed-in user.

The decision is made in the browser (every visitor gets the same page, so a cached page stays right):
`resources/admin/assets/consent.js` (admin script rules: no markup, styles, `setAttribute`, requests or host names)
offers the categories with a configured service and reads `atar_consent`: a token per decided category, `s`/`a` and
`1`/`0`, statistics first (`s1`, `s1.a0`, `a1`; Plan 40's `1`/`0` read as `s1`/`s0`, so advertising is asked anew; anything else is no choice;
`ConsentChoice::fromCookie()` reads the same format), `Max-Age` 180 days, `Path=/`; `SameSite=None; Secure` on https,
so the page framed by Metrica's click map or Webvisor's player on metrika.yandex.ru sees the choice and loads the
counter; `SameSite=Lax` on http, where None is refused. While an offered category is undecided it shows the theme's
`[data-consent]` (rendered `hidden`, never focused by itself): a category added later (an ad pixel after a
statistics-only Accept) asks again. A save writes the offered categories over the stored ones.
`[data-consent-accept]`/`[data-consent-decline]` decide every offered category; `[data-consent-choose]` (with
`aria-expanded`) opens `[data-consent-panel]`, whose `[data-consent-category]` checkboxes show the stored choice
(unchecked while undecided) and `[data-consent-save]` stores them. A granted category loads its scripts with the
loader's nonce (`script.nonce`) and initialises them itself, as the official snippets do: statistics
`ym(id, 'init', {clickmap, trackLinks, accurateTrackBounce, webvisor})` and GA4; advertising Meta's `fbq` queue
(`init`, `PageView`), Google Ads, VK's `_tmr` queue (`pageView`, the script's id `tmr-code`) and TikTok's `ttq` queue
(`load`, `page`). Google's gtag.js loads once for GA4 and Google Ads (the first allowed id names it, the other is
configured on it) after `gtag('consent', 'default', …)` with Consent Mode v2 signals from the choice
(`analytics_storage` = statistics, `ad_storage`/`ad_user_data`/`ad_personalization` = advertising), later choices as
one `consent update` with all four signals at the start of every later choice, whichever Google id the site has; each
id is configured only with its category. A denied category is withdrawn (again on every page while denied):
statistics sets `ga-disable-{id}`, advertising `fbq('consent', 'revoke')` and `ttq.revokeConsent()` (the Google
signals came with the update); then the category's first-party cookies go (statistics
`_ym*`, `mdd`, `_ga*`, `_gid`, `_gat*`; advertising `_fbp`, `_fbc`, `_gcl_*`, `_ttp`, `_tt_enable_cookie`, `ttcsid*`,
`tmr_*`, `domain_sid`, on the host and its parent domains) and its storage keys (`_ym*`, `tmr_*`). Metrica and the VK counter, which
have no stop call, run until the page is left; allowing a category again on the same page resumes what is loaded.
`[data-consent-open]` reopens the banner (the panel open on the stored choice when there is one) and focuses it; after a
choice the focus returns there. Without JavaScript nothing loads and nothing shows; there are no noscript pixels. The
skeleton's banner is `partials/consent.php` (see `docs/theme-guide.md`, Cookie banner). With every service set the
response headers are about 4.3 KB: the shipped nginx template raises the FastCGI buffers (`fastcgi_buffer_size 16k`,
`fastcgi_buffers 8 16k`), nginx's default 4 KB answered 502. `security.csp.img_src` and `security.csp.connect_src`
(`config/security.php`) add hosts to every response, such as more of Google's country domains.

## Demo content and the admin bar

`bin/atar demo:seed [--force] [--dry-run] [--user=]` (`AtarCms\Demo`): `DemoWiper` deletes the content (entries,
terms, menu items, media rows and files, share images, variants, messages, settings except
`DemoWiper::KEPT_SETTINGS`) through the repositories' `deleteAll()`; `DemoSeeder` fills every configured locale
through the services only (GD placeholders labelled "Purpose · W×H", sections with subsections, articles, news
with tags, pages, singles by field type, hero slides, footer menu, main menu order, demo settings with a map),
deterministic; it asks for "yes" (`Cli\Prompt`) unless `--force`. `DemoStructure` reads the sections, root pages
and tags from the optional `config/demo.php` (config key `demo`), each key falling back to `DemoTexts`; its
`problems()` lines lead `DemoSeeder::problems()`, so a malformed file refuses before the wipe. Every per-item create
(term, page, single, preset fill, article, news) goes through `DemoSeeder::guarded()`: a `ValidationFailed` (a taken
address) becomes a `skipped: <kind> "<slug>" (<key>)` line in `seed()`'s result and the command's output, and the seed
goes on.

The public admin bar: theme helper `adminBar($entry, $term)` (`Admin\AdminBar` → `list<AdminBarAction>`:
dashboard, "Add: {collection}" for at most 3 routed non-single collections the user may create in, with
`?locale=`, Edit for the page's entry or term, Messages with the unread count; empty for visitors and on
stateless routes; the skeleton draws it as `partials/admin-bar.php`, a floating column at the inline-start edge,
a bottom row on phones). Other cross-cutting theme helpers: `langAttrs()`, `languageName()`,
`termChildren($taxonomy, $parent)`, `readableDate($time, false)` (the day alone), `ContentQuery::withinTerm()`
(a term's descendants) — the full list is in `docs/theme-guide.md`.

## Theme tools and the site-builder skill

`bin/atar theme:check [<theme>] [--json] [--no-render]` (`Theme\Check\ThemeChecker`: config, templates per page
type, sources (CSP, literal menu/image/route/asset/translation names, unescaped property chains), catalogs, CSS
(`LogicalCss`, skipped for `theme.php` `'direction' => 'ltr'`, which an RTL locale refuses), scripts, vendor
licences; then `RenderSmoke` renders every page type in every locale through
`Application::boot($paths, ['app.theme' => …])` with a `RecordingLogger`; exit 0/1/2).
`bin/atar theme:new <name> [--from=]` (scaffold `resources/themes/scaffold`, must pass theme:check).

The skeleton ships the agent skill `.agents/skills/building-atarcms-sites/` (pointer `.claude/skills/…`,
`AGENTS.md`, `CLAUDE.md` = `@AGENTS.md`); `SiteBuilderSkillTest` pins its frontmatter, files, every `bin/atar`
command/option it names and its `references/theme-guide.md` = `docs/theme-guide.md` (copy the guide after
editing it).

## Install, deploy and release

The skeleton's `bin/atar` runs `Kernel\ConsoleEntry` (PHP-version and `vendor/` guards first): `doctor` runs
before boot, any other command boots and a boot failure is one message pointing at the doctor. `AtarCms\Doctor`:
`Check`s over a `Platform` (`NativePlatform`; `FakePlatform` in tests) and a `DoctorContext` that loads `.env`
→ config → boot in stages; groups `PhpChecks`, `ConfigChecks`, `StorageChecks`, `DatabaseChecks`, `MailChecks`
(`--mail`), `WebChecks` (`--http`, `--http-via` = curl CONNECT_TO keeping the site's host, `--http-ca`); exit 1
on failures (`--strict`: warnings too), `--json`; `gd.formats` fails without JPEG/PNG/WebP/GIF, `gd.avif` only warns
(AVIF is optional; ok when `media.avif` turns it off; `NativePlatform` adds the test encode as "AVIF Encodes", so a
libavif without an encoder warns too); never prints secrets (tests grep for them). The web probe:
`WebProbe::issue()` writes the SHA-256 of a token to `storage/tmp/doctor-probe.json`, stateless
`GET /_atar/doctor/{token}` answers once within 60 s with facts only (`FactsPlatform` lets `PhpChecks` judge the
web PHP), else the normal 404.

One user runs the site's PHP (FPM, composer, `bin/atar`); the web server only reads (Docker: `www-data` moved to
`HOST_UID`/`HOST_GID`; servers: `deploy/php-fpm/atarcms.conf`). `/theme-assets/` and `/admin-assets/` have a
stateless PHP fallback (`View\Http\AssetFileController`, strict paths, type allowlist, the nginx headers). A server
that restricts `/admin` by IP must keep `/admin-assets/` public: the public site loads the logo files, `tooltip.js` and
`consent.js` from it. Web
server configs in `skeleton/deploy/` (`.avif` variants go out as `image/avif`: nginx's `/media/` names every variant type in its own `types` block,
Apache adds `AddType image/avif .avif`, Caddy's table has it; `DeployConfigTest` pins both; nginx template shared with Docker dev, Caddyfile, Apache vhost +
`public/.htaccess`, last-resort `root.htaccess`), headers pinned by `DeployConfigTest`. Each config compresses text
responses (nginx `gzip` level 5 from 1 KB, Apache `mod_deflate`, Caddy `encode zstd gzip`): HTML, CSS, JS, JSON, SVG,
XML/feeds, plain text; images, video and woff2 are compressed already and left out, and no server compresses a byte
range, so videos keep their 206 answers. `/admin` is never compressed (BREACH): its pages carry the session's
synchronizer CSRF token, stable for the session, next to input they reflect (searches, filters), the textbook
condition; the public pages carry no per-session secret (public forms use the signed, non-secret
`PublicFormToken`; the admin bar has no token), and the editors' few admin pages gain little from compression.
nginx sends `^/admin(/|$)` to PHP from its own location with `gzip off`, Apache sets `no-gzip` by `THE_REQUEST`
(the URL is already `index.php` after the rewrite), Caddy's `encode` skips `/admin` and `/admin/*`;
`skeleton/compose.production.yaml` (Caddy, restart, healthchecks). Release: tag → `.github/workflows/release.yml`
→ `.github/scripts/export-core.sh` (the public mirror `yanfishel/atarcms-core`, secret `CORE_REPO_DEPLOY_KEY`) and
`.github/scripts/export-skeleton.sh` (`yanfishel/atarcms`, secret `SITE_REPO_DEPLOY_KEY`); the core's
`.gitattributes` `export-ignore`s every dev file (`PackageExportTest`), and the mirror leaves out the private
working files (`PublicMirrorTest`). See `docs/releasing.md`.
