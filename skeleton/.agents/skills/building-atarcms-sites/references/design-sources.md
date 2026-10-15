# Design sources and the design analysis

## Sources

**Pictures or sketches** (files in the project, e.g. `design/`). If you can see images, study them; if you cannot,
ask the owner to describe each page (layout from top to bottom, colours, fonts) and write that down. Note what the
sketch leaves open and decide with the owner.

**A reference website** (a URL). Inspiration only. If you can open web pages, look at the layout, the rhythm of
spacing, the navigation pattern, the kind of typography and the colour mood. Never copy its texts, images, logos,
icons, fonts, CSS, JavaScript or markup, not even as placeholders: placeholders ship, and the site would pass itself
off as someone else's. Write your own neutral texts; use free fonts we may self-host (SIL OFL or similar) that give a
similar feel; build the CSS from your own tokens. Tell the owner this in one sentence.

**A purchased HTML template** (a folder). Read its licence first and keep it. Then follow
`references/purchased-template.md`.

## The design analysis (write it into `site-brief.md`, section "Design analysis")

1. **Tokens.** Colours with roles (background, surface, text, muted text, accent, accent on dark, lines, error) for
   light and, if wanted, dark; check text contrast 4.5:1 (large text 3:1). Type: families (self-hosted, with their
   licence), base size (at least 16 px; larger for older audiences), scale, line heights, weights. Spacing scale,
   container width, radii, shadows.
2. **Components inventory.** Header (brand, menu, languages, search, call to action), phone menu, footer, hero
   (still, or any slider/rotation → `Field::slides()` data; the theme guide's "Hero slider" has the patterns),
   cards, lists, breadcrumbs, pagination, side column, buttons, forms (contact form), map, gallery, tags, notices.
   For each: where it appears and which data feeds it (menu, settings, entries).
3. **Page map.** Each design page -> AtarCMS page type -> template:

| Design page | Page type | Template |
|---|---|---|
| home | single `home` | `collections/home/show.php` |
| list of posts | archive | `collections/news/index.php` or `index.php` |
| post | entry | `collections/news/show.php` or `show.php` |
| section page | term of `sections` | `taxonomies/sections/show.php` |
| article | entry of `articles` | `collections/articles/show.php` |
| about, privacy policy, accessibility statement | entry of `pages` (privacy and accessibility are its preset pages) | `collections/pages/show.php` |
| contact | single `contacts` | `collections/contacts/show.php` |
| search | search | `search.php` |
| 404, errors | error | `errors/404.php`, `errors/error.php` |

Mark fields the design needs that the model lacks (a home headline, an event date): go back to phase 2 and add them
to `config/collections.php` (with their labels in the catalogs), then tell the owner.

Show the analysis to the owner before writing markup.

## Building it (phase 4)

Start from `bin/atar theme:new <name> --from=default`: it already handles right to left, dark mode, the CSP, the
contact form, menus, galleries and accessibility. Change tokens first (top of `assets/css/site.css`), then the layout,
then partials, then page templates. Keep CSS logical (`margin-inline-start`, `inset-inline-end`, `text-align:
start`) and every printed value escaped (`$this->e()`). Remove what the design does not need (and its catalog keys
in every language). Theme rules and helpers: `references/theme-guide.md`.
