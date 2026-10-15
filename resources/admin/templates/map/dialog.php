<?php
/**
 * The map form as the dialog's content (Dialog, dialog.js; ContactMapController): a tab per way to enter the map
 * (tabs.js; the empty data-tabs key opens the tab the server chose: the way the map was entered, or the refused one),
 * each panel its own form, and the footer's "Save map" of the open tab (data-tab-for: tabs.js shows the open tab's
 * button only). A refused form comes back here with its errors; a saved map replaces the entry form's map column and
 * closes the dialog. The dialog exists only with JavaScript, so the closed panels are hidden from the start.
 *
 * @var AtarCms\View\Template $this
 * @var string $method the open tab
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 */
$methods = AtarCms\Admin\Content\ContactMapController::METHODS;
$action = $this->url('admin.map.save');
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($this->t('admin.map.title')) ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<div class="admin-dialog-form" data-tabs="">
    <div class="admin-dialog-body">
        <?php if ($errors !== []): ?>
        <div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($errors)])) ?></p></div>
        <?php endif ?>
        <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($this->t('admin.map.ways')) ?>">
            <?php foreach ($methods as $tab): ?>
            <?php $count = $tab === $method ? count($errors) : 0; ?>
            <button type="button" class="admin-tab" role="tab" id="tab-map-<?= $this->e($tab) ?>" aria-controls="panel-map-<?= $this->e($tab) ?>" aria-selected="<?= $tab === $method ? 'true' : 'false' ?>" tabindex="<?= $tab === $method ? '0' : '-1' ?>" data-tab="map-<?= $this->e($tab) ?>" data-errors="<?= $this->e($count) ?>">
                <?= $this->e($this->t('admin.map.tab.' . $tab)) ?>
                <?php if ($count > 0): ?>
                <span class="admin-tab-badge" aria-hidden="true"><?= $this->e($count) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.tab_errors', ['count' => $count])) ?></span>
                <?php endif ?>
            </button>
            <?php endforeach ?>
        </div>
        <?php foreach ($methods as $tab): ?>
        <section class="admin-tab-panel" role="tabpanel" id="panel-map-<?= $this->e($tab) ?>" aria-labelledby="tab-map-<?= $this->e($tab) ?>"<?= $tab === $method ? '' : ' hidden' ?>>
            <form class="admin-map-form" id="map-form-<?= $this->e($tab) ?>" method="post" action="<?= $this->e($action) ?>" hx-post="<?= $this->e($action) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
                <?= $this->csrfField() ?>
                <input type="hidden" name="method" value="<?= $this->e($tab) ?>">
                <?= $this->partial('map/fields', [
                    'method' => $tab,
                    'values' => $tab === $method ? $values : [],
                    'errors' => $tab === $method ? $errors : [],
                    'params' => $tab === $method ? $params : [],
                    'autofocus' => $tab === $method,
                ]) ?>
            </form>
        </section>
        <?php endforeach ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close><?= $this->e($this->t('admin.map.cancel')) ?></button>
        <?php foreach ($methods as $tab): ?>
        <button type="submit" class="admin-button admin-button-primary" form="map-form-<?= $this->e($tab) ?>" data-tab-for="map-<?= $this->e($tab) ?>"<?= $tab === $method ? '' : ' hidden' ?>><?= $this->icon('save') ?><?= $this->e($this->t('admin.map.save')) ?></button>
        <?php endforeach ?>
    </div>
</div>
