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

### v0.2.0

Change the constraint to `"yanfishel/atarcms-core": "^0.2"` in `composer.json`, then the usual three commands.
No schema changes and nothing else to do; sites created from v0.1.0 keep working as they are. The optional
`config/demo.php` only matters before `demo:seed` runs, so an installed site can ignore it.

### v0.1.0

The first version: install it, nothing to update.
