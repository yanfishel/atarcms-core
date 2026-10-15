<?php
/**
 * Popular tags of the content language: the top ones by the number of viewable entries carrying them, as bars on the
 * scale of the first.
 *
 * @var AtarCms\View\Template $this
 * @var list<array{name: string, count: int}> $tags
 * @var bool $rtl
 */
$scale = max(1, $tags[0]['count'] ?? 1);
?>
<section class="admin-card admin-dash-card" id="dashboard-tags" aria-labelledby="dashboard-tags-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-tags-title"><?= $this->icon('tags') ?><?= $this->e($this->t('admin.dashboard.tags.title')) ?></h2>
    </div>
    <?php if ($tags === []): ?>
    <p class="admin-dash-none"><?= $this->e($this->t('admin.dashboard.tags.empty')) ?></p>
    <?php else: ?>
    <ul class="admin-dash-meters admin-tone-2">
        <?php foreach ($tags as $tag): ?>
        <li class="admin-dash-meter">
            <span class="admin-dash-meter-label">
                <span class="admin-dash-meter-name"<?= $this->langAttrs($this->contentLocale()) ?>><?= $this->e($tag['name']) ?></span>
            </span>
            <span class="admin-dash-meter-value" title="<?= $this->e($this->t('admin.dashboard.tags.count', ['count' => $tag['count']])) ?>"><?= $this->e((string) $tag['count']) ?></span>
            <?= $this->partial('dashboard/meter', ['value' => $tag['count'], 'scale' => $scale, 'rtl' => $rtl]) ?>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
</section>
