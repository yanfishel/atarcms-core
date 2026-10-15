<?php
/** @var AtarCms\View\Template $this */
/** @var list<AtarCms\Auth\AuditEntry> $entries */
/** @var int $page */
/** @var int $pages */
$title = $this->t('admin.audit.title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]]]);
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<?php if ($entries === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('history') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.audit.empty')) ?></p>
</div>
<?php else: ?>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $this->e($this->t('admin.audit.column.time')) ?></th>
                <th><?= $this->e($this->t('admin.audit.column.user')) ?></th>
                <th><?= $this->e($this->t('admin.audit.column.action')) ?></th>
                <th><?= $this->e($this->t('admin.audit.column.subject')) ?></th>
                <th><?= $this->e($this->t('admin.audit.column.ip')) ?></th>
                <th><?= $this->e($this->t('admin.audit.column.details')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr class="audit-entry">
                <td data-label="<?= $this->e($this->t('admin.audit.column.time')) ?>"><span dir="ltr"><?= $this->e($this->date($entry->createdAt, 'Y-m-d H:i:s')) ?></span></td>
                <td data-label="<?= $this->e($this->t('admin.audit.column.user')) ?>"><?= $this->e($entry->userEmail ?? '—') ?></td>
                <?php $actionKey = 'admin.audit.action.' . $entry->action; ?>
                <td data-label="<?= $this->e($this->t('admin.audit.column.action')) ?>"><?php if ($this->hasTranslation($actionKey)): ?><span title="<?= $this->e($entry->action) ?>"><?= $this->e($this->t($actionKey)) ?></span><?php else: ?><code><?= $this->e($entry->action) ?></code><?php endif ?></td>
                <td data-label="<?= $this->e($this->t('admin.audit.column.subject')) ?>"><?= $this->partial('audit/subject', ['entry' => $entry]) ?></td>
                <td data-label="<?= $this->e($this->t('admin.audit.column.ip')) ?>"><?= $this->e($entry->ip ?? '') ?></td>
                <td data-label="<?= $this->e($this->t('admin.audit.column.details')) ?>">
                    <?php if ($entry->meta !== []): ?>
                    <details class="admin-details"><summary><?= $this->e($this->t('admin.audit.show_details')) ?></summary><code><?= $this->e(json_encode($entry->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></code></details>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
<?= $this->partial('partials/pagination', [
    'page' => $page,
    'pages' => $pages,
    'url' => fn(int $number): string => $this->url('admin.audit', ['page' => $number]),
]) ?>
