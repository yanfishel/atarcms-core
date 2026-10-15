<?php
/**
 * The page: header (brand, main menu, languages, search), the content, footer (footer menu, contacts, social links).
 * Pages pass title / fullTitle / description / robots. A 5xx error page renders without the database ($status).
 *
 * @var AtarCms\View\Template $this
 * @var string $content
 */
$withSite = ($status ?? 200) < 500;
$settings = $withSite ? $this->siteSettings() : null;
$description = ($description ?? null) ?? $settings?->metaDescription;
$documentTitle = $withSite ? (($fullTitle ?? null) ?? $this->pageTitle($title ?? null)) : (($title ?? null) ?? $this->t('site.name'));
$siteName = $withSite ? $this->siteName() : $this->t('site.name');
// The home page of the page's language: "/" or "/ru" ("/search" without its last segment).
$homeUrl = rtrim(dirname($this->url('search')), '/') ?: '/';
$alternates = $withSite ? $this->alternates() : [];
?>
<!doctype html>
<html<?= $this->langAttrs($this->locale()) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->e($documentTitle) ?></title>
    <?php if ($description !== null): ?>
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
    <?= $this->socialMeta($title ?? null, $description, isset($entry) && $entry instanceof AtarCms\Content\Entry ? $entry : null, 'large') ?>
    <link rel="stylesheet" href="<?= $this->e($this->asset('css/site.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main"><?= $this->e($this->t('site.skip')) ?></a>
<header class="site-header">
    <div class="container header-bar">
        <a class="brand" href="<?= $this->e($homeUrl) ?>"><?= $this->e($siteName) ?></a>
        <?php if ($withSite): ?>
        <?= $this->partial('partials/search-form', ['query' => '']) ?>
        <?php if (count($alternates) > 1): ?>
        <ul class="languages" aria-label="<?= $this->e($this->t('site.languages')) ?>">
            <?php foreach ($alternates as $code => $href): ?>
            <li><a href="<?= $this->e($href) ?>" hreflang="<?= $this->e($code) ?>"<?= $code === $this->locale() ? ' aria-current="true"' : '' ?>><span<?= $this->langAttrs($code) ?>><?= $this->e($this->languageName($code)) ?></span></a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <?php endif ?>
    </div>
    <?php if ($withSite): ?>
    <nav class="site-nav" aria-label="<?= $this->e($this->t('site.menus.main')) ?>">
        <div class="container"><?= $this->partial('partials/menu', ['links' => $this->menu('main')]) ?></div>
    </nav>
    <?php endif ?>
</header>
<main id="main" class="container site-main"><?= $content ?></main>
<footer class="site-footer">
    <div class="container footer-grid">
        <?php if ($withSite && $settings !== null): ?>
        <?php $footerMenu = $this->menu('footer'); ?>
        <?php if ($footerMenu !== []): ?>
        <nav aria-label="<?= $this->e($this->t('site.menus.footer')) ?>"><?= $this->partial('partials/menu', ['links' => $footerMenu]) ?></nav>
        <?php endif ?>
        <address class="contacts">
            <?php if ($settings->phone !== null): ?>
            <a href="tel:<?= $this->e(preg_replace('/[^+0-9]/', '', $settings->phone)) ?>" dir="ltr"><?= $this->e($settings->phone) ?></a>
            <?php endif ?>
            <?php if ($settings->email !== null): ?>
            <a href="mailto:<?= $this->e($settings->email) ?>"><?= $this->e($settings->email) ?></a>
            <?php endif ?>
            <?php if ($settings->address !== null): ?>
            <span><?= nl2br($this->e($settings->address)) ?></span>
            <?php endif ?>
        </address>
        <?php $links = [...$this->socialLinks(), ...$this->messengers()]; ?>
        <?php if ($links !== []): ?>
        <ul class="social" aria-label="<?= $this->e($this->t('site.social')) ?>">
            <?php foreach ($links as $link): ?>
            <li><a href="<?= $this->e($link->url) ?>" rel="noopener"><?= $this->e($this->t('site.network.' . $link->network)) ?></a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <?php endif ?>
        <p class="copyright">© <?= $this->e(date('Y')) ?> <?= $this->e($siteName) ?></p>
    </div>
</footer>
</body>
</html>
