# Changelog

Every version of AtarCMS (`yanfishel/atarcms-core`) and of its site skeleton (`yanfishel/atarcms`, same version).
What a site must do after an update is in `UPGRADE.md`.

## v0.1.0

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
