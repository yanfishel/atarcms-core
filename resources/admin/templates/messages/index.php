<?php
/**
 * The messages of the site's forms, newest first: unread ones in bold with a chip; a row opens the message. With more
 * than one form, a row of plain links filters by form (each with its unread count) and, in the unfiltered list, a
 * badge names each message's form; the preview is the message's first words, else its fields' values.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Messages\Message> $messages
 * @var array<int, string> $previews message id => preview
 * @var array<string, string> $formTitles form key => name; empty: no badges
 * @var list<array{label: string, url: string, unread: int, current: bool}> $tabs
 * @var ?string $form the filtered form
 * @var int $page
 * @var int $pages
 */
$title = $this->t('admin.messages.title');
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]]]);
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<?php if ($tabs !== []): ?>
<nav class="admin-filter-tabs" aria-label="<?= $this->e($this->t('admin.messages.forms')) ?>">
    <?php foreach ($tabs as $tab): ?>
    <a class="admin-filter-tab" href="<?= $this->e($tab['url']) ?>"<?= $tab['current'] ? ' aria-current="page"' : '' ?>><?= $this->e($tab['label']) ?><?php if ($tab['unread'] > 0): ?> <span class="admin-tab-count"><?= $this->e($tab['unread']) ?></span><?php endif ?></a>
    <?php endforeach ?>
</nav>
<?php endif ?>
<?php if ($messages === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon('inbox') ?>
    <p class="admin-empty-title"><?= $this->e($this->t('admin.messages.empty')) ?></p>
    <p class="admin-muted"><?= $this->e($this->t('admin.messages.empty_help')) ?></p>
</div>
<?php else: ?>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th><?= $this->e($this->t('admin.messages.column.sender')) ?></th>
                <th><?= $this->e($this->t('admin.messages.column.message')) ?></th>
                <?php if ($this->multilingual()): ?>
                <th><?= $this->e($this->t('admin.messages.column.language')) ?></th>
                <?php endif ?>
                <th><?= $this->e($this->t('admin.messages.column.received')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($messages as $message): ?>
            <tr class="admin-table-row<?= $message->isRead() ? '' : ' admin-message-unread' ?>">
                <td data-label="<?= $this->e($this->t('admin.messages.column.sender')) ?>">
                    <a class="admin-row-link" href="<?= $this->e($this->url('admin.messages.show', ['id' => $message->id])) ?>"><?= $this->e($message->sender()) ?></a>
                    <?php if (!$message->isRead()): ?>
                    <span class="admin-chip admin-chip-unread"><?= $this->e($this->t('admin.messages.unread')) ?></span>
                    <?php endif ?>
                    <?php if ($message->email !== null && $message->name !== null): ?>
                    <span class="admin-message-email" dir="ltr"><?= $this->e($message->email) ?></span>
                    <?php endif ?>
                </td>
                <td data-label="<?= $this->e($this->t('admin.messages.column.message')) ?>">
                    <?php if (isset($formTitles[$message->form])): ?>
                    <span class="admin-chip admin-chip-form"><?= $this->e($formTitles[$message->form]) ?></span>
                    <?php endif ?>
                    <span class="admin-message-preview"><?= $this->e($previews[$message->id] ?? '') ?></span>
                </td>
                <?php if ($this->multilingual()): ?>
                <td data-label="<?= $this->e($this->t('admin.messages.column.language')) ?>"><?= $this->e($this->languageName($message->locale)) ?></td>
                <?php endif ?>
                <td data-label="<?= $this->e($this->t('admin.messages.column.received')) ?>"><?= trim($this->partial('partials/table-date', ['time' => $message->createdAt])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
<?= $this->partial('partials/pagination', [
    'page' => $page,
    'pages' => $pages,
    'url' => fn(int $number): string => $this->url('admin.messages', [...($form !== null ? ['form' => $form] : []), 'page' => $number]),
]) ?>
