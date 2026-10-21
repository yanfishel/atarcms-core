<p align="center">
  <img src="resources/admin/assets/logo/atar-green.svg" width="88" alt="">
</p>

<h1 align="center">AtarCMS</h1>

<p align="center">
  <b>A small multilingual CMS that AI agents can build, run and read.</b><br>
  PHP 8.4 · no build step · one small VPS is enough
</p>

<p align="center">
  <a href="#ai-ready-end-to-end"><img src="https://img.shields.io/badge/AI--ready-llms.txt%20%C2%B7%20%7Bpage%7D.md%20%C2%B7%20admin%20guide-6d28d9" alt="AI-ready"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-2a7d5c" alt="MIT"></a>
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white" alt="PHP 8.4">
  <a href="https://packagist.org/packages/yanfishel/atarcms-core"><img src="https://img.shields.io/packagist/v/yanfishel/atarcms-core?label=packagist" alt="Packagist"></a>
</p>

AtarCMS runs small sites — a studio, a clinic, a club, a workshop — with one to five trusted editors: sections
and menus, entries with revisions, autosave and preview, a media library with on-demand WebP/AVIF variants,
search, forms with a message inbox, SEO with generated share images, analytics behind a consent banner, an
accessibility toolbar, and an admin in five languages (English, Russian, Hebrew with full RTL, German, French).
Everything boots from the sources on plain OPcache: no compiled cache, no build step, no node_modules.

## AI-ready, end to end

Being readable and operable by AI agents is the project's central design goal, not an add-on. The default theme
scores a clean sheet in Lighthouse, including Google's new **agentic browsing** audit:

![Lighthouse: Accessibility 100, Best Practices 100, SEO 100, Agentic Browsing 3/3](docs/images/lighthouse.png)

Every stage of a site's life has an AI surface:

**1. An agent builds the site.** The skeleton ships an agent skill (`.agents/skills/building-atarcms-sites/`,
wired up by `AGENTS.md`): a coding agent installs AtarCMS, builds the theme from a design brief, configures
languages, sections and menus, fills demo content and walks the go-live checklist.

**2. An agent runs the admin.** The admin describes itself at **`/admin/ai-guide`** — one known URL instead of
hidden per-page hints. A signed-in browser agent gets the screens it may open, every content type with its
fields and limits, and how saving, autosave, translations and media work — generated for this site and filtered
by that user's permissions. The admin itself works without JavaScript, renders semantic markup and never hides
state in scripts, so agents driving the accessibility tree operate it reliably.

**3. Agents and LLMs read the published site.** The public site is machine-readable without scraping:

- **`/llms.txt`** — the whole site as a markdown map ([llmstxt.org](https://llmstxt.org)), one request:

  ```markdown
  # Atar Demo

  > A demo site with sections, articles, news and a contact form.

  Site languages: en (default), ru, he

  Append .md to an entry's URL for a markdown version.

  The full text of the entry pages: https://example.com/llms-full.txt

  ## News (en)

  - [Spring at Atar](https://example.com/news/spring-at-atar)
  ```

- **`/llms-full.txt`** — the full text of the public entry pages in one request (`/llms.txt`'s order, each page's
  markdown twin with a `Source:` line); after `content.llms_full_limit` pages (default 100) it points to
  `/llms.txt`.

- **`{page URL}.md`** — every public entry page has a markdown twin: the same content as clean markdown
  (`text/plain`), with a `Link: rel="canonical"` header back to the HTML page and `X-Robots-Tag: noindex` so
  search engines never see duplicates. Old addresses redirect with 301, like the HTML pages:

  ```console
  $ curl https://example.com/contacts.md
  # Contacts

  - Language: en (translations: [he](…/he/contacts.md), [ru](…/ru/contacts.md))

  ## Text
  …
  ```

"Hide from search engines" hides `/llms.txt`, `/llms-full.txt` and the `.md` twins along with the sitemap, so a site under
construction leaks nothing. Details: `docs/architecture.md` ("AI readiness") and `docs/admin-ui.md` ("AI guide").

## A look inside

| | |
| --- | --- |
| ![The dashboard](docs/images/admin-dashboard.png) **The dashboard** — activity, content and translation state at a glance | ![The entry form](docs/images/admin-entry-form.png) **Entry form** — tabs per field group, hero slides with optional video |
| ![Rich text and the map](docs/images/admin-contacts.png) **Rich text** (CKEditor 5) beside structured fields — address, hours, a Google map | ![Sections](docs/images/admin-sections.png) **Sections** — the site's menu as a tree: drag to nest, every change audited |
| ![Hebrew admin](docs/images/admin-rtl.png) **Full RTL** — the same admin in Hebrew, mirrored by logical CSS | ![The demo site](docs/images/site-home.png) **The default theme** — slides with video, dropdown menus, search, consent banner |

## What is in the box

- **Content:** collections and singles defined in code, entries with revisions, autosave, preview, scheduled
  publication; sections (hierarchical menus with addresses), tags and custom taxonomies; slug history with 301s.
- **Multilingual by design:** per-language content with linked translations, hreflang alternates, localized
  sitemaps and feeds; machine translation of a form in place (Chrome's on-device Translator API).
- **Media:** folders, bulk upload, alt texts per language, image variants on demand (WebP, AVIF, art direction),
  self-hosted video, YouTube/Vimeo embeds.
- **Public site:** themes of plain PHP templates with a strict Content Security Policy (no inline anything),
  search, contact and custom forms with rate limiting and a message inbox, generated share images, JSON-LD,
  feeds, sitemap, robots.txt — and the AI surfaces above.
- **Operations:** `bin/atar` console (installer doctor, migrations, user management, one-time login links),
  audit log of every change, Google sign-in, deploy configs for nginx/Caddy/Apache, Docker setups for
  development and production.
- **Visitors:** accessibility toolbar, cookie consent (statistics and advertising separately) gating Metrica,
  GA4 and ad pixels, dark/light theme.

## Quick start

A site starts from the skeleton, its own repository
([`yanfishel/atarcms`](https://github.com/yanfishel/atarcms), exported from `skeleton/` with every
version tag), and gets this core as the Composer package `yanfishel/atarcms-core`:

```bash
composer create-project yanfishel/atarcms my-site --no-interaction
```

Then: fill `.env`, run `php bin/atar migrate`, create the first administrator with
`php bin/atar user:create you@example.com --role=admin` and sign in with a one-time
`php bin/atar user:login-link` — or hand the whole thing to a coding agent, which reads
`AGENTS.md` and does the same. Every installation path (Docker or not, local, a server, shared hosting) is in
the skeleton's `.agents/skills/building-atarcms-sites/references/install.md`, and `php bin/atar doctor` checks
the result.

**Requirements:** PHP 8.4 (gd, intl, mbstring, pdo_mysql, exif, fileinfo), MariaDB or MySQL, any web server —
or just Docker.

## Documentation

| | |
| --- | --- |
| `docs/architecture.md` | How every subsystem works, with the decisions behind it |
| `docs/theme-guide.md` | Building a public theme: templates, helpers, rules, a recipe |
| `docs/admin-ui.md` | The admin: design tokens, screens, the rules tests enforce |
| `docs/releasing.md`, `UPGRADE.md` | Versions, the release pipeline, what a site does after an update |

Versions are git tags (`v0.1.0`…); what changed is in `CHANGELOG.md`. This repository is a per-release mirror
of a private development repository (`CONTRIBUTING.md`); issues and security reports (`SECURITY.md`) live here.

## Development

Everything runs in Docker:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer check   # PHP-CS-Fixer, PHPStan at level max, PHPUnit
```

The runnable demo site lives in `skeleton/` (see `skeleton/README.md`).

## Licence

AtarCMS's own code is released under the MIT licence (`LICENSE`). The vendored third-party components keep their
own licences (see "Third-party components"); the admin's rich-text editor is the GPL build of CKEditor 5, so a
redistribution of AtarCMS together with it must honour the GPL for that component.

## Third-party components

- **CKEditor 5** 48.5.2 (the admin's rich-text editor), GPL-2.0-or-later, source:
  https://github.com/ckeditor/ckeditor5/tree/v48.5.2. Its files and licence (`ckeditor5.LICENSE`,
  `ckeditor5.COPYING.GPL`) are in `resources/admin/assets/vendor/`.
