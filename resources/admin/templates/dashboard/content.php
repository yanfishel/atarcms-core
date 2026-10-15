<?php
/**
 * Content by collection: one row per viewable collection (singles left out), its colour dot, label and total, and a
 * bar split by status (published, scheduled, drafts) on the scale of the largest collection. The numbers are the
 * row's title (a tip) and visually hidden text; the bar is hidden from screen readers.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\Dashboard\Dashboard $dashboard
 * @var bool $rtl
 */
$labels = ['published' => 'admin.dashboard.content.published', 'scheduled' => 'admin.dashboard.content.scheduled', 'draft' => 'admin.dashboard.content.draft'];
$statuses = array_keys($labels);
$scale = max(1, ...array_map(static fn(array $row): int => $row['published'] + $row['scheduled'] + $row['draft'], array_values($dashboard->statuses)));
?>
<section class="admin-card admin-dash-content" id="dashboard-content" aria-labelledby="dashboard-content-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-content-title"><?= $this->icon('layers') ?><?= $this->e($this->t('admin.dashboard.content.title')) ?></h2>
    </div>
    <ul class="admin-dash-legend">
        <?php foreach ($statuses as $status): ?>
        <li><span class="admin-dash-key admin-dash-key-<?= $this->e($status) ?>" aria-hidden="true"></span><?= $this->e($this->t($labels[$status])) ?></li>
        <?php endforeach ?>
    </ul>
    <ul class="admin-dash-stacks">
        <?php foreach ($dashboard->statuses as $row): ?>
        <?php
        $collection = $row['collection'];
        $total = $row['published'] + $row['scheduled'] + $row['draft'];
        $summary = $this->t('admin.dashboard.content.summary', ['published' => $row['published'], 'scheduled' => $row['scheduled'], 'draft' => $row['draft']]);
        $x = 0;
        ?>
        <li class="admin-dash-stack admin-tone-<?= $this->e((string) ($dashboard->tones[$collection->name] ?? 8)) ?>" title="<?= $this->e($summary) ?>">
            <div class="admin-dash-stack-head">
                <span class="admin-dash-dot" aria-hidden="true"></span>
                <a href="<?= $this->e($this->url('admin.entries', ['collection' => $collection->name])) ?>"><?= $this->e($this->label($collection->label)) ?></a>
                <span class="admin-dash-stack-total"><?= $this->e((string) $total) ?></span>
            </div>
            <span class="admin-visually-hidden"><?= $this->e($summary) ?></span>
            <svg class="admin-dash-stack-bar" viewBox="0 0 <?= $this->e((string) $scale) ?> 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><?php foreach ($statuses as $status): ?><?php if ($row[$status] > 0): ?><rect class="admin-dash-seg admin-dash-seg-<?= $this->e($status) ?>" x="<?= $this->e((string) ($rtl ? $scale - $x - $row[$status] : $x)) ?>" y="0" width="<?= $this->e((string) $row[$status]) ?>" height="1" vector-effect="non-scaling-stroke"/><?php $x += $row[$status]; ?><?php endif ?><?php endforeach ?></svg>
        </li>
        <?php endforeach ?>
    </ul>
</section>
