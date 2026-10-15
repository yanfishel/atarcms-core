# Verification

## `bin/atar doctor`

The installation, not the theme: PHP and its extensions, ICU data for every site language, the limits the media
settings need, `.env` and the boot, `storage/` and who owns it, the database and its migrations, an administrator,
mail. Exit code 0 without failures (warnings allowed; `--strict` fails on them too), 1 with failures; `--json` gives
`{"ok", "checks": [{"id", "status", "message", "fix"}]}`. `--http` also fetches the site: nothing outside `public/`
may be served, and the web server's PHP (which can differ from the command line's) is checked through a one-time
probe. Under Docker locally: `bin/atar doctor --http --http-via=http://nginx`. Run it after installing, after
changing `.env` or the server, and before launch; it never prints passwords.

## `bin/atar theme:check <name>`

Exit code 0 without errors, 1 with errors, 2 when the theme directory is missing. `--json` gives
`{"theme", "ok", "errors", "warnings", "info", "pages"}`; each finding has `check`, `file`, `line`, `message`.
`--no-render` skips the page rendering (no database needed; use it while building).

| Check | Typical finding | Fix |
|---|---|---|
| `config` | theme.php fails validation; `direction: ltr` with a right-to-left language | fix the key; make the CSS logical |
| `templates` | a page type without a template | add `show.php` / `index.php` / `term.php` or the collection's own |
| `sources` | `style=`, inline script, CDN link, unknown menu/image size/route/asset/translation, unescaped `<?= $x->y ?>` | class in CSS, script file, vendor the file, fix the name, `$this->e()` |
| `catalogs` | a language without `lang/<code>.php`, different keys, invalid ICU | add the file / keys in every language |
| `css` | `margin-left`, `float: left`, a missing `url()` file, a font from another host | logical property, copy the file |
| `scripts` | an import or `fetch()` to another host | vendor the library |
| `vendor` | a library folder without its licence | copy the licence |
| `render` | `500: ...` with the exception and the template line; a missing page not 404; warnings for `<h1>` count, `<img>` without `alt`, `<html lang>` | fix the template; the message names file and line |

Zero errors is required before `APP_THEME` changes. Fix warnings, or tell the owner why one stays.

## Visual pass (if you have a browser)

For every language: home, a section, an article, the news list and a news item, a page, contacts (send the form once:
the message appears in the admin, Messages), search, a missing page. Each at desktop width and at 375 px; in dark
mode if the theme has one; in a right-to-left language check that the layout mirrors. Without a browser, fetch the
pages (`curl -s -o /dev/null -w "%{http_code}"`) and say that the owner should look.

## After switching

- `.env` `APP_THEME=<name>`; under Docker `docker compose up -d` (nginx reads it at start for `/theme-assets/`).
- `bin/atar media:variants:clear` if image sizes changed; `bin/atar share-images:rebuild` if the share layout, the
  site name or the icon changed.
- Fetch the home page and a stylesheet URL: both 200.

## What to show the owner

The list of pages checked, the `theme:check` summary, anything left open, and how to undo (`APP_THEME=default`).
