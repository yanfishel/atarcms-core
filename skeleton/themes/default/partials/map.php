<?php
/**
 * The site's Google map ($this->contactMap(): added, changed and removed on the contacts page's admin form), in the
 * page's language, and "Open in Google Maps" and "Directions in Waze" when the map has a place to open. The iframe's
 * host is always allowed by the Content Security Policy.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Settings\MapView $map
 */
?>
<figure class="map">
    <iframe class="map-frame" src="<?= $this->e($map->embedUrl) ?>" title="<?= $this->e($this->t('site.contacts.map')) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox" allowfullscreen></iframe>
    <?php if ($map->linkUrl !== null || $map->wazeUrl !== null): ?>
    <figcaption>
        <?php if ($map->linkUrl !== null): ?>
        <a href="<?= $this->e($map->linkUrl) ?>" rel="noopener" target="_blank"><?= $this->e($this->t('site.contacts.open_map')) ?></a>
        <?php endif ?>
        <?php if ($map->wazeUrl !== null): ?>
        <a class="map-waze" href="<?= $this->e($map->wazeUrl) ?>" rel="noopener" target="_blank"><svg class="icon icon-brand" aria-hidden="true" focusable="false"><use href="<?= $this->e($this->asset('brands.svg')) ?>#waze"></use></svg> <?= $this->e($this->t('site.contacts.waze')) ?></a>
        <?php endif ?>
    </figcaption>
    <?php endif ?>
</figure>
