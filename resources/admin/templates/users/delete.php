<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Auth\User $user */
$editUrl = $this->url('admin.users.edit', ['id' => $user->id]);
$this->layout('layouts/admin', [
    'title' => $this->t('admin.users.delete_title'),
    'breadcrumbs' => [
        [$this->t('admin.users.title'), $this->url('admin.users')],
        [$user->email, $editUrl],
        [$this->t('admin.users.delete_title')],
    ],
]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.users.delete_title')) ?></h1>
    <p><?= $this->e($this->t('admin.users.delete_confirm', ['email' => $user->email])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.users.delete', ['id' => $user->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($editUrl) ?>"><?= $this->e($this->t('admin.users.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.users.delete_button')) ?></button>
    </form>
</section>
