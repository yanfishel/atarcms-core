<?php
/**
 * In progress: the recently updated entries, the drafts and the scheduled ones as the tabs of one card (tabs.js, the
 * tab remembered); without JavaScript the three lists show under their headings. Drafts and Scheduled count all.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\Dashboard\Dashboard $dashboard
 */
$tabs = [
    'recent' => ['label' => 'admin.dashboard.recent', 'icon' => 'history', 'count' => null],
    'drafts' => ['label' => 'admin.dashboard.drafts', 'icon' => 'file-text', 'count' => $dashboard->pending['drafts'] ?? null],
    'scheduled' => ['label' => 'admin.dashboard.scheduled', 'icon' => 'calendar', 'count' => $dashboard->pending['scheduled'] ?? null],
];
$title = $this->t('admin.dashboard.progress');
?>
<section class="admin-card admin-dash-progress" id="dashboard-progress" aria-labelledby="dashboard-progress-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-progress-title"><?= $this->icon('pencil-line') ?><?= $this->e($title) ?></h2>
    </div>
    <div class="admin-form-column" data-tabs="admin.tabs.dashboard">
        <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($title) ?>">
            <?php foreach ($tabs as $tab => $info): ?>
            <button type="button" class="admin-tab" role="tab" id="tab-<?= $this->e($tab) ?>" aria-controls="panel-<?= $this->e($tab) ?>" aria-selected="<?= $tab === 'recent' ? 'true' : 'false' ?>" tabindex="<?= $tab === 'recent' ? '0' : '-1' ?>" data-tab="<?= $this->e($tab) ?>"><?= $this->e($this->t($info['label'])) ?><?php if (($info['count'] ?? 0) > 0): ?> <span class="admin-tab-count"><?= $this->e((string) $info['count']) ?></span><?php endif ?></button>
            <?php endforeach ?>
        </div>
        <?php foreach ($tabs as $tab => $info): ?>
        <?php $entries = $dashboard->progress[$tab]; ?>
        <section class="admin-tab-panel<?= $tab === 'recent' ? ' admin-tab-panel-open' : '' ?>" role="tabpanel" id="panel-<?= $this->e($tab) ?>" aria-labelledby="tab-<?= $this->e($tab) ?>">
            <h3 class="admin-tab-heading"><?= $this->e($this->t($info['label'])) ?></h3>
            <?php if ($entries === []): ?>
            <div class="admin-empty admin-panel-empty">
                <?= $this->icon($info['icon']) ?>
                <p><?= $this->e($this->t('admin.dashboard.nothing')) ?></p>
            </div>
            <?php else: ?>
            <ul class="admin-panel-list">
                <?php foreach ($entries as $entry): ?>
                <?php // The collection's dot, the title and its collection (with the language and a scheduled time), the status. ?>
                <li class="admin-panel-item admin-tone-<?= $this->e((string) ($dashboard->tones[$entry->collection->name] ?? 8)) ?>">
                    <span class="admin-dash-dot" aria-hidden="true"></span>
                    <div class="admin-panel-text">
                        <?php if ($dashboard->editable[$entry->id] ?? false): ?>
                        <a href="<?= $this->e($this->url('admin.entries.edit', ['collection' => $entry->collection->name, 'id' => $entry->id])) ?>"><?= $this->e($entry->title) ?></a>
                        <?php else: ?>
                        <span class="admin-row-title"><?= $this->e($entry->title) ?></span>
                        <?php endif ?>
                        <span class="admin-panel-meta"><?= $this->e($this->label($entry->collection->label)) ?><?= $this->multilingual() ? ' · ' . $this->e(strtoupper($entry->locale)) : '' ?><?= $tab === 'scheduled' ? ' · <span dir="ltr">' . $this->e($this->date($entry->publishedAt)) . '</span>' : '' ?></span>
                    </div>
                    <span class="admin-chip admin-chip-<?= $this->e($entry->status->value) ?>"><?= $this->e($this->t('admin.entries.status.' . $entry->status->value)) ?></span>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>
        <?php endforeach ?>
    </div>
</section>
