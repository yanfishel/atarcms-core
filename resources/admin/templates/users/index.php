<?php
/**
 * The users list. "Add user", a row's e-mail and its Edit and Delete open the user pages, which dialog.js loads into
 * the screen's dialog (data-dialog-open); without JavaScript they are full pages. An action the current user may
 * not take is a locked button that names the reason: one's own Delete, both actions of a user whose role one cannot
 * grant (whose e-mail is plain text).
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Auth\User> $users
 * @var array<string, string> $roles
 * @var array<int, bool> $manageable
 * @var array<int, bool> $deletable
 * @var int $actorId
 */
$title = $this->t('admin.users.title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'scripts' => ['dialog.js']]);
$locked = function (string $id, string $icon, string $class, string $label, string $reason): string {
    $reasonId = $this->e($id . '-reason');

    return '<button type="button" class="' . $class . ' admin-locked" aria-disabled="true" title="' . $this->e($reason) . '" aria-label="' . $this->e($label) . '" aria-describedby="' . $reasonId . '">'
        . $this->icon($icon) . '</button><span class="admin-visually-hidden" id="' . $reasonId . '">' . $this->e($reason) . '</span>';
};
?>
<?= $this->partial('partials/page-header', [
    'title' => $title,
    'actions' => [['label' => $this->t('admin.users.new'), 'url' => $this->url('admin.users.new'), 'icon' => 'plus', 'primary' => true, 'dialog' => true]],
]) ?>
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.users.count', ['count' => count($users)])) ?></p>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $this->e($this->t('admin.users.column.email')) ?></th>
                <th><?= $this->e($this->t('admin.users.column.name')) ?></th>
                <th><?= $this->e($this->t('admin.users.column.role')) ?></th>
                <th><?= $this->e($this->t('admin.users.column.status')) ?></th>
                <th><?= $this->e($this->t('admin.users.column.last_login')) ?></th>
                <th><span class="admin-visually-hidden"><?= $this->e($this->t('admin.users.column.actions')) ?></span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <?php $canManage = $manageable[$user->id] ?? false; ?>
            <?php $editUrl = $this->url('admin.users.edit', ['id' => $user->id]); ?>
            <?php $editLabel = $this->t('admin.tree.action.edit', ['label' => $user->email]); ?>
            <?php $deleteLabel = $this->t('admin.tree.action.delete', ['label' => $user->email]); ?>
            <tr<?= $canManage ? ' class="admin-table-row"' : '' ?>>
                <td data-label="<?= $this->e($this->t('admin.users.column.email')) ?>"><?php if ($canManage): ?><a class="admin-row-link" href="<?= $this->e($editUrl) ?>" data-dialog-open><?= $this->e($user->email) ?></a><?php else: ?><span class="admin-row-title"><?= $this->e($user->email) ?></span><?php endif ?></td>
                <td data-label="<?= $this->e($this->t('admin.users.column.name')) ?>"><?= $this->e($user->name) ?></td>
                <td data-label="<?= $this->e($this->t('admin.users.column.role')) ?>"><span class="admin-chip admin-role admin-role-<?= $this->e($user->role) ?>"><?= $this->e($this->label($roles[$user->role] ?? $user->role)) ?></span></td>
                <td data-label="<?= $this->e($this->t('admin.users.column.status')) ?>"><span class="admin-chip admin-chip-<?= $this->e($user->status->value) ?>"><?= $this->e($this->t('admin.users.status.' . $user->status->value)) ?></span></td>
                <td data-label="<?= $this->e($this->t('admin.users.column.last_login')) ?>"><?php if ($user->lastLoginAt === null): ?><span class="admin-muted"><?= $this->e($this->t('admin.users.never')) ?></span><?php else: ?><?= trim($this->partial('partials/table-date', ['time' => $user->lastLoginAt])) ?><?php endif ?></td>
                <td class="admin-table-actions"><span class="admin-row-actions">
                    <?php if ($canManage): ?>
                    <a class="admin-row-action" href="<?= $this->e($editUrl) ?>" title="<?= $this->e($editLabel) ?>" aria-label="<?= $this->e($editLabel) ?>" data-dialog-open><?= $this->icon('pencil') ?></a>
                    <?php else: ?>
                    <?= $locked('user-' . $user->id . '-edit', 'pencil', 'admin-row-action', $editLabel, $this->t('admin.users.locked.role')) ?>
                    <?php endif ?>
                    <?php if ($deletable[$user->id] ?? false): ?>
                    <a class="admin-row-action admin-row-action-danger" href="<?= $this->e($this->url('admin.users.delete.confirm', ['id' => $user->id])) ?>" title="<?= $this->e($deleteLabel) ?>" aria-label="<?= $this->e($deleteLabel) ?>" data-dialog-open><?= $this->icon('trash-2') ?></a>
                    <?php else: ?>
                    <?= $locked('user-' . $user->id . '-delete', 'trash-2', 'admin-row-action admin-row-action-danger', $deleteLabel, $this->t($user->id === $actorId ? 'admin.users.locked.self' : 'admin.users.locked.role')) ?>
                    <?php endif ?>
                </span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
</dialog>
