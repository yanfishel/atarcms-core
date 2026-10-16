# Questionnaire

Ask in this order, a few questions at a time, in the owner's language. Offer the default in brackets; accept "you
decide". Record every answer in `site-brief.md` (template: `assets/site-brief.template.md`), then show the whole
brief and ask for confirmation.

## 1. Project

1. What is the site for, and who visits it?
2. The site name, and a short name for the phone home screen (default: the name, up to 30 characters).
3. The tone: formal, friendly, playful? (friendly)

## 2. Languages

4. Which languages? Which is the main one (it has no URL prefix)? (the owner's language)
   Mark right-to-left languages (he, ar, fa, ur, yi): the theme must lay out both ways.

## 3. Structure

5. The main sections of the site, and their subsections. (Typical: Services / About / ... ) A section with
   subsections opens its first subsection; a section without them lists its articles.
6. Standalone pages: About, Terms... (the Privacy policy and the Accessibility statement, required in some countries,
   e.g. Israel, are preset pages the site always has: only their texts are asked for).
7. What the home page shows: a hero, the sections, latest news, a text block, contacts. For the hero: one still
   picture with a headline, or slides (`Field::slides()`: pictures with optional texts and links)? If slides, what
   cycles: whole slides, only the pictures under one fixed headline, or only the texts over one picture (the theme
   guide's "Hero slider" has the patterns)? Video: none, some slides playing a video instead of their picture, one
   full-hero background video, or a background video under rotating captions? Videos are the owner's own short,
   silent MP4/WebM files (up to 100 MB; the picture stays as the poster), never YouTube or Vimeo.

## 4. News

8. News or a blog? (yes) With tags? (yes) An RSS feed? (yes) How many per page? (10)

## 5. Other content

9. Anything else with many items: events, products, team members, projects, vacancies, reviews? For each: the
   fields (title is built in), whether it needs a list page, how it is sorted, whether visitors filter by something.
   Team members or treatments that belong to a section are usually articles in that section, not a new collection.

## 6. Contacts

10. Phone, email, address, opening hours (per language), WhatsApp / Viber / Telegram.
11. A map? (an address, coordinates, or a Google Maps link or embed code; one map for every language)
12. A contact form? On which page? (yes, on Contacts) Messages go to the admin and to the site email when mail is set
    up.

## 7. Social and menus

13. Social network links (the supported list is in the template).
14. Main menu order (the sections plus pinned pages such as Home, News, Contacts). A footer menu (Privacy...)?

## 8. Design

15. What do you have: pictures or sketches of the pages, a website you like (inspiration only: we never copy its
    texts, pictures, logos or code), or a purchased HTML template (a folder with its licence)?
16. Mood, colours, fonts you like; dark mode? Anything that must or must not be there?

## 9. Content and launch

17. Demo content to see the design before real texts? (yes on a new site). It deletes everything that is already
    there.
18. The domain, the mail provider, who the administrators are (emails), Google sign-in (yes/no). On a yes, tell
    the owner now that it needs a free OAuth client from https://console.cloud.google.com (their Google account,
    about ten minutes) and that you will walk them through it at launch — the steps are go-live item 7 and the
    site handbook's "Sign in with Google".
19. Visitor statistics? A Yandex Metrica counter number (with Webvisor session replay or without) and/or a Google
    Analytics 4 measurement ID. Visitors then see a cookie banner and are counted only after they accept. Advertising
    pixels? A Meta Pixel ID, a Google Ads conversion ID (`AW-…`), a VK Ads pixel ID, a TikTok Pixel ID; visitors then
    allow statistics and advertising separately.

## Mapping answers to the site

| Answer | Goes to |
|---|---|
| languages | `config/locales.php` |
| sections, subsections | created in the admin (Structure, Sections) after phase 2; the model is in `config/taxonomies.php` |
| pages, news, other content | `config/collections.php` |
| main menu pinned items, contact form page | `config/content.php` (`main_menu`, `contact_form`) |
| footer menu | admin, Structure, Menus |
| names, contacts, social, map, SEO texts | `bin/atar settings:import` (after demo content) |
| design, fonts, colours | the theme (phase 3 and 4) |
| domain, mail, admins | `.env` and launch (phase 7) |
| statistics counters | Settings → Site settings → Integrations, or `settings:import` (`yandex_metrica`, `yandex_webvisor`, `google_analytics`) |
| advertising pixels | Settings → Site settings → Integrations, or `settings:import` (`meta_pixel`, `google_ads`, `vk_pixel`, `tiktok_pixel`) |
