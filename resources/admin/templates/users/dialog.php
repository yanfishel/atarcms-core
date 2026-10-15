<?php
/**
 * The user form as the dialog's content (Dialog, dialog.js): the header (title, close), the fields as the scrolling
 * body and the footer with the buttons. The form posts through htmx: its answer replaces this fragment (field errors)
 * or reloads the users list (saved). Cancel closes the dialog.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Auth\User|null $user
 * @var array{email: string, name: string, role: string, status: string} $values
 * @var array<string, string> $errors only field errors reach here (UsersController::form())
 * @var array<string, string> $roles
 * @var bool $isSelf
 * @var string $originalStatus
 */
$heading = $user === null ? $this->t('admin.users.new') : $user->email;
$action = $user === null ? $this->url('admin.users.store') : $this->url('admin.users.update', ['id' => $user->id]);
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($heading) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($action) ?>" hx-post="<?= $this->e($action) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <?= $this->partial('terms/alert', ['errors' => $errors]) ?>
        <?= $this->csrfField() ?>
        <?php if ($user !== null): ?>
        <input type="hidden" name="original_status" value="<?= $this->e($originalStatus) ?>">
        <?php endif ?>
        <?= $this->partial('users/fields', ['user' => $user, 'values' => $values, 'errors' => $errors, 'roles' => $roles, 'isSelf' => $isSelf]) ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close><?= $this->e($this->t('admin.users.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($user === null ? $this->t('admin.users.create') : $this->t('admin.users.save')) ?></button>
    </div>
</form>
