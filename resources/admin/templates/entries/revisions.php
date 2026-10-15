<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Collection $collection */
/** @var AtarCms\Content\Entry $entry */
/** @var list<AtarCms\Content\Revision> $revisions */
/** @var array<int, string> $authors */
/** @var bool $canRestore */
$heading = $this->t('admin.entries.revisions_title', ['title' => $entry->title]);
$editUrl = $this->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id]);
$this->layout('layouts/admin', [
    'title' => $heading,
    'breadcrumbs' => [
        [$this->label($collection->label), $collection->single ? null : $this->url('admin.entries', ['collection' => $collection->name, 'locale' => $entry->locale])],
        [$entry->title, $canRestore ? $editUrl : null],
        [$this->t('admin.entries.revisions')],
    ],
    // The entry's language, for the top bar's switch (none for a collection in one language).
    ...($collection->translatable ? ['contentLocale' => $entry->locale] : []),
]);
?>
<?= $this->partial('partials/page-header', [
    'title' => $heading,
    'locale' => $collection->translatable ? $entry->locale : null,
    'actions' => $canRestore ? [['label' => $this->t('admin.entries.edit'), 'url' => $editUrl]] : [],
]) ?>
<?php if ($revisions === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('history') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.entries.no_revisions')) ?></p>
</div>
<?php else: ?>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $this->e($this->t('admin.entries.revision.time')) ?></th>
                <th><?= $this->e($this->t('admin.entries.revision.author')) ?></th>
                <th><?= $this->e($this->t('admin.entries.revision.title')) ?></th>
                <?php if ($canRestore): ?><th><span class="admin-visually-hidden"><?= $this->e($this->t('admin.entries.revision.restore')) ?></span></th><?php endif ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($revisions as $revision): ?>
            <tr>
                <td data-label="<?= $this->e($this->t('admin.entries.revision.time')) ?>"><span dir="ltr"><?= $this->e($this->date($revision->createdAt, 'Y-m-d H:i:s')) ?></span><?php if ($revision->isAutosave()): ?> <span class="admin-muted">· <?= $this->e($this->t('admin.entries.revision.autosave')) ?></span><?php endif ?></td>
                <td data-label="<?= $this->e($this->t('admin.entries.revision.author')) ?>"><?= $this->e($revision->authorId === null ? '' : ($authors[$revision->authorId] ?? '')) ?></td>
                <td data-label="<?= $this->e($this->t('admin.entries.revision.title')) ?>"><?= $this->e($revision->title()) ?></td>
                <?php if ($canRestore): ?>
                <td class="admin-table-actions">
                    <form class="admin-inline-form" method="post" action="<?= $this->e($this->url('admin.entries.restore', ['collection' => $collection->name, 'id' => $entry->id, 'revision' => $revision->id])) ?>">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="admin-button admin-button-secondary admin-button-small"><?= $this->icon('history') ?><?= $this->e($this->t('admin.entries.revision.restore')) ?></button>
                    </form>
                </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
