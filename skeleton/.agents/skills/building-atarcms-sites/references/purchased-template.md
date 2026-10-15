# A purchased HTML template as the theme

The owner keeps the look they paid for; the delivery changes because the site's Content Security Policy allows files
only from the site itself, styles only from stylesheets and scripts only from files (plus one nonce'd inline
script). Say so to the owner in one or two sentences: "The template will look the same; its fonts and scripts will be
served from your site instead of other servers, which the site's security rules require (and which is better for
privacy)."

## Steps

1. **Licence.** Find the licence (LICENSE, a README, the seller's terms). Copy it into the theme
   (`themes/<name>/TEMPLATE-LICENSE.txt` or next to the files in `assets/vendor/`). Note in the brief what it allows.
2. **Inventory.** List the template's pages and map them to page types (`references/design-sources.md`, page map).
   List every external resource: `<link href="https://...">`, `<script src="https://...">`, `@import url(https://...)`,
   fonts (Google Fonts, Font Awesome), images from other hosts.
3. **Start the theme.** `bin/atar theme:new <name>` (the scaffold) or `--from=default`; copy the template's own CSS,
   JS, images and fonts into `themes/<name>/assets/` (`css/`, `js/`, `img/`, `fonts/`), libraries into
   `assets/vendor/<library>/` with their licence files.
4. **No CDN.** Download each library at the exact version the template uses (Bootstrap, jQuery, icon fonts or SVG
   sprites, sliders) and its licence into `assets/vendor/`. Google Fonts: download the font files (woff2) of the used
   weights, write the `@font-face` rules yourself in the theme CSS, keep the font's licence (OFL). jQuery is fine as a
   vendored file. Prefer CSS and webfont builds over scripts that inject styles: Font Awesome's `all.js` adds a
   `<style>` element the CSP blocks; its `css/all.min.css` + `webfonts/` build looks the same. Remove scripts that
   need the seller's service (form backends with API tokens): the site has its own contact form. A template's hero
   slider is rebuilt, not vendored: its slides become `Field::slides()` data and the skeleton's `slider.js` pattern
   replaces the plugin (Swiper, Slick and the like write inline styles the CSP blocks); the theme guide's "Hero
   slider" shows the markup and the patterns.
5. **Inline code.** Move every inline `<script>` into `assets/js/*.js` (load it with
   `<script src="<?= $this->e($this->asset('js/site.js')) ?>" defer></script>`); `onclick=` and other handlers become
   listeners in that file. `style="..."` attributes become classes in the theme CSS; a background image set inline
   becomes a class per image, or an `<img>` with `object-fit`.
6. **Templates.** Cut the HTML into `layouts/default.php` (head with the helpers of the theme guide, header, footer,
   `<?= $content ?>`), partials and page templates. Replace sample texts with data (`$entry->title`, fields, menus,
   settings), escape everything with `$this->e()`, use `img()` for pictures, `menu('main')` for navigation,
   `contactForm()` for the contact form and `form('key')` for the forms of `config/forms.php` (the default theme's
   `partials/form-fields.php` draws any form's fields; a template's booking or callback popup becomes
   `partials/form-dialog.php`).
   Remove template features that need a server we do not have (newsletter forms posting elsewhere, PHP mailers).
7. **Direction.** If no site language is right to left, you may keep the template's physical CSS
   (`margin-left`, `float: left`): add `'direction' => 'ltr'` to `theme.php` so `theme:check` accepts it. If the site
   has (or gets) a right-to-left language, do not add it. A compiled template stylesheet (Bootstrap and the like)
   is a vendored file: keep it unchanged under `assets/vendor/<template>/` with the licence (the logical-sides check
   skips `assets/vendor/`), generate a right-to-left copy with RTLCSS (as Bootstrap does for its own) and load it on
   `dir="rtl"` pages. For the theme's own CSS, convert to logical properties
   (`margin-inline-start`, `padding-inline-end`, `inset-inline-start`, `float: inline-start`, `text-align: start`,
   `border-start-start-radius`), use the library's RTL build if it has one (Bootstrap ships `bootstrap.rtl.min.css`),
   mirror arrows with `:dir(rtl)` rules, and print `<html<?= $this->langAttrs($this->locale()) ?>>`.
8. **Check.** `bin/atar theme:check <name>` until zero errors. Typical findings: a forgotten CDN link, an inline
   style, a missing translation key, a missing `url()` file in the vendored CSS.

## When the owner insists on CDN links

Explain that a CDN link is not a theme setting but a change of the site's security policy in the core, which the
theme cannot make; the page would load without those files. Offer the self-hosted copy (same look). Do not change
the core.
