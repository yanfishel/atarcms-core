# Changelog

Every version of AtarCMS (`yanfishel/atarcms-core`) and of its site skeleton (`yanfishel/atarcms`, same version).
What a site must do after an update is in `UPGRADE.md`.

## v0.2.10

- Both READMEs list the agentcheck.dev, Frase and agent-ready.dev checks of atarcms.com only.

## v0.2.9

- Both READMEs show the agentcheck.dev full check of atarcms.com at 100/100 and the other agent-readiness checks
  taken on v0.2.8.

## v0.2.8

- Archives and term pages (tags, categories, sections) have markdown twins too: `/news.md`, `/tag/releases.md`,
  `/company/team.md`, `?page=N` like the HTML. Each opens with frontmatter, then the title, the term's description,
  links to the other languages and pages, and the page's entries (and a section's subsections). Like entry twins they
  carry `noindex` and a canonical link, the HTML page names them (`markdownAlternate()` and the `Link` header), and
  the page answers `Accept: text/markdown` with them. `/llms.txt` says so.
- The frontmatter's modification time is now `last_updated` (was `updated`), and `description` falls back to the
  site's description of the page's language.
- Both READMEs show the agent-readiness checks of atarcms.com taken on v0.2.7.

## v0.2.7

- Entry pages name their markdown twin in an HTTP header too: `Link: </page.md>; rel="alternate";
  type="text/markdown"`, under the same conditions as the `markdownAlternate()` tag.
- Every markdown twin (and the markdown an entry page answers for `Accept: text/markdown`) opens with YAML
  frontmatter: `title`, `description`, `url`, `lang`, `published` and `updated`. `/llms-full.txt` stays without it.
- `/sitemap.md` is a markdown sitemap of the same public content as `/llms.txt`, which links it; a 404 while the
  site is hidden from search engines. A root page can no longer take the slug `sitemap`.
- Public HTML pages allow full snippets in search and AI answers:
  `X-Robots-Tag: max-snippet:-1, max-image-preview:large, max-video-preview:-1` by default, set by
  `content.snippet_directives` in `config/content.php` (an empty string turns it off; a value that is not one line
  fails the boot).
- Both READMEs show the agentcheck.dev full check (97/100) and a table of four independent agent-readiness checks
  of atarcms.com.

## v0.2.6

- Public listings keep to the configured languages: entries and terms of a language removed from
  `config/locales.php` no longer appear in `/sitemap.xml` (as pages or hreflang alternates), the paging of
  `/llms.txt` and `/llms-full.txt`, or the translation links of the markdown twins.
- Public HTML pages point AI agents to `/llms.txt` with a `Link: </llms.txt>; rel="describedby"` header (not while
  the site is hidden from search engines, not on previews).
- Entry pages answer `Accept: text/markdown` with their markdown twin at the page's own address (browsers still get
  HTML); both variants send `Vary: Accept`.
- The default `robots.txt` declares `Content-Signal: search=yes, ai-input=yes, ai-train=no` (contentsignals.org).
- Every page with a canonical URL has schema.org JSON-LD: plain pages are a `WebPage`, archives and term pages a
  `CollectionPage`, each part of the `WebSite` of its language (whose `@id` is now its home URL + `#website`).
- The core's test suite runs in parallel through ParaTest (development only).

## v0.2.5

- Both READMEs show the Lighthouse scores centred and at their natural size.

## v0.2.4

- Entry pages' markdown twins (`{address}.md`) are served as `text/markdown; charset=utf-8`, matching the
  `markdownAlternate()` link; the shipped nginx and Apache configs compress them (Caddy does by default).
- Both READMEs are up to date: the Lighthouse scores of atarcms.com (Performance included), the requirements, the
  full list of third-party components, and fresh admin screenshots with the page footer.

## v0.2.3

- The site handbook (`HANDBOOK.md`) is up to date: Composer from Packagist at `^0.2`, the linked admin language,
  section article order, German and French search stemming, video and AVIF media, hero slides, the chat button, an
  admin overview, a table of every `bin/atar` command and the config keys it lacked.
- `bin/atar demo:seed` names exactly which site settings it keeps; the Google OAuth comment in `.env.example`
  follows the current Google Cloud console.
- Both READMEs show the agentcheck.dev "AI Ready" score.

## v0.2.2

- `/llms-full.txt` gives LLMs the full text of the site in one request: the `/llms.txt` header, then every public
  entry page in `/llms.txt`'s order as its markdown twin with a `Source:` line under its title, separated by `---`
  (terms are left out). After `content.llms_full_limit` entries (default 100) or 1 MiB it stops and points to
  `/llms.txt`, which now links it. Like `/llms.txt`, it is served for the default locale only and is a 404 while
  "Hide from search engines" is on.
- The theme helper `$this->markdownAlternate()` prints `<link rel="alternate" type="text/markdown">` to the
  current entry page's markdown twin (`/index.md` for a single at `/`), nothing on other pages, previews or while
  the site is hidden from search engines; the default layout uses it.

## v0.2.1

- The dashboard no longer pulls the sticky sidebar up near the bottom of the page: the activity chart's screen
  reader table kept its full height although visually hidden and stretched the page past the admin shell.
- `bin/atar doctor` builds the admin menu (`config.admin_menu`): a `Collection::icon()`, `Taxonomy::icon()` or
  module item icon that is not a symbol of the admin sprite used to pass every command and fail only on the first
  admin page; the doctor now names the item, its module and the bad icon.
- The admin page footer links the AtarCMS site and the GitHub repository, on the login page too.
- From 768px the login page shows the site's brand beside the sign-in card: the mark large on a soft gradient of
  the site's admin colour, with the site name.

## v0.2.0

- Demo content follows the site: an optional `config/demo.php` gives `bin/atar demo:seed` the brief's sections
  (with subsections), pages and news tags per language; without it the generic demo structure. A malformed file
  stops the seed before anything is deleted (typos such as an unknown language or a reused address are named up
  front), and an address the site already uses is skipped and reported, never fatal. The site builder skill writes
  the file from the brief.
- `bin/atar user:create` saves the site's default language as the new user's interface language, so a fresh admin
  speaks the site's language instead of following the browser.
- The admin pages end with a quiet page footer (the AI guide link, the year and the fishart mark), which the
  sidebar carried before.
- The site handbook and the launch checklist walk the owner through creating the Google OAuth client for
  "Sign in with Google" (console project, consent screen, redirect URI, `.env`), and the brief warns about it
  early; the questionnaire, the README prep list and the theme phase carry the other lessons of the first
  cold-run site build.
- Both READMEs are reworked around what AI agents can do with the CMS, and `composer create-project` resolves
  everything from Packagist (no GitHub token, no `--repository`).

The first version: content (collections, sections, taxonomies, menus), media library, search, site settings, the
admin in five languages (English, Russian, Hebrew, German and French), share images, the default theme, the site
builder skill, `bin/atar doctor`, web server configs for nginx, Caddy and Apache, and the Docker setups for
development and production.

AI readiness is built in: the admin describes itself to AI browser agents at `/admin/ai-guide` (per user, filtered
by their permissions), the public site has a markdown map at `/llms.txt` (`content.llms_limit`, default 1000 links),
and every public entry page has a markdown twin at `{address}.md` (canonical link, `noindex`; old slugs redirect
like the HTML pages). "Hide from search engines" hides all three.

Open source: the code is public under the MIT licence (`LICENSE`); vulnerabilities are reported privately
(`SECURITY.md`), and the public repository is a per-release mirror (`CONTRIBUTING.md`).

A draft version: AtarCMS is still in its greenfield phase, so a later 0.x version may change the database schema in
place and need a fresh install (see `UPGRADE.md`).
