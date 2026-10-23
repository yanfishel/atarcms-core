# Updating a site

As the user who runs the site's PHP (Docker: `docker compose exec -u www-data php …`):

```bash
composer update yanfishel/atarcms-core
php bin/atar migrate
php bin/atar doctor
```

Then the steps of the new version below, and commit the new `composer.lock`. A site requires `^0.MINOR`, so
`composer update` takes the fixes of its minor version only; moving to the next minor (`^0.2`) is a change of the
constraint in `composer.json`, after reading its section here.

## Draft versions

AtarCMS is still in its greenfield phase: until it ends, its versions are drafts. The database migrations of a
released version may still be changed in place, so an update can need a fresh install (a new database, then the
content entered again or imported). Each version says so below when it does.

## Once greenfield ends (prepared, not yet in force)

From the version that ends the greenfield phase on: migrations shipped in a tag are never edited or deleted; every
schema change is a new migration that `php bin/atar migrate` applies; a patch version (0.1.x) never needs a step
here; every minor version lists its steps here.

## Versions

### v0.2.7

The usual three commands. No schema changes. A root page with the slug `sitemap` must be renamed before its next
save: `/sitemap.md` is now the site's markdown sitemap. Optional: `'snippet_directives' => ''` in
`config/content.php` turns off the new `X-Robots-Tag` snippet directives.

### v0.2.6

The usual three commands. No schema changes. Optional: a site with its own `robots` text in `config/content.php`
replaces the default robots.txt entirely, so add `Content-Signal: search=yes, ai-input=yes, ai-train=no` under its
`User-agent: *` line to declare the content signals. A CDN in front of the site that ignores `Vary` (such as
Cloudflare's "Cache Everything") must not cache HTML pages, or it may serve the markdown variant to browsers.

### v0.2.5

The usual three commands; nothing else to do. No schema changes.

### v0.2.4

The usual three commands. No schema changes. Optional: a server set up from `deploy/nginx` or `deploy/apache`
before this version can add `text/markdown` to its `gzip_types` / `AddOutputFilterByType DEFLATE` line so the `.md`
pages are compressed too.

### v0.2.3

The usual three commands; nothing else to do. No schema changes.

### v0.2.2

The usual three commands; nothing else to do. No schema changes. Optional: a theme of your own can print
`<?= $this->markdownAlternate() ?>` in its layout's `<head>` (next to `canonical()`) to link entry pages to their
markdown twins, as the default theme now does; replace any `<link rel="alternate" type="text/markdown">` that
points to `/llms.txt`.

### v0.2.1

The usual three commands; nothing else to do. No schema changes.

### v0.2.0

Change the constraint to `"yanfishel/atarcms-core": "^0.2"` in `composer.json`, then the usual three commands.
No schema changes and nothing else to do; sites created from v0.1.0 keep working as they are. The optional
`config/demo.php` only matters before `demo:seed` runs, so an installed site can ignore it.

### v0.1.0

The first version: install it, nothing to update.
