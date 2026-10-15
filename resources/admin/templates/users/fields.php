<?php
/**
 * The user form's fields, shared by the page (users/form.php) and the dialog (users/dialog.php): the e-mail (fixed
 * once created), the name, the role and, for an existing user, the status. One's own role and status show disabled
 * and post as hidden inputs (UsersController ignores them anyway).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Auth\User|null $user
 * @var array{email: string, name: string, role: string, status: string} $values
 * @var array<string, string> $errors
 * @var array<string, string> $roles
 * @var bool $isSelf
 */
$error = fn(string $key): string => isset($errors[$key]) ? '<span class="admin-field-error">' . $this->e($this->t($errors[$key])) . '</span>' : '';
$fieldClass = static fn(string $key): string => 'admin-field' . (isset($errors[$key]) ? ' admin-field-invalid' : '');
?>
<div class="<?= $fieldClass('email') ?>">
    <label for="email"><?= $this->icon('log-in') ?><?= $this->e($this->t('admin.users.column.email')) ?></label>
    <?php if ($user === null): ?>
    <input id="email" name="email" type="email" required maxlength="254" dir="ltr" value="<?= $this->e($values['email']) ?>">
    <?php else: ?>
    <input id="email" type="email" dir="ltr" value="<?= $this->e($values['email']) ?>" disabled>
    <?php endif ?>
    <?= $error('email') ?>
</div>
<div class="<?= $fieldClass('name') ?>">
    <label for="name"><?= $this->icon('type') ?><?= $this->e($this->t('admin.users.column.name')) ?></label>
    <input id="name" name="name" maxlength="190" value="<?= $this->e($values['name']) ?>">
    <?= $error('name') ?>
</div>
<div class="<?= $fieldClass('role') ?>">
    <label for="role"><?= $this->icon('users') ?><?= $this->e($this->t('admin.users.column.role')) ?></label>
    <?php if ($isSelf): ?>
    <input type="hidden" name="role" value="<?= $this->e($values['role']) ?>">
    <?php endif ?>
    <select id="role"<?= $isSelf ? ' disabled' : ' name="role"' ?>>
        <?php if ($values['role'] === ''): ?>
        <option value="" selected disabled><?= $this->e($this->t('admin.users.choose_role')) ?></option>
        <?php elseif (!isset($roles[$values['role']])): ?>
        <option value="" selected disabled><?= $this->e($this->t('admin.users.unknown_role', ['role' => $values['role']])) ?></option>
        <?php endif ?>
        <?php foreach ($roles as $role => $label): ?>
        <option value="<?= $this->e($role) ?>"<?= $role === $values['role'] ? ' selected' : '' ?>><?= $this->e($this->label($label)) ?></option>
        <?php endforeach ?>
    </select>
    <span class="admin-help"><?= $this->e($this->t('admin.users.role_help')) ?></span>
    <?= $error('role') ?>
</div>
<?php if ($user !== null): ?>
<div class="<?= $fieldClass('status') ?>">
    <label for="status"><?= $this->icon('toggle-left') ?><?= $this->e($this->t('admin.users.column.status')) ?></label>
    <?php if ($isSelf): ?>
    <input type="hidden" name="status" value="<?= $this->e($values['status']) ?>">
    <?php endif ?>
    <select id="status"<?= $isSelf ? ' disabled' : ' name="status"' ?>>
        <?php foreach (['active', 'disabled'] as $status): ?>
        <option value="<?= $this->e($status) ?>"<?= $status === $values['status'] ? ' selected' : '' ?>><?= $this->e($this->t('admin.users.status.' . $status)) ?></option>
        <?php endforeach ?>
    </select>
    <?= $error('status') ?>
</div>
<?php endif ?>
