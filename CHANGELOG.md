# Changelog

Every version of AtarCMS (`yanfishel/atarcms-core`) and of its site skeleton (`yanfishel/atarcms`, same version).
What a site must do after an update is in `UPGRADE.md`.

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
