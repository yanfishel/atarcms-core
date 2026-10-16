---
name: building-atarcms-sites
description: Use when installing AtarCMS on a computer or server, or building, re-theming or launching an AtarCMS site - a new site from scratch, turning sketches, a reference website or a purchased HTML template into a theme, changing the site's languages, sections, collections or menus, filling it with demo content, or preparing it for production.
---

# Building an AtarCMS site

## Overview

You turn the owner's wishes into a working AtarCMS site: content model, theme, demo content, checks, launch list.
`site-brief.md` (project root) drives everything; the owner confirms it. Work one phase at a time; after each phase
show the owner what you did, wait for their answer, tick the phase in the brief's Status (with the commit hash of
the previous phase), then commit. The core (`vendor/yanfishel/atarcms-core/`) is read-only.

Talk to the owner in their language. Files you write for the site (code, comments, config) stay in English unless
they are content or the brief.

## Hard rules

1. Never edit `vendor/`. A core problem is worked around in the theme or `src/`, and reported to the owner.
2. Work in git: one commit per phase (`git init` first if there is no repository, after checking `.gitignore`).
   Never commit `.env`, `auth.json`, `storage/app.key` or anything under `storage/` but its `.gitkeep` files, and
   never write passwords, tokens, login links or API keys into `site-brief.md` (it is committed): they go into
   `.env` on the server.
3. Anything that deletes content runs only after the owner typed an explicit yes to that exact action in this
   conversation. "Fill it with demo content" is not a yes to deleting what is there. See phase 5.
4. Demo content comes from `bin/atar demo:seed`, never from your own seeder, SQL or scripts.
5. Keep the working theme active until the new one passes `bin/atar theme:check <name>` with zero errors; only then
   set `APP_THEME`.
6. A reference website is inspiration only: never copy its texts, images, logos, icons, fonts or code, not even
   "for now", even when the owner asks. Say: "I'll take the layout and mood, not their texts or logo: those belong to
   them, and placeholders tend to go live." Write neutral placeholder texts and use the site's own name.
7. A purchased template keeps its licence file. Its fonts, CSS and scripts are copied into the theme's `assets/`
   (no CDN links: the Content Security Policy blocks other hosts); inline scripts move to files, `style=` attributes
   become classes. Explain this to the owner; it does not change the look.
8. A site with a right-to-left language (he, ar, fa...) needs a theme laid out with logical CSS; `'direction' =>
   'ltr'` in `theme.php` is only for sites without one.
9. One user runs the site's PHP: never run `bin/atar` or composer as root (the container's default). Under Docker
   always `-u www-data` (the image gives `www-data` the host's uid through `HOST_UID`/`HOST_GID` in `.env`); without
   Docker as the site user. Root-owned files (`storage/app.key`, image caches, a new theme) break the site.
10. Installing and launching: ask before installing system packages, editing server configuration (nginx, Apache,
   PHP, DNS) or creating databases and users. Never drop, empty or reuse a database that holds another site's
   tables. Secrets (database and mail passwords) are typed by the owner or generated on the target
   straight into `.env`; never print them or write them into the chat or a committed file.

## Running commands

Find out once, at the start:
- `compose.yaml` with a running `php` service (`docker compose ps`): every command as `www-data`,
  `docker compose exec -T -u www-data php php bin/atar <command>` (`-T` keeps redirected output clean), composer too
  (`docker compose exec -T -u www-data php composer …`). If it fails on `storage/` or on project files, the image was
  built for another uid: set `HOST_UID`/`HOST_GID` in `.env` to `id -u`/`id -g` (Linux), `docker compose up -d
  --build`, and fix what root left once: `docker compose exec php chown -R www-data: storage themes`.
- Otherwise `php -v` 8.4 or newer: `php bin/atar <command>` as the site user (the owner of `storage/`; on a server
  `sudo -u <site user> php bin/atar <command>`).
- Neither, or no site here yet: phase 0 installs it (`references/install.md`).

| Command | Use |
|---|---|
| `bin/atar list` | every command (also proves the config boots) |
| `bin/atar doctor` | the installation: PHP, config, storage, database, mail (`--mail`), what the web server serves (`--http`); `--json` |
| `bin/atar migrate:status`, `bin/atar migrate` | database state; apply migrations |
| `bin/atar collections:sync` | after adding, changing or removing an `->indexed()` field, then `migrate` |
| `bin/atar search:reindex` | rebuild the search index (when `UPGRADE.md` asks, or search results look stale) |
| `bin/atar media:references:prune` | release media held by removed collections or fields |
| `bin/atar media:cleanup --dry-run` | leftover media files nothing refers to (then without `--dry-run` to delete them) |
| `bin/atar theme:new <name> --from=default` | start a theme (without `--from`: the core's smaller scaffold) |
| `bin/atar theme:check <name> --json` | the theme against the site; `--no-render` skips rendering pages |
| `bin/atar demo:seed --dry-run` | what demo content would delete (changes nothing) |
| `bin/atar demo:seed --force` | delete all content, fill demo content (rule 3) |
| `bin/atar settings:import site-settings.json` | site settings from JSON; `--example` prints the shape, `--dry-run` validates |
| `bin/atar media:variants:clear`, `bin/atar share-images:rebuild` | after changing image sizes / share image layout or brand |
| `bin/atar user:create`, `bin/atar user:login-link`, `bin/atar mail:test` | launch |

## Resuming

If `site-brief.md` exists, read its Status and continue at the first unchecked phase. Never redo a checked phase
unless the owner asks.

## Phases

**0. Install and preflight.** If this folder is an AtarCMS site whose `bin/atar doctor` reports no failure, go on to
the preflight. Otherwise install (rule 10): read `references/install.md`, find out what the machine has (Docker,
PHP 8.4, Composer, local or server), agree on a path with the owner, get
the code with `composer create-project`, fill `.env`, create the database, run `bin/atar migrate`, create the
owner's administrator (`bin/atar user:create … --role=admin`, `bin/atar user:login-link`), and run `bin/atar doctor`
(on a server also `bin/atar doctor --http`) until it reports no failure; explain the warnings. `git init` if needed
and commit "Install" (the brief does not exist yet: tick phase 0 in its Status in phase 1). Preflight:
`git status`; how commands run; `bin/atar list`; `bin/atar migrate:status`;
`bin/atar demo:seed --dry-run` (how much content exists); `config/locales.php`, `APP_THEME`. Tell the owner what you
found.

**1. Brief.** Read `references/questionnaire.md`. If the owner already handed you a brief or a list of answers
(the README's "What to have ready"), start from it and ask only what is missing. Ask the questions in small
groups and offer defaults. Write `site-brief.md` from `assets/site-brief.template.md`. The owner confirms it.
Commit "Site brief".

**2. Content model.** Read `references/content-model.md`. Change `config/` (locales, collections, taxonomies,
content). Check that it boots (`bin/atar list`), run `collections:sync` + `migrate` when needed. Removing a
collection the active theme uses breaks its pages until phase 6: fine on a new site (say so), on a public site
ask first. Write `config/demo.php` with the brief's sections, pages and tags (see `references/content-model.md`):
demo:seed builds that structure instead of the generic one. Show the model (collections, fields, addresses, menus,
the demo structure). Commit "Content model".

**3. Design analysis.** Read `references/design-sources.md` (and `references/purchased-template.md` for a template).
Write the analysis into the brief: tokens, components, page map. Show it before writing any markup. Commit
"Design analysis".

**4. Theme.** `bin/atar theme:new <name> --from=default` (the default theme already handles languages, right to left,
dark mode, CSP and accessibility; for a purchased template the smaller scaffold, without `--from`, is often the
better base). Build in this order, checking with `bin/atar theme:check <name> --no-render`
and committing whenever a step works: tokens and layout, partials, page templates, catalogs (`lang/<code>.php` for
every site language, same keys; remove catalogs of languages the site dropped). The floating admin bar on public
pages draws its icons from the theme's own sprite, not the admin's: add every collection's Lucide icon (the
`icon()` name of `config/collections.php`) to the theme's icon sprite (`icons.svg` under the theme's assets), or
signed-in editors see a bare "+" on its add buttons. End with a full
`bin/atar theme:check <name>` (rendering the current content). Rules: `references/theme-guide.md` (sections 1 to 7
and 9).

**5. Demo content.** Only if the brief asks for it. demo:seed takes the preset pages' titles and the menu
locations from the active theme: on a new site switch `APP_THEME` in `.env` to the new theme first (it passed `theme:check` in phase 4;
under Docker then `docker compose up -d`). Run `bin/atar demo:seed --dry-run`. Nothing to delete: run
`bin/atar demo:seed --force`. Anything to delete: show the owner the numbers, say it is all deleted, and run it only
after their explicit yes (rule 3); never on a production site with real content. Then, in this order:
1. Settings: `bin/atar settings:import --example` to a file under `storage/tmp/`, fill every key from the brief and
   set `""` for every key the brief does not have (demo:seed wrote demo values for all of them), `--dry-run`, import,
   then `bin/atar share-images:rebuild` (the cards carry the site name).
2. Structure: with `config/demo.php` the sections, pages and tags are the brief's already. Read the
   `skipped:` lines demo:seed printed: an address the site already uses (a single, a preset page) is skipped, not
   made; rename it in `config/demo.php` if the brief needs both. Only a site seeded without the file has the
   generic demo sections (Services, Projects, Company) and pages: the brief's are then made in the admin
   (Structure, Sections; Content; Structure, Menus), and deleting or replacing the generic ones is a deletion: ask
   (rule 3). The footer menu stays generic either way.
3. Fields kept optional for the demo (see `references/content-model.md`): add their `->required()` now.
4. `bin/atar theme:check <name>` (with rendering). Commit "Demo content" (config and brief only).

**6. Verification.** Read `references/verification.md`. Zero errors, every warning fixed or explained. Switch
`APP_THEME` in `.env` if not done yet (Docker: `docker compose up -d` so nginx serves the new assets). If you have a browser, look at
every page type in every language, at 375 px and in dark mode. Commit "Verification" (`.env` is not committed:
write the theme switch into the brief's Decisions).

**7. Launch.** Go through `references/go-live.md` with the owner as a checklist. Do only what they confirm.

## Red flags: stop and correct

| Thought | Correction |
|---|---|
| "The owner is in a hurry, skip the brief" | The brief is short; without it you build the wrong site. |
| "It is only demo content, I will reseed" | `demo:seed` deletes everything first. Dry run, then ask. |
| "I will write a quick seeder with their structure" | Rule 4. Their structure goes into `config/demo.php`; demo:seed builds it. |
| "Just this Google Fonts / CDN link" | Blocked by the CSP. Copy the files into `assets/`. |
| "Copy their hero text as a placeholder" | Rule 6. Placeholders ship. Write your own. |
| "The template is LTR, Hebrew will be fine" | Rule 8. `theme:check` refuses it. Make the CSS logical. |
| "A one-line fix in vendor/" | Rule 1. The next `composer install` erases it. |
| "Switch the theme now, fix it later" | Rule 5. Visitors see the broken theme. |
| "Commit at the end" | One commit per phase; the owner can go back to any step. |
| "Root inside the container is fine" / "sudo is fine for bin/atar" | Rule 9. Root-owned files give 500 errors. |
| "I'll put the token into composer.json / the brief" | Rule 10. The owner stores it with `composer config --global --auth`. |
| "The panel can't point at public/, I'll just upload everything" | `deploy/apache/root.htaccess`, then `bin/atar doctor --http` must show `web.exposure` ok. |
| "apt install is harmless, no need to ask" | Rule 10. Show the commands, wait for the yes. |

## Common mistakes

- Removing a language from `config/locales.php` but leaving its catalog: harmless; adding one without a catalog
  fails `theme:check`.
- Printing `$entry->title` without `$this->e()`; reading a rich-text field as a string (`->html` or `->text()`).
- A section with subsections has no page of its own: it redirects to its first subsection.
- After changing `theme.php` image sizes: `bin/atar media:variants:clear`.
