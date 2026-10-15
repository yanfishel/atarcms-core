<?php
/**
 * The close button at the end of every modal's header (the dialog layout: header with the title and this button, the
 * body, the footer with the actions). A form with method="dialog" closes the dialog without JavaScript of its own.
 *
 * @var AtarCms\View\Template $this
 */
?>
<form class="admin-dialog-close" method="dialog"><button type="submit" class="admin-button admin-button-link admin-button-icon" title="<?= $this->e($this->t('admin.dialog.close')) ?>" aria-label="<?= $this->e($this->t('admin.dialog.close')) ?>"><?= $this->icon('x') ?></button></form>
