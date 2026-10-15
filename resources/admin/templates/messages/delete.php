<?php
/**
 * Confirms deleting a message of the contact form.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Message $message
 */
$showUrl = $this->url('admin.messages.show', ['id' => $message->id]);
$this->layout('layouts/admin', [
    'title' => $this->t('admin.messages.delete_title'),
    'breadcrumbs' => [
        [$this->t('admin.messages.title'), $this->url('admin.messages')],
        [$message->sender(), $showUrl],
        [$this->t('admin.messages.delete_title')],
    ],
]);
?>
<section class="admin-card admin-card-danger admin-confirm">
    <?= $this->icon('trash-2') ?>
    <h1><?= $this->e($this->t('admin.messages.delete_title')) ?></h1>
    <p><?= $this->e($this->t('admin.messages.delete_confirm', ['name' => $message->sender()])) ?></p>
    <form class="admin-confirm-actions" method="post" action="<?= $this->e($this->url('admin.messages.delete', ['id' => $message->id])) ?>">
        <?= $this->csrfField() ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($showUrl) ?>"><?= $this->e($this->t('admin.messages.cancel')) ?></a>
        <button type="submit" class="admin-button admin-button-danger"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.messages.delete_button')) ?></button>
    </form>
</section>
