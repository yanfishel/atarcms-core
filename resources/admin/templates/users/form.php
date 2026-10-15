<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Auth\User|null $user */
/** @var array{email: string, name: string, role: string, status: string} $values */
/** @var array<string, string> $errors */
/** @var array<string, string> $roles */
/** @var bool $isSelf */
/** @var string $originalStatus */
$heading = $user === null ? $this->t('admin.users.new') : $this->t('admin.users.edit');
$action = $user === null ? $this->url('admin.users.store') : $this->url('admin.users.update', ['id' => $user->id]);
$this->layout('layouts/admin', [
    'title' => $heading,
    'breadcrumbs' => [[$this->t('admin.users.title'), $this->url('admin.users')], [$user === null ? $heading : $user->email]],
]);
?>
<?= $this->partial('partials/page-header', ['title' => $heading]) ?>
<?= $this->partial('terms/alert', ['errors' => $errors]) ?>
<form class="admin-form-card" method="post" action="<?= $this->e($action) ?>">
    <?= $this->csrfField() ?>
    <?php if ($user !== null): ?>
    <input type="hidden" name="original_status" value="<?= $this->e($originalStatus) ?>">
    <?php endif ?>
    <div class="admin-card admin-form">
        <?= $this->partial('users/fields', ['user' => $user, 'values' => $values, 'errors' => $errors, 'roles' => $roles, 'isSelf' => $isSelf]) ?>
    </div>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <?php if ($user !== null && !$isSelf): ?>
            <a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url('admin.users.delete.confirm', ['id' => $user->id])) ?>"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.users.delete')) ?></a>
            <?php endif ?>
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($user === null ? $this->t('admin.users.create') : $this->t('admin.users.save')) ?></button>
        </div>
    </div>
</form>
