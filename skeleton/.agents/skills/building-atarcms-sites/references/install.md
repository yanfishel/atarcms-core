# Installing AtarCMS

From nothing to a working admin. Ask before you install system packages, edit server configuration, or create
databases and users, and show the owner the exact commands first. Secrets (database passwords,
mail passwords) are typed by the owner or generated on the target machine straight into a file; never print them,
never write them into the chat, `site-brief.md` or a committed file.

## 1. Choose the path

Find out: `docker compose version`, `php -v` (8.4 or newer?), `composer --version`, the system (`uname -a`,
`/etc/os-release`), whether you are root, and from the owner: their own computer or a server, the domain, and
whether a site already runs there (then never touch its database).

| Path | When | Web server | Section |
|---|---|---|---|
| A. Local with Docker | building the site on a computer | nginx in Docker, http://localhost:8080 | 3A |
| B. Server with Docker | a VPS where Docker is fine | Caddy in Docker (automatic HTTPS) | 3B |
| C. Server without Docker | a VPS, Debian 13 or Ubuntu 24.04+ | nginx (or Caddy or Apache) + PHP-FPM + MariaDB | 3C |
| D. Shared hosting with SSH | PHP 8.4 and MySQL/MariaDB from a panel | the host's Apache | 3D |
| E. Local without Docker | PHP 8.4 and MariaDB already installed | `php -S` | 3E |

Requirements everywhere: PHP 8.4+ with pdo_mysql, intl (full ICU data), mbstring, fileinfo, gd (JPEG, PNG, WebP,
FreeType; AVIF optional), exif, dom, json, ctype, iconv, openssl; MariaDB 10.11+ (MySQL 8.0.21+ should work, untested); Composer 2.
`php bin/atar doctor` checks all of it.

**One user runs the site's PHP.** One user owns the site's files and runs PHP-FPM, composer and `bin/atar`; the web
server only reads. Under Docker that user is `www-data`, moved to the host's uid by `HOST_UID`/`HOST_GID` in `.env`:
run everything with `docker compose exec -u www-data php …`. Without Docker it is the site user
(`sudo -u <site user> php bin/atar …`). Never run `bin/atar` as root: root-owned files in `storage/` give 500 errors.

## 2. Access to the repositories

The core (`yanfishel/atarcms-core`) and the site skeleton (`yanfishel/atarcms`) are public GitHub repositories:
installing needs no account and no token. Run every composer command with `--no-interaction`.

If GitHub's anonymous rate limit ever interrupts a download (a 403 naming the limit), any GitHub account's
fine-grained token with no repository permissions raises it. The owner (not you) pastes it, as the user who runs
composer, without it landing in the shell history:
`read -rs T && composer config --global --auth github-oauth.github.com "$T"; unset T`. **Docker** (paths A and B):
composer runs in the container, whose Composer home is temporary, so store such a token in the project's
`auth.json` (in `.gitignore`, never committed):
`read -rs T && docker compose exec -T -u www-data php composer config --auth github-oauth.github.com "$T"; unset T`,
then `chmod 600 auth.json`.

## 3A. Local with Docker

```bash
composer create-project yanfishel/atarcms my-site --no-interaction
cd my-site
```

No Composer on the computer: the same through the `composer:2` image, as the user the site's image will use, or
the files belong to root and the site cannot
write them: on Linux `-u "$(id -u):$(id -g)"`, on Docker Desktop (Windows, macOS) `-u 82:82`:
`docker run --rm -it -u 82:82 -v "$PWD":/app -w /app -e COMPOSER_AUTH composer:2 create-project … --ignore-platform-reqs`
(the platform is checked again in the site's own image below). If `bin/atar doctor` later reports files of root in
`storage/`: `docker compose exec php chown -R www-data: .` once. In Git Bash on Windows prefix docker commands that
name container paths with `MSYS_NO_PATHCONV=1`.

`.env` was copied from `.env.example` (local defaults). On Linux set `HOST_UID` and `HOST_GID` to `id -u` / `id -g`.
Then:

```bash
docker compose up -d --build
docker compose exec -u www-data php composer install
docker compose exec -u www-data php php bin/atar migrate
docker compose exec -u www-data php php bin/atar user:create you@example.com --role=admin --name="Your Name"
docker compose exec -u www-data php php bin/atar user:login-link you@example.com
docker compose exec -u www-data php php bin/atar doctor --http --http-via=http://nginx
```

Open the login link (10 minutes, once) at http://localhost:8080. With `APP_ENV=local` the login page also offers
one-click sign-in. Mail lands in Mailpit, http://localhost:8025.

## 3B. Server with Docker

Docker Engine and the compose plugin installed (ask first), DNS of the domain pointing at the server, ports 80 and
443 open. As the user who will own the site (not root):

```bash
composer create-project … (as in 3A, or through the composer:2 image)
cd my-site
```

Edit `.env`: `COMPOSE_FILE=compose.production.yaml`, `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://example.com`, `LOG_LEVEL=info`, `HOST_UID`/`HOST_GID` from `id -u`/`id -g`, `DB_HOST=db`,
`DB_DATABASE`, `DB_USERNAME` and a generated password:

```bash
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 24)/" .env && chmod 600 .env
docker compose up -d --build
docker compose exec -u www-data php composer install --no-dev --optimize-autoloader
docker compose exec -u www-data php php bin/atar migrate
docker compose exec -u www-data php php bin/atar user:create owner@example.com --role=admin --name="Owner"
docker compose exec -u www-data php php bin/atar user:login-link owner@example.com
docker compose exec -u www-data php php bin/atar doctor --http
```

Caddy gets the certificate on the first request (the domain must already point at the server). The database is
created from the `DB_*` values on the first start; changing them later does not change the database.

## 3C. Server without Docker (Debian 13, Ubuntu 24.04+)

`example` stands for the site's name (its user, directory and database). Each block says who runs it.

As root, the packages (Debian 13 has PHP 8.4; Ubuntu 24.04 first `add-apt-repository ppa:ondrej/php`):

```bash
apt update
apt install nginx mariadb-server composer git unzip curl openssl sudo gettext-base \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-intl php8.4-gd php8.4-mbstring php8.4-xml php8.4-curl
```

As root, the site user and its directory:

```bash
adduser --disabled-password --gecos "" example
mkdir -p /var/www/example && chown example: /var/www/example
```

As root, the database (the password goes into a root-only file, then into `.env` below; it is never shown):

```bash
openssl rand -hex 24 > /root/example-db-password && chmod 600 /root/example-db-password
mariadb -e "CREATE DATABASE example CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'example'@'localhost' IDENTIFIED BY '$(cat /root/example-db-password)';
  GRANT ALL PRIVILEGES ON example.* TO 'example'@'localhost';"
```

As the site user (composer runs as that user), the code and `.env`:

```bash
sudo -u example -H composer create-project yanfishel/atarcms /var/www/example --no-dev --no-interaction
cd /var/www/example
sudo -u example sed -i 's/^APP_ENV=.*/APP_ENV=production/; s#^APP_URL=.*#APP_URL=https://example.com#; s/^LOG_LEVEL=.*/LOG_LEVEL=info/; s/^DB_HOST=.*/DB_HOST=localhost/; s/^DB_DATABASE=.*/DB_DATABASE=example/; s/^DB_USERNAME=.*/DB_USERNAME=example/' .env
sudo -u example chmod 600 .env
```

As root, the password into `.env` (`DB_HOST=localhost` uses MariaDB's socket, matching the `'example'@'localhost'` user):

```bash
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=$(cat /root/example-db-password)/" /var/www/example/.env && rm /root/example-db-password
```

As root, the PHP-FPM pool running as the site user:

```bash
sed 's/example/example/g' /var/www/example/deploy/php-fpm/atarcms.conf > /etc/php/8.4/fpm/pool.d/example.conf
php-fpm8.4 -t && systemctl reload php8.4-fpm
```

(The pool file is written for a site user called `example`; the `sed` puts the real name in its place: for a site
`shop`, `sed 's/example/shop/g' … > /etc/php/8.4/fpm/pool.d/shop.conf`.)

As root, nginx: render the template, enable it, disable Debian's default site (it answers every request it is the
default for, IPv6 included, and hides this one), get the certificate:

```bash
cd /var/www/example
SERVER_NAME=example.com SITE_ROOT=/var/www/example ATAR_THEME=default PHP_FASTCGI=unix:/run/php/atarcms-example.sock \
  envsubst '$SERVER_NAME $SITE_ROOT $ATAR_THEME $PHP_FASTCGI' < deploy/nginx/atarcms.conf.template > /etc/nginx/sites-available/example
ln -s /etc/nginx/sites-available/example /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
apt install certbot python3-certbot-nginx && certbot --nginx -d example.com
```

A hand-made nginx (or proxy) config instead of the template needs `fastcgi_buffer_size 16k` or more: with many
analytics counters and advertising pixels the response headers pass nginx's default 4 KB and pages answer 502.

Instead of nginx: Caddy (`deploy/caddy/Caddyfile`, set its four variables in Caddy's environment, automatic HTTPS;
Debian's Caddy runs as `caddy`, so let it use the PHP-FPM socket: `usermod -aG www-data caddy`, restart Caddy)
or Apache (`deploy/apache/atarcms.conf`, its modules `a2enmod rewrite headers proxy_fcgi alias deflate`,
`a2dissite 000-default`, `certbot --apache`). Every shipped config compresses text responses (HTML, CSS, JS, SVG,
feeds) but not the admin's pages (BREACH: they carry the session's CSRF token) nor images and video; keep it so when
adapting a config. After switching `APP_THEME`
later, render the nginx config again and reload nginx.

As the site user in `/var/www/example` (`sudo -u example php bin/atar …`): `php bin/atar migrate`,
`php bin/atar user:create … --role=admin`, `php bin/atar user:login-link …`, `php bin/atar doctor --http`.
The login link signs in once within 10 minutes: hand it to the owner directly, never into a shared chat or log.
The plain `doctor` warns about the command line's upload limits (Debian's CLI keeps 2M): expected, `--http` shows the
limits of the PHP-FPM pool, which handles uploads.

Upload limits: videos (hero slides) may be up to `max_video_size` in `config/media.php` (100 MB; other files
`max_size`, 10 MB). Every layer must let such a request through: PHP's `upload_max_filesize` (100M) and
`post_max_size` (101M; the pool file and the Docker `php.ini` set both) and the web server's body limit (nginx
`client_max_body_size 101m`, Caddy `request_body` `max_size 101MB`, Apache `LimitRequestBody`; the shipped configs
have them). A smaller limit somewhere refuses large videos only: `doctor --http` warns about PHP's; lower
`max_video_size` instead on a host whose limits you cannot raise (shared hosting often stops at 32–64M).

## 3D. Shared hosting with SSH

The panel gives PHP 8.4 (select it for the domain and for the SSH command line; they can differ), a MySQL/MariaDB
database and user, and SSH. Composer: the host's, or `php composer.phar` downloaded into your home directory.

1. `composer create-project … ~/example --no-dev --no-interaction` (section 3A's command).
2. In the panel, point the domain's document root at `~/example/public`. Most panels allow it (or use a subdomain).
   Only if the panel cannot: `cp deploy/apache/root.htaccess .htaccess` in the site root. This protects `.env`,
   `storage/` and `vendor/` only when the host reads `.htaccess` files; step 5 must prove it.
3. `.env` with the panel's database values, `APP_ENV=production`, `APP_URL=https://<domain>`; HTTPS from the panel
   (Let's Encrypt). `chmod 600 .env`.
4. `php bin/atar migrate`, `user:create`, `user:login-link`.
5. `php bin/atar doctor --http`: `web.exposure` must be ok (else the site's secrets are public: take the site down
   and fix the document root), and the `web.*` lines show the PHP the web server really uses.

Theme and admin assets and uploads are served through PHP there: no extra rules needed.

## 3E. Local without Docker

PHP 8.4 with the extensions and MariaDB on the computer: create a database and user as in 3C, `create-project`,
`.env` with `APP_ENV=local`, `APP_URL=http://localhost:8080`, `DB_HOST=127.0.0.1`, then `php bin/atar migrate`,
`user:create`, and `php -S 127.0.0.1:8080 -t public public/index.php` (the PHP server serves the assets and uploads
through the site). No mail without `MAIL_DSN`.

## 4. Check, commit, continue

`bin/atar doctor` until it reports no failure; explain every warning to the owner (`--json` for details; `--mail`
connects to the mail server; `--http` asks the web server). Then `git init` if there is no repository (the
skeleton's `.gitignore` keeps `.env`, `auth.json`, `vendor/` and `storage/` out; `composer.lock` is committed; a new
user needs `git config user.name`/`user.email` in the repository first),
commit "Install", and continue with the brief.

## Mail

`MAIL_DSN=smtp://user:password@smtp.example.com:587` (URL-encode special characters of the password) and
`MAIL_FROM=site@example.com` in `.env`; `bin/atar doctor --mail` signs in without sending, `bin/atar mail:test
you@example.com` sends one. Without it the contact form's messages stay in the admin.

## Backups

The database, `storage/uploads/`, `storage/app.key` and `.env` (keep the copies private: they hold the secrets).
Daily, as root (Debian's root signs in to MariaDB through its socket, no password), in `/etc/cron.d/example-backup`;
bash's `pipefail` makes a failed dump an error mail instead of an empty file:

```
SHELL=/bin/bash
30 3 * * * root set -o pipefail; mariadb-dump --single-transaction example | gzip > /var/backups/example-$(date +\%a).sql.gz
```

(Docker: `docker compose exec -T db sh -c 'mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'`.)
Copy the dumps and `storage/uploads/` off the server. Nothing else needs cron.

## Updating

As the site user (Docker: `docker compose exec -u www-data php …`):

```bash
composer update yanfishel/atarcms-core
php bin/atar migrate
php bin/atar doctor
```

Then the steps `vendor/yanfishel/atarcms-core/UPGRADE.md` lists for the new version (for example
`php bin/atar share-images:rebuild`). Commit the new `composer.lock`. While AtarCMS is a draft (0.x before the end of
its greenfield phase), an update may need a fresh install: read UPGRADE.md first.
