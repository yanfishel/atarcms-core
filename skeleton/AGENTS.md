# Agent instructions for this AtarCMS site

This project is a website built on AtarCMS, a small PHP CMS. The core is the Composer package in
`vendor/yanfishel/atarcms-core/` and is read-only for you: the site is `config/`, `themes/`, `src/` (the site's own
module) and `.env`.

To install it, build the site, re-theme it, change its languages, sections, collections or menus, fill it with demo
content or prepare it for launch, use the skill in `.agents/skills/building-atarcms-sites/` (start with its
`SKILL.md`; installing is `references/install.md`).

Working with the running site: the admin describes itself for AI agents at `/admin/ai-guide` (the "AI guide" link in
the admin's page footer) — read it before editing content through the browser. The public site's map is
`/llms.txt` (its full text: `/llms-full.txt`, a plain markdown sitemap: `/sitemap.md`), and every public entry, archive and term page answers at its address
plus `.md` as clean markdown.

Running commands: one user runs the site's PHP, never root. With Docker (`compose.yaml` and a running `php`
service) every command as `www-data`: `docker compose exec -T -u www-data php php bin/atar <command>` (composer
too; on Linux `HOST_UID`/`HOST_GID` in `.env` give `www-data` your uid). Without Docker `php bin/atar <command>` with
PHP 8.4, as the owner of `storage/`. `bin/atar list` shows every command, `bin/atar help <command>` one command's
usage; `bin/atar doctor` checks the installation.

Rules:
- Never edit `vendor/`; fix things in the theme or the site's module.
- Never commit `.env`, `auth.json`, `storage/app.key` or anything under `storage/` but its `.gitkeep` files; no
  passwords, tokens, login links or API keys in committed files or in the chat.
- Ask before anything that deletes content (`bin/atar demo:seed` deletes the whole site's content), and before
  installing system packages, changing server configuration or creating databases.
- Themes keep the Content Security Policy: no inline styles, no inline scripts without the nonce, no files from
  other hosts. `bin/atar theme:check` must report no errors before a theme goes live.
