<?php
/**
 * The activity chart: edits and publications per day (per week over 90 days) as bars drawn by the server. The SVG
 * stretches to its box and is hidden from screen readers; the HTML columns over it carry the hover tips (titles), the
 * visually hidden table the same numbers. In a right-to-left interface the server lays the bars out from the right.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\Dashboard\Dashboard $dashboard
 * @var bool $rtl
 */
use AtarCms\Admin\Dashboard\BarChart;
use AtarCms\Admin\Dashboard\DayBuckets;

$activity = $dashboard->activity ?? ['edits' => [], 'publications' => []];
$buckets = $dashboard->buckets;
$weekly = $buckets->step() > 1;
$locale = $this->locale();
// Figures in the interface language's digits and grouping, like the tiles.
$formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
$number = static fn(int $value): string => (string) $formatter->format($value);
$columns = [];
foreach ($buckets->columns() as $index => $days) {
    $first = DayBuckets::label($days[0], $locale);
    $columns[] = [
        'label' => count($days) > 1 ? $this->t('admin.dashboard.activity.week', ['from' => $first, 'to' => DayBuckets::label($days[count($days) - 1], $locale)]) : $first,
        'axis' => $first,
        'edits' => $activity['edits'][$index] ?? 0,
        'publications' => $activity['publications'][$index] ?? 0,
    ];
}
$chart = new BarChart(array_map(static fn(array $column): array => [$column['edits'], $column['publications']], $columns));
$series = ['edits', 'publications'];
$totals = ['edits' => array_sum($activity['edits']), 'publications' => array_sum($activity['publications'])];
$count = count($columns);
// Every 5th day from today (every 3rd week), so the labels never crowd; a week shows each day.
$every = $count <= 7 ? 1 : ($weekly ? 3 : 5);
$caption = $this->t('admin.dashboard.activity.caption', ['days' => $dashboard->range, 'step' => $weekly ? 'week' : 'day']);
?>
<section class="admin-card admin-dash-activity" id="dashboard-activity" aria-labelledby="dashboard-activity-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-activity-title"><?= $this->icon('chart-no-axes-column') ?><?= $this->e($this->t('admin.dashboard.activity.title')) ?></h2>
        <nav class="admin-dash-range" aria-label="<?= $this->e($this->t('admin.dashboard.activity.range')) ?>">
            <?php foreach (DayBuckets::RANGES as $range): ?>
            <?php $href = $this->url('admin.dashboard') . '?days=' . $range; ?>
            <a id="dashboard-range-<?= $this->e((string) $range) ?>" href="<?= $this->e($href) ?>" hx-get="<?= $this->e($href) ?>" hx-select="#dashboard-activity" hx-target="#dashboard-activity" hx-swap="outerHTML" hx-push-url="true"<?= $range === $dashboard->range ? ' aria-current="true"' : '' ?>><?= $this->e($this->t('admin.dashboard.activity.days', ['days' => $range])) ?></a>
            <?php endforeach ?>
        </nav>
    </div>
    <ul class="admin-dash-legend">
        <?php foreach ($series as $name): ?>
        <li><span class="admin-dash-key admin-dash-key-<?= $this->e($name) ?>" aria-hidden="true"></span><?= $this->e($this->t('admin.dashboard.activity.' . $name)) ?> <strong><?= $this->e($number($totals[$name])) ?></strong></li>
        <?php endforeach ?>
        <li class="admin-dash-legend-note"><?= $this->e($this->t($weekly ? 'admin.dashboard.activity.per_week' : 'admin.dashboard.activity.per_day')) ?></li>
    </ul>
    <div class="admin-chart">
        <div class="admin-chart-plot">
            <span class="admin-chart-tick admin-chart-tick-top" aria-hidden="true"><?= $this->e($number($chart->max())) ?></span>
            <span class="admin-chart-tick admin-chart-tick-mid" aria-hidden="true"><?= $this->e($number($chart->mid())) ?></span>
            <svg class="admin-chart-svg" viewBox="0 0 <?= $this->e((string) $chart->width()) ?> <?= BarChart::HEIGHT ?>" preserveAspectRatio="none" aria-hidden="true" focusable="false"><line class="admin-chart-grid" x1="0" y1="0.5" x2="<?= $this->e((string) $chart->width()) ?>" y2="0.5" vector-effect="non-scaling-stroke"/><line class="admin-chart-grid" x1="0" y1="50" x2="<?= $this->e((string) $chart->width()) ?>" y2="50" vector-effect="non-scaling-stroke"/><?php foreach ($chart->bars($rtl) as $bar): ?><rect class="admin-chart-bar admin-chart-bar-<?= $this->e($series[$bar['series']]) ?>" x="<?= $this->e((string) $bar['x']) ?>" y="<?= $this->e((string) $bar['y']) ?>" width="<?= $this->e((string) $bar['width']) ?>" height="<?= $this->e((string) $bar['height']) ?>"/><?php endforeach ?><line class="admin-chart-base" x1="0" y1="<?= BarChart::HEIGHT ?>" x2="<?= $this->e((string) $chart->width()) ?>" y2="<?= BarChart::HEIGHT ?>" vector-effect="non-scaling-stroke"/></svg>
            <ol class="admin-chart-hits" aria-hidden="true">
                <?php foreach ($columns as $column): ?>
                <li class="admin-chart-hit" title="<?= $this->e($this->t('admin.dashboard.activity.tip', ['day' => $column['label'], 'edits' => $column['edits'], 'publications' => $column['publications']])) ?>"></li>
                <?php endforeach ?>
            </ol>
            <?php if ($totals['edits'] + $totals['publications'] === 0): ?>
            <p class="admin-chart-empty"><?= $this->e($this->t('admin.dashboard.activity.empty', ['days' => $dashboard->range])) ?></p>
            <?php endif ?>
        </div>
        <ol class="admin-chart-axis" aria-hidden="true">
            <?php foreach ($columns as $index => $column): ?>
            <?php $back = $count - 1 - $index; // Every second label gives way on a narrow card. ?>
            <li<?= $back % $every === 0 && intdiv($back, $every) % 2 === 1 ? ' class="admin-chart-axis-minor"' : '' ?>><?= $back % $every === 0 ? '<span>' . $this->e($column['axis']) . '</span>' : '' ?></li>
            <?php endforeach ?>
        </ol>
    </div>
    <table class="admin-visually-hidden">
        <caption><?= $this->e($caption) ?></caption>
        <thead>
            <tr><th scope="col"><?= $this->e($this->t($weekly ? 'admin.dashboard.activity.column_week' : 'admin.dashboard.activity.column_day')) ?></th><th scope="col"><?= $this->e($this->t('admin.dashboard.activity.edits')) ?></th><th scope="col"><?= $this->e($this->t('admin.dashboard.activity.publications')) ?></th></tr>
        </thead>
        <tbody>
            <?php foreach (array_reverse($columns) as $column): ?>
            <tr><th scope="row"><?= $this->e($column['label']) ?></th><td><?= $this->e((string) $column['edits']) ?></td><td><?= $this->e((string) $column['publications']) ?></td></tr>
            <?php endforeach ?>
        </tbody>
    </table>
</section>
