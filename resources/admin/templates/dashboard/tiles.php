<?php
/**
 * The KPI tiles: each a coloured icon, its number, a line saying what changed and, for the counts over time, a
 * sparkline of the last 30 days (aria-hidden: the line above says the same in words).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\Dashboard\Dashboard $dashboard
 * @var bool $rtl
 */
use AtarCms\Admin\Dashboard\DashboardData;
use AtarCms\Admin\Dashboard\Sparkline;

$spark = function (array $days) use ($rtl): string {
    $line = new Sparkline($days);

    return '<svg class="admin-spark" viewBox="0 0 ' . Sparkline::WIDTH . ' ' . Sparkline::HEIGHT . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<path class="admin-spark-area" d="' . $this->e($line->area($rtl)) . '"/>'
        . '<path class="admin-spark-line" d="' . $this->e($line->line($rtl)) . '" vector-effect="non-scaling-stroke"/></svg>';
};
$size = function (int $bytes): string {
    foreach (['admin.dashboard.media.used_gb' => 1024 ** 3, 'admin.dashboard.media.used_mb' => 1024 ** 2] as $key => $factor) {
        if ($bytes >= $factor) {
            return $this->t($key, ['size' => round($bytes / $factor, 1)]);
        }
    }

    return $this->t('admin.dashboard.media.used_kb', ['size' => round($bytes / 1024, 1)]);
};
$days = DashboardData::TILE_DAYS;
// The big numbers in the interface language's digits and grouping.
$formatter = new NumberFormatter($this->locale(), NumberFormatter::DECIMAL);
$number = static fn(int $value): string => (string) $formatter->format($value);
if ($dashboard->published === null && $dashboard->messages === null && $dashboard->media === null) {
    return;
}
?>
<section class="admin-dash-tiles" aria-label="<?= $this->e($this->t('admin.dashboard.tiles')) ?>">
    <?php if ($dashboard->published !== null): ?>
    <article class="admin-dash-tile admin-tone-accent">
        <div class="admin-dash-tile-head">
            <span class="admin-dash-tile-icon"><?= $this->icon('eye') ?></span>
            <h2 class="admin-dash-tile-label"><?= $this->e($this->t('admin.dashboard.published.title')) ?></h2>
        </div>
        <p class="admin-dash-tile-value"><?= $this->e($number($dashboard->published['count'])) ?></p>
        <p class="admin-dash-tile-note"><?= $this->e($this->t('admin.dashboard.published.recent', ['count' => $dashboard->published['recent'], 'days' => $days])) ?></p>
        <?= $spark($dashboard->published['days']) ?>
    </article>
    <?php endif ?>
    <?php if ($dashboard->pending !== null): ?>
    <article class="admin-dash-tile admin-tone-warning">
        <div class="admin-dash-tile-head">
            <span class="admin-dash-tile-icon"><?= $this->icon('file-text') ?></span>
            <h2 class="admin-dash-tile-label"><?= $this->e($this->t('admin.dashboard.pending')) ?></h2>
        </div>
        <dl class="admin-dash-pair">
            <div class="admin-dash-pair-item">
                <dt><?= $this->e($this->t('admin.dashboard.drafts')) ?></dt>
                <dd><?= $this->e($number($dashboard->pending['drafts'])) ?></dd>
            </div>
            <div class="admin-dash-pair-item">
                <dt><?= $this->e($this->t('admin.dashboard.scheduled')) ?></dt>
                <dd><?= $this->e($number($dashboard->pending['scheduled'])) ?></dd>
            </div>
        </dl>
    </article>
    <?php endif ?>
    <?php if ($dashboard->messages !== null): ?>
    <article class="admin-dash-tile admin-tone-6">
        <div class="admin-dash-tile-head">
            <span class="admin-dash-tile-icon"><?= $this->icon('inbox') ?></span>
            <h2 class="admin-dash-tile-label"><a class="admin-dash-tile-link" href="<?= $this->e($this->url('admin.messages')) ?>"><?= $this->e($this->t('admin.dashboard.unread.title')) ?></a></h2>
        </div>
        <p class="admin-dash-tile-value"><?= $this->e($number($dashboard->messages['unread'])) ?></p>
        <p class="admin-dash-tile-note"><?= $this->e($this->t('admin.dashboard.unread.recent', ['count' => $dashboard->messages['recent'], 'days' => $days])) ?></p>
        <?= $spark($dashboard->messages['days']) ?>
    </article>
    <?php endif ?>
    <?php if ($dashboard->media !== null): ?>
    <article class="admin-dash-tile admin-tone-5">
        <div class="admin-dash-tile-head">
            <span class="admin-dash-tile-icon"><?= $this->icon('images') ?></span>
            <h2 class="admin-dash-tile-label"><a class="admin-dash-tile-link" href="<?= $this->e($this->url('admin.media')) ?>"><?= $this->e($this->t('admin.menu.media')) ?></a></h2>
        </div>
        <p class="admin-dash-tile-value"><?= $this->e($number($dashboard->media['count'])) ?></p>
        <p class="admin-dash-tile-note"><?= $this->e($size($dashboard->media['bytes'])) ?></p>
    </article>
    <?php endif ?>
</section>
