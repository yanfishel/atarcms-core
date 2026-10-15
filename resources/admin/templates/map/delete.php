<?php
/**
 * The map's removal as a full page (without JavaScript): Cancel goes back to the contact page's entry form, Remove posts.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection the contact page's collection
 * @var string $back the entry form
 */
$title = $this->t('admin.map.delete_title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$this->label($collection->label), $back], [$title]]]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($title) ?></h1>
    <p><?= $this->e($this->t('admin.map.delete_confirm')) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.map.delete')) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($back) ?>"><?= $this->e($this->t('admin.map.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.map.remove')) ?></button>
    </form>
</section>
