<?php
/**
 * The quick actions of a signed-in user ($this->adminBar(): AtarCms\Admin\AdminBarAction): a floating column of round
 * icon links at the page's inline-start edge (a row at the bottom on phones). Visitors never get it; the theme draws
 * each action's Lucide icon from its sprite, a plus over the icons of "add" actions and the unread count over the
 * messages.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Admin\AdminBarAction> $actions
 */
if ($actions === []) {
    return;
}
?>
<nav class="admin-bar" aria-label="<?= $this->e($this->t('site.admin_bar')) ?>">
    <ul>
        <?php foreach ($actions as $action): ?>
        <?php if ($action->url === null): ?>
        <?php // An inactive action keeps its place; its label (also the tooltip) says why. ?>
        <li><span class="admin-bar-action admin-bar-<?= $this->e($action->kind) ?>" aria-disabled="true" aria-label="<?= $this->e($action->label) ?>" title="<?= $this->e($action->label) ?>"><?= $this->partial('partials/icon', ['name' => $action->icon]) ?></span></li>
        <?php else: ?>
        <li><a class="admin-bar-action admin-bar-<?= $this->e($action->kind) ?>" href="<?= $this->e($action->url) ?>" aria-label="<?= $this->e($action->label) ?>" title="<?= $this->e($action->label) ?>"><?= $this->partial('partials/icon', ['name' => $action->icon]) ?><?php if ($action->kind === AtarCms\Admin\AdminBarAction::ADD): ?><span class="admin-bar-plus" aria-hidden="true"><?= $this->partial('partials/icon', ['name' => 'plus']) ?></span><?php endif ?><?php if ($action->badge !== null): ?><span class="admin-bar-badge" aria-hidden="true"><?= $this->e($action->badge) ?></span><?php endif ?></a></li>
        <?php endif ?>
        <?php endforeach ?>
    </ul>
</nav>
