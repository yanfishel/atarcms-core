<?php
/**
 * The map column of the contact page's entry form (config content.contact_page; ContactMapController): the site's map as the
 * site shows it (in the interface language) under the Waze route the site links to (or a muted line when the map has no
 * place to route to), or a muted line without a map, and for who may change it "Add map" or
 * "Change" and "Remove map", which open in the form's dialog (dialog.js; without JavaScript full pages). A save in the
 * dialog replaces this column by its id, so the entry form's unsaved text stays. It sits inside the entry form: links
 * only, no form of its own.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Settings\GoogleMap|null $map
 * @var bool $canManage whether the user may change the map
 */
?>
<section class="admin-map-panel" id="contact-map" aria-labelledby="contact-map-title">
    <h2 class="admin-map-title" id="contact-map-title"><?= $this->icon('map-pin') ?><?= $this->e($this->t('admin.map.title')) ?></h2>
    <?php if ($map !== null): ?>
    <?php if ($map->wazeUrl() !== null): ?>
    <a class="admin-map-waze" href="<?= $this->e($map->wazeUrl()) ?>" target="_blank" rel="noopener"><?= $this->icon('external-link') ?><?= $this->e($this->t('admin.map.waze')) ?></a>
    <?php else: ?>
    <p class="admin-map-none"><?= $this->e($this->t('admin.map.waze_none')) ?></p>
    <?php endif ?>
    <iframe class="admin-map-frame" src="<?= $this->e($map->embedUrl($this->locale())) ?>" title="<?= $this->e($this->t('admin.map.preview')) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe>
    <?php else: ?>
    <p class="admin-map-none"><?= $this->e($this->t('admin.map.none')) ?></p>
    <?php endif ?>
    <?php if ($canManage): ?>
    <div class="admin-map-actions">
        <?php if ($map === null): ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($this->url('admin.map')) ?>" data-dialog-open><?= $this->icon('plus') ?><?= $this->e($this->t('admin.map.add')) ?></a>
        <?php else: ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($this->url('admin.map')) ?>" data-dialog-open><?= $this->icon('pencil') ?><?= $this->e($this->t('admin.map.change')) ?></a>
        <a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url('admin.map.delete.confirm')) ?>" data-dialog-open><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.map.remove')) ?></a>
        <?php endif ?>
    </div>
    <?php endif ?>
</section>
