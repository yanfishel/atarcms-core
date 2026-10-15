<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Admin\Dashboard\Dashboard $dashboard */
// Each block shows only for whom it applies (DashboardData); the rows below adapt to the blocks a user gets.
$rtl = AtarCms\I18n\TextDirection::of($this->locale()) === 'rtl';
$this->layout('layouts/admin', ['title' => $this->t('admin.dashboard.title'), 'scripts' => ['tabs.js']]);
$charts = $dashboard->activity !== null;
$content = $dashboard->statuses !== [];
$cards = (int) ($dashboard->translations !== null) + (int) ($dashboard->tags !== null) + (int) ($dashboard->messages !== null);
?>
<div class="admin-dash">
<?php if ($dashboard->hidden): ?>
<div class="admin-alert admin-alert-warning" role="status"><?= $this->icon('eye') ?><p><?= $this->e($this->t('admin.dashboard.hidden')) ?> <a href="<?= $this->e($this->url('admin.settings')) ?>"><?= $this->e($this->t('admin.dashboard.hidden_link')) ?></a></p></div>
<?php endif ?>
<header class="admin-dash-header">
    <div class="admin-dash-hello">
        <h1><?= $this->e($this->t('admin.dashboard.welcome', ['name' => $dashboard->user->displayName()])) ?></h1>
        <p class="admin-dash-date"><?= $this->e($dashboard->today) ?></p>
    </div>
    <?php if ($dashboard->quick !== []): ?>
    <nav class="admin-dash-quick" aria-label="<?= $this->e($this->t('admin.dashboard.quick.title')) ?>">
        <ul class="admin-dash-quick-list">
            <?php foreach ($dashboard->quick as $collection): ?>
            <?php $label = $this->label($collection->label); ?>
            <li><a class="admin-dash-quick-link admin-tone-<?= $this->e((string) ($dashboard->tones[$collection->name] ?? 8)) ?>" href="<?= $this->e($collection->single ? $this->url('admin.entries', ['collection' => $collection->name]) : $this->url('admin.entries.new', ['collection' => $collection->name])) ?>" aria-label="<?= $this->e($this->t($collection->single ? 'admin.dashboard.quick.edit' : 'admin.dashboard.quick.add', ['name' => $label])) ?>"><span class="admin-dash-quick-icon"><?= $this->icon($collection->single ? 'pencil' : 'plus') ?></span><?= $this->e($label) ?></a></li>
            <?php endforeach ?>
        </ul>
    </nav>
    <?php endif ?>
</header>
<?= $this->partial('dashboard/tiles', ['dashboard' => $dashboard, 'rtl' => $rtl]) ?>
<?php if ($charts): ?>
<div class="admin-dash-split<?= $content ? ' admin-dash-split-two' : '' ?>">
    <?= $this->partial('dashboard/activity', ['dashboard' => $dashboard, 'rtl' => $rtl]) ?>
    <?php if ($content): ?>
    <?= $this->partial('dashboard/content', ['dashboard' => $dashboard, 'rtl' => $rtl]) ?>
    <?php endif ?>
</div>
<?php endif ?>
<?php if ($cards > 0): ?>
<div class="admin-dash-cards">
    <?php if ($dashboard->translations !== null): ?>
    <?= $this->partial('dashboard/translations', ['translations' => $dashboard->translations, 'rtl' => $rtl]) ?>
    <?php endif ?>
    <?php if ($dashboard->tags !== null): ?>
    <?= $this->partial('dashboard/tags', ['tags' => $dashboard->tags, 'rtl' => $rtl]) ?>
    <?php endif ?>
    <?php if ($dashboard->messages !== null): ?>
    <?= $this->partial('dashboard/messages', ['messages' => $dashboard->messages['latest']]) ?>
    <?php endif ?>
</div>
<?php endif ?>
<div class="admin-dash-split<?= $dashboard->feed !== null ? ' admin-dash-split-two' : '' ?>">
    <?= $this->partial('dashboard/progress', ['dashboard' => $dashboard]) ?>
    <?php if ($dashboard->feed !== null): ?>
    <?= $this->partial('dashboard/feed', ['feed' => $dashboard->feed]) ?>
    <?php endif ?>
</div>
</div>
