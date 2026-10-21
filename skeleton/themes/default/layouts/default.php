<?php
/**
 * The page: the header (brand, languages, search and the main menu; a <details> menu on phones), the content with its
 * side column, the footer. Pages pass:
 * - title / fullTitle / description / robots / feed: the <head> (see the skeleton README);
 * - aside: the side column's HTML (partials/aside.php), or nothing for a single column;
 * - wide: true lets the page draw its own full-width bands (the home page, contacts);
 * - preloadImage: the URL of the page's largest picture when the browser would find it late (the home page's first
 *   slide's video poster), preloaded at high priority.
 * The menus and the site settings read the database: a 5xx error page (perhaps caused by the database) renders without
 * them ($status), and without the accessibility toolbar (its switch is a setting), though the visitor's stored
 * accessibility choices still apply.
 *
 * @var AtarCms\View\Template $this
 * @var string $content
 */
$withSite = ($status ?? 200) < 500;
$settings = $withSite ? $this->siteSettings() : null;
// The accessibility toolbar (Site settings → General): off, the stored choices no longer apply either (nothing could
// undo them).
$toolbar = $settings !== null && $settings->accessibilityToolbar;
$a11yChoices = $settings === null || $settings->accessibilityToolbar;
// The chat button (the contact page's switch) with at least one messenger, in the bottom end corner, above the toolbar's
// button when that sits there.
$chat = $settings !== null && $settings->chatButton && $settings->messengers !== [];
$chatRaised = $toolbar && $settings->accessibilityToolbarPosition === 'bottom-end';
// The preset pages (config/collections.php): the footer links both, the toolbar the accessibility statement, each while
// it is published in this language.
$statement = $withSite ? $this->presetUrl('accessibility') : null;
$legal = [];
foreach ($withSite ? ['privacy', 'accessibility'] : [] as $preset) {
    $presetPage = $this->presetEntry($preset);
    $presetUrl = $this->presetUrl($preset);
    if ($presetPage !== null && $presetUrl !== null) {
        $legal[] = [$presetPage->title, $presetUrl];
    }
}
// A page without its own description uses the site's (Settings → Site settings).
$description = ($description ?? null) ?? $settings?->metaDescription;
// The <title>: a page's whole title ($fullTitle, the home page's), else its title by the site's title pattern ("{title} — {site}").
$documentTitle = $withSite ? (($fullTitle ?? null) ?? $this->pageTitle($title ?? null)) : (($title ?? null) ?? $this->t('site.name'));
$siteName = $withSite ? $this->siteName() : $this->t('site.name');
$mainMenu = $withSite ? $this->menu('main') : [];
$alternates = $withSite ? $this->alternates() : [];
// The cookie banner of the analytics counters (Site settings → Integrations): null without a counter and for signed-in
// users, who are not counted; consentHead() prints the core script that shows it and loads the counters after consent.
$consent = $withSite ? $this->consent() : null;
$aside = $aside ?? null;
$wide = ($wide ?? false) === true;
// The booking form (config/forms.php): a "Book" link in the header opens its dialog (partials/form-dialog.php), or
// its page without JavaScript.
$booking = $withSite ? $this->form('booking') : null;
?>
<!doctype html>
<html<?= $this->langAttrs($this->locale()) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php // Before the first paint: the stored light or dark choice (theme.js), .js for the parts that need scripts and the stored accessibility classes (a11y.js). ?>
    <script nonce="<?= $this->e($this->nonce()) ?>">try{var t=localStorage.getItem('atar-theme');if(t==='light'||t==='dark'){document.documentElement.dataset.theme=t}}catch(e){}document.documentElement.classList.add('js');<?php if ($a11yChoices): ?>try{JSON.parse(localStorage.getItem('atar-a11y')).forEach(function(c){if(/^a11y-(text-[123]|spacing|font|contrast|grayscale|invert|links|focus|cursor|still|guide|no-images)$/.test(c)){document.documentElement.classList.add(c)}})}catch(e){}<?php endif ?></script>
    <title><?= $this->e($documentTitle) ?></title>
    <?php if (($description ?? null) !== null): ?>
    <meta name="description" content="<?= $this->e($description) ?>">
    <?php endif ?>
    <?php if (($robots ?? null) !== null): ?>
    <meta name="robots" content="<?= $this->e($robots) ?>">
    <?php endif ?>
    <?php if ($withSite): ?>
    <?= $this->siteHead() ?>
    <?php endif ?>
    <?= $this->hreflang() ?>
    <?= $this->canonical() ?>
    <?= $this->markdownAlternate() ?>
    <?= $this->socialMeta($title ?? null, $description, isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null, 'large') ?>
    <?php if (($feed ?? null) !== null): ?>
    <link rel="alternate" type="application/rss+xml" href="<?= $this->e($feed) ?>">
    <?php endif ?>
    <?php if (($preloadImage ?? null) !== null): ?>
    <link rel="preload" as="image" href="<?= $this->e($preloadImage) ?>" fetchpriority="high">
    <?php endif ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset('css/site.css')) ?>">
    <script type="module" src="<?= $this->e($this->asset('js/theme.js')) ?>"></script>
    <?php // Titles as the theme's own tips (the same file as the admin's; without support the browser's own). ?>
    <script type="module" src="<?= $this->e($this->asset('js/tooltip.js')) ?>"></script>
    <?php if ($toolbar): ?>
    <script type="module" src="<?= $this->e($this->asset('js/a11y.js')) ?>"></script>
    <?php endif ?>
    <?php if ($withSite && str_contains($content, ' data-slider>')): ?>
    <?php // The home page's slider, on a page with more than one slide or a video slide (partials/hero-slider.php). ?>
    <script type="module" src="<?= $this->e($this->asset('js/slider.js')) ?>"></script>
    <?php endif ?>
    <?php if ($withSite && str_contains($content, 'class="gallery"')): ?>
    <?php // The lightbox, only on pages with a gallery. ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset('vendor/photoswipe-5.4.4.css')) ?>">
    <script type="module" src="<?= $this->e($this->asset('js/gallery.js')) ?>"></script>
    <?php endif ?>
    <?php if ($withSite): ?>
    <?= $this->consentHead() ?>
    <?php endif ?>
</head>
<body>
<a class="skip-link" href="#main"><?= $this->e($this->t('site.skip')) ?></a>
<?php if ($withSite): ?>
<?php // The signed-in user's quick actions (nothing for visitors): the admin, "add", "edit" for this page, messages. ?>
<?= $this->partial('partials/admin-bar', ['actions' => $this->adminBar(
    isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null,
    isset($term) && $term instanceof AtarCms\Content\Term ? $term : null,
)]) ?>
<?php endif ?>
<?php if ($toolbar): ?>
<?php // The accessibility toolbar: a round button in the chosen corner, early in the tab order. ?>
<?= $this->partial('partials/a11y', ['statement' => $statement, 'position' => $settings->accessibilityToolbarPosition]) ?>
<?php endif ?>
<?php if ($chat): ?>
<?= $this->partial('partials/chat', ['messengers' => $settings->messengers, 'raised' => $chatRaised]) ?>
<?php endif ?>
<?php if ($consent !== null): ?>
<?php // The cookie banner, early in the reading order; shown by the core script while the visitor has not chosen. ?>
<?= $this->partial('partials/consent', ['consent' => $consent]) ?>
<?php endif ?>
<header class="site-header">
    <div class="container topbar">
        <?php $logo = $withSite ? $this->siteLogo() : null; ?>
        <a class="brand" href="<?= $this->e($this->url('content.home.show')) ?>">
            <?php if ($logo !== null): ?>
            <?= $this->img($logo, 'logo', ['alt' => $siteName, 'loading' => 'eager']) ?>
            <?php else: ?>
            <span class="brand-mark" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span><span><?= $this->e($siteName) ?></span>
            <?php endif ?>
        </a>
        <div class="topbar-tools">
            <?php if ($booking !== null): ?>
            <a class="button topbar-book" href="<?= $this->e($booking->url) ?>" data-form-dialog="form-booking"><?= $this->partial('partials/icon', ['name' => 'calendar']) ?><span class="topbar-book-label"><?= $this->e($this->t('forms.book')) ?></span></a>
            <?php endif ?>
            <?= $this->partial('partials/search-form', ['query' => '']) ?>
            <?= $this->partial('partials/language-menu', ['alternates' => $alternates]) ?>
            <?php if ($mainMenu !== [] || $alternates !== []): ?>
            <details class="phone-nav">
                <summary><?= $this->partial('partials/icon', ['name' => 'menu']) ?><?= $this->e($this->t('site.menu')) ?></summary>
                <div class="phone-nav-panel">
                    <?php if ($mainMenu !== []): ?>
                    <nav aria-label="<?= $this->e($this->t('site.menus.main')) ?>"><?= $this->partial('partials/menu', ['links' => $mainMenu]) ?></nav>
                    <?php endif ?>
                    <?= $this->partial('partials/search-form', ['query' => '']) ?>
                    <?= $this->partial('partials/languages', ['alternates' => $alternates]) ?>
                </div>
            </details>
            <?php endif ?>
            <?= $this->partial('partials/theme-menu') ?>
        </div>
    </div>
    <?php if ($mainMenu !== []): ?>
    <nav class="site-nav" aria-label="<?= $this->e($this->t('site.menus.main')) ?>">
        <div class="container"><?= $this->partial('partials/menu', ['links' => $mainMenu]) ?></div>
    </nav>
    <?php endif ?>
</header>
<main id="main" class="site-main<?= $wide ? ' site-main-wide' : '' ?>">
    <?php if ($wide): ?>
    <?= $content ?>
    <?php else: ?>
    <div class="container page-grid<?= $aside !== null ? ' page-grid-aside' : '' ?>">
        <div class="page-content<?= $aside === null ? ' page-narrow' : '' ?>"><?= $content ?></div>
        <?php if ($aside !== null): ?>
        <aside class="aside" aria-label="<?= $this->e($this->t('site.aside.label')) ?>"><?= $aside ?></aside>
        <?php endif ?>
    </div>
    <?php endif ?>
</main>
<?php if ($settings !== null): ?>
<?= $this->partial('partials/footer', ['settings' => $settings, 'siteName' => $siteName, 'legal' => $legal, 'consent' => $consent !== null]) ?>
<?php endif ?>
<?php if ($booking !== null): ?>
<?= $this->partial('partials/form-dialog', ['key' => 'booking']) ?>
<?php endif ?>
</body>
</html>
