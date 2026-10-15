<?php
/**
 * The site settings (SettingsForm): the content language comes from the top bar's switch; on the left the fields in
 * three tabs (the contact details are on the contact page's form, SettingsForm::contactFields()) (tabs.js, remembered under "admin.tabs.settings"; without JavaScript every panel shows under its heading), on the
 * right one card with the images (the media widgets). Fields kept per language carry the language's badge; one Save
 * stores the site-wide values and those of the language shown.
 *
 * @var AtarCms\View\Template $this
 * @var string $locale
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var array<int, AtarCms\Media\Media> $images the chosen images that exist
 */

$title = $this->t('admin.settings.title');
[$fieldErrors, $toasts] = AtarCms\Admin\FormErrors::split($errors, $params, array_keys(AtarCms\Admin\Settings\SettingsForm::fields()));
$tabErrors = AtarCms\Admin\Settings\SettingsForm::tabErrors($fieldErrors);
$open = 0;
foreach (array_values($tabErrors) as $index => $count) {
    if ($count > 0) {
        $open = $index;
        break;
    }
}
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'scripts' => ['tabs.js', 'media.js'], 'toasts' => $toasts, 'contentLocale' => $locale]);
$fields = function (string $tab) use ($values, $fieldErrors, $params, $images, $locale): string {
    $html = '';
    foreach (AtarCms\Admin\Settings\SettingsForm::tab($tab) as $name => $field) {
        $html .= $this->partial('settings/field', ['name' => $name, 'field' => $field, 'values' => $values, 'errors' => $fieldErrors, 'params' => $params, 'images' => $images, 'locale' => $locale]);
    }

    return $html;
};
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<?php if ($fieldErrors !== []): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($fieldErrors)])) ?></p></div>
<?php endif ?>
<form class="admin-entry-form" method="post" action="<?= $this->e($this->url('admin.settings.save')) ?>">
    <?= $this->csrfField() ?>
    <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
    <div class="admin-form-layout">
        <div class="admin-form-column" data-tabs="admin.tabs.settings">
            <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($this->t('admin.settings.tabs')) ?>">
                <?php foreach (AtarCms\Admin\Settings\SettingsForm::TABS as $index => $tab): ?>
                <?php $count = $tabErrors[$tab]; ?>
                <button type="button" class="admin-tab" role="tab" id="tab-<?= $this->e($tab) ?>" aria-controls="panel-<?= $this->e($tab) ?>" aria-selected="<?= $index === $open ? 'true' : 'false' ?>" tabindex="<?= $index === $open ? '0' : '-1' ?>" data-tab="<?= $this->e($tab) ?>" data-errors="<?= $this->e($count) ?>">
                    <?= $this->e($this->t('admin.form.tab.' . $tab)) ?>
                    <?php if ($count > 0): ?>
                    <span class="admin-tab-badge" aria-hidden="true"><?= $this->e($count) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.tab_errors', ['count' => $count])) ?></span>
                    <?php endif ?>
                </button>
                <?php endforeach ?>
            </div>
            <?php foreach (AtarCms\Admin\Settings\SettingsForm::TABS as $index => $tab): ?>
            <section class="admin-tab-panel admin-card<?= $index === $open ? ' admin-tab-panel-open' : '' ?>" role="tabpanel" id="panel-<?= $this->e($tab) ?>" aria-labelledby="tab-<?= $this->e($tab) ?>">
                <h2 class="admin-tab-heading"><?= $this->e($this->t('admin.form.tab.' . $tab)) ?></h2>
                <?= $fields($tab) ?>
            </section>
            <?php endforeach ?>
        </div>
        <div class="admin-form-column">
            <section class="admin-card admin-form">
                <h2 class="admin-card-heading"><?= $this->e($this->t('admin.settings.images')) ?></h2>
                <?= $fields(AtarCms\Admin\Settings\SettingsForm::IMAGES) ?>
            </section>
        </div>
    </div>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($this->t('admin.settings.save')) ?></button>
        </div>
    </div>
</form>
<?php if ($this->can('media.upload')): ?>
<?= $this->partial('media/dialog') ?>
<?php endif ?>
