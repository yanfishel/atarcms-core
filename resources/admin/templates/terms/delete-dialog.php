<?php
/**
 * The delete confirmation as the dialog's content (Dialog, dialog.js): Cancel (focused) closes the dialog, Delete
 * posts through htmx; a done delete reloads the list with the flash, and so does a refused one (subsections, entries
 * that take their address from it), whose reason is an error toast.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $term
 * @var int $entries
 */
$delete = $this->url(...AtarCms\Admin\Taxonomy\TermRoutes::of($taxonomy, '.delete', ['id' => $term->id]));
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.terms.delete_title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($delete) ?>" hx-post="<?= $this->e($delete) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <p><?= $this->e($this->t($this->multilingual() ? 'admin.terms.delete_confirm' : 'admin.terms.delete_confirm_single', ['name' => $term->name, 'locale' => $this->languageName($term->locale), 'count' => $entries])) ?></p>
        <?= $this->csrfField() ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close autofocus><?= $this->e($this->t('admin.terms.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.terms.delete_button')) ?></button>
    </div>
</form>
