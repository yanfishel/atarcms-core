# Launch checklist

Go through it with the owner; tick what is done, do only what they confirm. Secrets go into `.env` on the server,
never into git or the chat history if avoidable. Installing on the server is `references/install.md` (paths B, C
or D); this list is what must be true before visitors come.

1. **Environment** (`.env`): `APP_ENV=production` (the local one-click login disappears), `APP_DEBUG=false`,
   `APP_URL=https://<domain>` (no path, no trailing slash), `APP_THEME=<theme>`, `LOG_LEVEL=info`; `chmod 600 .env`.
2. **HTTPS** in front of the site (the session cookie is `Secure` in production): Caddy does it by itself, nginx and
   Apache with certbot.
3. **Database**: its own user and a generated password; `bin/atar migrate` after every update.
4. **One user runs the site's PHP**: PHP-FPM, composer and `bin/atar` as the owner of the site's files (Docker:
   `-u www-data` with `HOST_UID`/`HOST_GID`; a server: the PHP-FPM pool of `deploy/php-fpm/atarcms.conf`).
   `storage/app.key` is created on first use: keep it, back it up, never commit it.
5. **Mail**: `MAIL_DSN=smtp://user:password@smtp.example.com:587`, `MAIL_FROM=site@<domain>`; check with
   `bin/atar doctor --mail`, then `bin/atar mail:test you@example.com`. Without mail the contact form messages stay
   in the admin.
6. **Administrators**: `bin/atar user:create owner@example.com --role=admin --name="Name"`, then
   `bin/atar user:login-link owner@example.com` (a one-time link, 10 minutes). More users in the admin.
7. **Google sign-in** (optional; it never creates users — only people already in `/admin/users` can sign in).
   The owner creates an OAuth client in https://console.cloud.google.com (any Google account, free); walk them
   through: a project, then the consent screen ("Google Auth Platform": app name, support e-mail, audience
   External, publish — only name and e-mail are asked, so no verification review), then a client of type
   "Web application" with the authorized redirect URI exactly `{APP_URL}/admin/login/google/callback`.
   The owner puts the Client ID and secret into `.env` as `ATAR_GOOGLE_CLIENT_ID`,
   `ATAR_GOOGLE_CLIENT_SECRET` (never in the chat); `ATAR_GOOGLE_ALLOWED_DOMAINS` can limit sign-in to their
   Workspace domains. The full steps are the site handbook's "Sign in with Google".
8. **Content**: demo content replaced or removed (it contains invented texts and placeholder pictures); real Privacy
   policy and any legally required pages; real site settings (`bin/atar settings:import` or the admin Settings);
   the site icon and logo uploaded in Settings.
9. **Search engines**: "Hide from search engines" off in Settings when the site is ready (on while it is not);
   verification codes for Yandex/Google in Settings if wanted. Visitor statistics (if the owner wants them): the
   Yandex Metrica counter number (the "Webvisor" switch records sessions, so only on purpose) and/or the Google
   Analytics 4 measurement ID (`G-…`) in Settings → Site settings → Integrations, or `settings:import` keys
   `yandex_metrica`, `yandex_webvisor`, `google_analytics`. The counters load only after a visitor accepts the cookie
   banner (the theme's `partials/consent.php`, the theme guide's Cookie banner); signed-in users are never counted.
   Check it signed out in a private window: the banner shows, nothing goes to Yandex or Google before Accept, and the
   Privacy policy page names the services (and session replay with Webvisor). The banner's text per language is a
   field there too (empty: the theme's). Advertising pixels (only if the owner runs ads): the Meta Pixel ID, the Google
   Ads conversion ID (`AW-…`), the VK Ads pixel ID and the TikTok Pixel ID in the same tab, or `settings:import` keys
   `meta_pixel`, `google_ads`, `vk_pixel`, `tiktok_pixel`. The banner then offers "Accept all", "Decline" and "Choose"
   (statistics and advertising separately); check that allowing only statistics sends nothing to Meta, Google Ads, VK
   or TikTok, and that the Privacy policy names the pixels too. Google Tag Manager is not supported (it breaks the
   Content Security Policy); add each service by its own field. Google Ads pings `www.google.<visitor's TLD>` for
   remarketing lists: only `.com`, `.ru`, `.co.il`, `.de`, `.fr` and `.co.uk` are allowed (conversions still record
   through googleadservices.com and googleads.g.doubleclick.net); add the site's other countries to
   `security.csp.img_src` and `security.csp.connect_src` in `config/security.php`. A hand-made nginx (or proxy) config
   needs `fastcgi_buffer_size 16k` or more (the shipped template has it): with many counters and pixels the response
   headers pass 4 KB and pages answer 502.
   If the web server restricts `/admin` by IP, keep `/admin-assets/` public: the public site loads the logo files,
   `tooltip.js` and the banner's `consent.js` from it.
10. **The doctor on the server**: `bin/atar doctor --http` reports no failure: the web server serves nothing outside
    `public/` (`web.exposure`), its PHP runs as the owner of `storage/` (`web.user`) with the needed extensions and
    limits, HTTPS with HSTS. Explain every warning to the owner. Compression:
    `curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' https://<domain>/` shows `Content-Encoding: gzip` (or
    `zstd`), as does the theme's `site.css`; an image or video shows none, and `/admin/login` none either (on purpose).
11. **Backups**: the database (daily dump), `storage/uploads/`, `storage/app.key` and `.env` (install.md, Backups).
12. **Updates**: `composer update yanfishel/atarcms-core`, `bin/atar migrate`, `bin/atar doctor`, then the steps of
    `vendor/yanfishel/atarcms-core/UPGRADE.md` and `bin/atar theme:check` (install.md, Updating).
13. **Final look**: every page type in every language over HTTPS; the contact form sends; `/sitemap.xml`,
    `/robots.txt`, `/llms.txt` and `/llms-full.txt` answer, and an entry page answers at its address plus `.md` (they are 404 until
    "Hide from search engines" is switched off). Tell the owner the site is AI-ready: `/llms.txt`, `/llms-full.txt` and the `.md`
    pages for public agents, `/admin/ai-guide` for agents working in the admin.
