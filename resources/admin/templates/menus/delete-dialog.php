<?php
/**
 * The delete confirmation as the dialog's content (Dialog, dialog.js): Cancel (focused) closes the dialog, Delete
 * posts through htmx and a done delete reloads the menu screen with the flash.
 *
 * @var AtarCms\View\Template $this
 * @var string $location
 * @var AtarCms\Content\Menu\MenuItem $item
 * @var string $label
 * @var int $subitems
 */
$delete = $this->url('admin.menus.delete', ['location' => $location, 'id' => $item->id]);
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.menus.delete_title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($delete) ?>" hx-post="<?= $this->e($delete) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <p><?= $this->e($this->t($this->multilingual() ? 'admin.menus.delete_confirm' : 'admin.menus.delete_confirm_single', ['label' => $label, 'locale' => $this->languageName($item->locale), 'count' => $subitems])) ?></p>
        <?= $this->csrfField() ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close autofocus><?= $this->e($this->t('admin.menus.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.menus.delete_button')) ?></button>
    </div>
</form>
