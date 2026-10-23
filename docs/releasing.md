# Releasing AtarCMS

A release is a git tag `vMAJOR.MINOR.PATCH` on `main` of the development repository
(`yanfishel/atarcms-private`). Pushing it runs `.github/workflows/release.yml`: the CI checks, then two exports —
`.github/scripts/export-core.sh` publishes the tag's whole tree minus the private working files (`CLAUDE.md`,
`docs/superpowers/`, `.github/`) as one commit on `main` of the public mirror `yanfishel/atarcms-core`, and
`.github/scripts/export-skeleton.sh` exports `skeleton/` of the tag (`git archive`, honouring
`skeleton/.gitattributes` `export-ignore`, so `compose.override.yaml` and `composer.dev.json` stay behind) as one
commit on `main` of `yanfishel/atarcms`. Each export is tagged with the same version; sites then get both
packages through Composer (`README.md`, "Quick start").

## Repositories

- `yanfishel/atarcms-private` — this development repository: the full history, plans and working notes. Private.
- `yanfishel/atarcms-core` — the public mirror: one commit per release, the tag's tree without the private working
  files. Its history starts at the first public version. Issues and vulnerability reports live here
  (`SECURITY.md`, `CONTRIBUTING.md`); it runs no CI of its own — this repository's CI already gated the tag.
- `yanfishel/atarcms` — the site skeleton, exported from `skeleton/` per release. Public.

## What CI runs

`.github/workflows/ci.yml` runs on every pull request, on pushes to `main`, by hand (`gh workflow run CI --ref
<branch>`) and inside the Release workflow. Each job starts MariaDB 11 through `.github/scripts/start-mariadb.sh`
(it falls back across registries when one rate-limits the pull) and uses PHP 8.4 with the site's extensions.

- `checks`: `composer check` — PHP-CS-Fixer, PHPStan at level max over `src`, `tests` and `skeleton/src` (templates excluded), PHPUnit in parallel through ParaTest (one database per worker, `atar_{N}_test`).
- `skeleton`: `.github/scripts/skeleton-install.sh` installs `skeleton/` the way a developer of the core does
  (`COMPOSER=composer.dev.json`, the core of the commit through the path repository) with a production `.env`
  (`APP_ENV=production`, an https `APP_URL`), runs `bin/atar migrate` and `demo:seed --force`, then requires
  `theme:check` without errors and `doctor` without failures (warnings pass). PHP's built-in server then serves
  `skeleton/public` and `.github/scripts/skeleton-smoke.sh` requests the home page (with its JSON-LD block), `/ru`,
  `/he`, a section, an article, `/news` and a news item (their addresses read from the site's own links), a search,
  `/admin/login`, `/sitemap.xml`, `/robots.txt` and an unknown address (404). PHP errors are caught in the logs, not
  in the pages: the server runs with `display_errors=0`, `log_errors=1` and `error_reporting=-1`, and the smoke
  fails on any `PHP Fatal error/Parse error/Warning/Notice/Deprecated` line in the server's log and on any entry at
  the level notice or above that the requests added to `skeleton/storage/logs/app-*.log` (where the site's error
  handler logs exceptions and deprecations), printing the offending lines.

## Versions

- Pre-1.0: `0.MINOR.PATCH`. A patch fixes only (no step for sites). A minor may add features, migrations and breaking
  changes, each with its site-side steps in `UPGRADE.md`. Sites require `^0.MINOR` (Composer's caret on 0.x keeps the
  minor), so they move to a new minor deliberately.
- Greenfield: until the user ends it, versions are drafts. Migrations may still be edited in place and an update
  may need a fresh install; `UPGRADE.md` says so per version. The migration policy prepared in `UPGRADE.md` ("Once
  greenfield ends") applies only from then on.

## Steps

1. `main` is merged and green: `docker compose run --rm php composer check`.
2. `CHANGELOG.md`: a section for the version. `UPGRADE.md`: its steps (or "nothing to do").
3. `skeleton/composer.json` requires `"yanfishel/atarcms-core": "^MAJOR.MINOR"` of the release (the export refuses a tag
   whose skeleton requires another line). Commit.
4. Tag and push:

   ```bash
   git tag -a v0.1.0 -m "AtarCMS v0.1.0"
   git push origin main v0.1.0
   ```

5. Watch the Release workflow (`gh run watch`); all three jobs must pass.
6. Verify from a clean machine or container:
   `composer create-project yanfishel/atarcms check-site --no-interaction`,
   then `php bin/atar doctor` in it (after `.env` and a database).

A failed export can be re-run from the Actions page — each export job on its own. If a target repository already
got the tag, delete that tag **in that repository** first (`git push --delete origin vX.Y.Z` in a clone of
`atarcms-core` and/or `atarcms`).

## The deploy keys (set up once)

The workflow pushes with one SSH deploy key per target repository, each able to write that repository and nothing
else:

```bash
ssh-keygen -t ed25519 -N "" -C "atarcms core export" -f atarcms-core-deploy
gh repo deploy-key add atarcms-core-deploy.pub --repo yanfishel/atarcms-core --allow-write --title "atarcms core export"
gh secret set CORE_REPO_DEPLOY_KEY --repo yanfishel/atarcms-private < atarcms-core-deploy
rm atarcms-core-deploy atarcms-core-deploy.pub

ssh-keygen -t ed25519 -N "" -C "atarcms skeleton export" -f atarcms-deploy
gh repo deploy-key add atarcms-deploy.pub --repo yanfishel/atarcms --allow-write --title "atarcms skeleton export"
gh secret set SITE_REPO_DEPLOY_KEY --repo yanfishel/atarcms-private < atarcms-deploy
rm atarcms-deploy atarcms-deploy.pub
```

## Packagist

Both packages are on https://packagist.org (`yanfishel/atarcms-core`, `yanfishel/atarcms`, submitted
2026-10-15) and update per tag through Packagist's GitHub integration — check both pages after a release. Sites
resolve everything from Packagist: `composer create-project yanfishel/atarcms my-site` with no `--repository`
and no tokens (the skeleton's shipped `composer.json` carries no `repositories` entry since v0.1.1).
