<?php
/**
 * The media picker dialog (media.js opens it and loads media/picker into its body), in the shared dialog layout: the
 * header with the title and the close button, the scrolling body, the footer. It has its own toast stack (a modal dialog lies in the top layer, above the page's stack) and a footer
 * with the selection: its status line and Clear selection (galleries), Cancel and Choose / Add. The footer's texts
 * come as data attributes with "#" where media.js puts the file name or the count.
 *
 * @var AtarCms\View\Template $this
 */
?>
<dialog class="admin-dialog admin-media-dialog" data-media-dialog data-picker-url="<?= $this->e($this->url('admin.media.picker')) ?>" aria-labelledby="media-dialog-title">
    <div class="admin-dialog-header">
        <h2 id="media-dialog-title"><?= $this->e($this->t('admin.media.picker.title')) ?></h2>
        <?= $this->partial('partials/dialog-close') ?>
    </div>
    <div class="admin-dialog-body admin-media-dialog-body" data-media-dialog-body></div>
    <div class="admin-toasts" data-toasts aria-live="polite"></div>
    <div class="admin-dialog-footer" data-picker-footer data-selected="<?= $this->e($this->t('admin.media.picker.selected', ['value' => '#'])) ?>" data-none="<?= $this->e($this->t('admin.media.picker.none')) ?>" data-choose="<?= $this->e($this->t('admin.media.picker.choose')) ?>" data-add="<?= $this->e($this->t('admin.media.picker.add', ['count' => '#'])) ?>" data-already="<?= $this->e($this->t('admin.media.picker.already')) ?>">
        <p class="admin-media-picker-status" role="status" data-picker-status><?= $this->e($this->t('admin.media.picker.none')) ?></p>
        <button type="button" class="admin-button admin-button-link" hidden data-picker-clear><?= $this->e($this->t('admin.media.picker.clear')) ?></button>
        <button type="button" class="admin-button admin-button-secondary" data-picker-cancel><?= $this->e($this->t('admin.media.cancel')) ?></button>
        <button type="button" class="admin-button admin-button-primary" disabled data-picker-choose><?= $this->e($this->t('admin.media.picker.choose')) ?></button>
    </div>
</dialog>
