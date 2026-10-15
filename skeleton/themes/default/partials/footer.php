<?php
/**
 * The site footer: the name with the address and opening hours, the phone, email and messengers, the footer menu
 * (Structure → Menus) and the social links of the site settings, then the copyright line and the links to the preset
 * pages, the privacy policy and the accessibility statement (with or without the accessibility toolbar), and "Cookie
 * settings", which reopens the cookie banner (with the banner only, and only with JavaScript). Every value is user text:
 * escape it.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Settings\Settings $settings
 * @var string $siteName
 * @var list<array{0: string, 1: string}> $legal the preset pages published in this language: title and address
 * @var bool $consent the page has the cookie banner (partials/consent.php)
 */
$social = $this->socialLinks();
$footerMenu = $this->menu('footer');
?>
<footer class="site-footer">
    <div class="container footer-grid">
        <div>
            <p class="footer-name"><?= $this->e($siteName) ?></p>
            <?php if ($settings->address !== null): ?>
            <p class="footer-text"><?= $this->e($settings->address) ?></p>
            <?php endif ?>
            <?php if ($settings->openingHours !== null): ?>
            <p class="footer-text"><?= $this->e($settings->openingHours) ?></p>
            <?php endif ?>
        </div>
        <?php if ($settings->phone !== null || $settings->email !== null || $settings->messengers !== []): ?>
        <div>
            <h2><?= $this->e($this->t('site.footer.contacts')) ?></h2>
            <?= $this->partial('partials/contact-links', ['settings' => $settings]) ?>
        </div>
        <?php endif ?>
        <?php if ($footerMenu !== []): ?>
        <nav aria-labelledby="footer-menu-title">
            <h2 id="footer-menu-title"><?= $this->e($this->t('site.footer.menu')) ?></h2>
            <ul class="footer-links">
                <?php foreach ($footerMenu as $link): ?>
                <li><a href="<?= $this->e($link->url) ?>"<?= $link->current ? ' aria-current="page"' : '' ?><?= $link->newTab ? ' target="_blank" rel="noopener"' : '' ?>><?= $this->e($link->label) ?></a></li>
                <?php endforeach ?>
            </ul>
        </nav>
        <?php endif ?>
        <?php if ($social !== []): ?>
        <div>
            <h2><?= $this->e($this->t('site.footer.follow')) ?></h2>
            <ul class="social-buttons" aria-label="<?= $this->e($this->t('site.social')) ?>">
                <?php foreach ($social as $link): ?>
                <?php $name = $this->t('site.network.' . $link->network); ?>
                <li><a class="social-button" href="<?= $this->e($link->url) ?>" rel="me noopener" aria-label="<?= $this->e($name) ?>" title="<?= $this->e($name) ?>"><?= $this->partial('partials/brand-icon', ['network' => $link->network]) ?></a></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>
    </div>
    <div class="container footer-bottom">
        <p><?= $this->e($this->t('site.footer.copyright', ['year' => $this->date(new DateTimeImmutable(), 'Y'), 'site' => $siteName])) ?></p>
        <?php if ($legal !== [] || $consent): ?>
        <?php // Holding only "Cookie settings" (JavaScript only), the list shows only with JavaScript too. ?>
        <ul class="footer-legal<?= $legal === [] ? ' footer-legal-js' : '' ?>">
            <?php foreach ($legal as [$label, $url]): ?>
            <li><a href="<?= $this->e($url) ?>"><?= $this->e($label) ?></a></li>
            <?php endforeach ?>
            <?php if ($consent): ?>
            <li class="footer-consent"><button type="button" class="footer-consent-button" aria-controls="consent" data-consent-open><?= $this->e($this->t('site.consent.settings')) ?></button></li>
            <?php endif ?>
        </ul>
        <?php endif ?>
    </div>
</footer>
