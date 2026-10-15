<?php
/** @var AtarCms\View\Template $this */
/** @var string $location */
/** @var string $locationLabel */
/** @var AtarCms\Content\Menu\MenuItem|null $item */
/** @var string|null $parentLabel the label of the item a new one goes inside ("Add inside"), else null */
/** @var string $locale */
/** @phpstan-import-type MenuItemFormValues from AtarCms\Admin\Menu\MenuItemFormData */
/** @var MenuItemFormValues $values */
/** @var array<string, string> $errors */
/** @var array<string, array<string, int|float|string>> $params */
/** @var string $version */
/** @var array<string, array{label: string, options: array<int, string>}> $entryGroups */
/** @var array<string, array{label: string, nodes: list<array{term: AtarCms\Content\Term, depth: int}>}> $termGroups */
/** @var array<int, array{label: string, depth: int}> $parents */
$isNew = $item === null;
$crumb = match (true) {
    !$isNew => $this->t('admin.menus.edit'),
    $parentLabel !== null => $this->t('admin.menus.add_inside', ['label' => $parentLabel]),
    default => $this->t('admin.menus.new'),
};
$heading = $crumb . ' · ' . $this->label($locationLabel);
$action = $isNew
    ? $this->url('admin.menus.store', ['location' => $location])
    : $this->url('admin.menus.update', ['location' => $location, 'id' => $item->id]);
// Errors without a field (a stale or gone item, an unsupported language) are toasts; the summary counts the rest.
[$fieldErrors, $toasts] = AtarCms\Admin\FormErrors::split($errors, $params, AtarCms\Admin\Menu\MenuItemForm::FIELDS);
$this->layout('layouts/admin', [
    'title' => $heading,
    'toasts' => $toasts,
    'contentLocale' => $locale,
    'breadcrumbs' => [
        [$this->t('admin.menus.title')],
        [$this->label($locationLabel), $this->url('admin.menus.show', ['location' => $location, 'locale' => $locale])],
        [$crumb],
    ],
]);
?>
<?= $this->partial('partials/page-header', ['title' => $heading, 'locale' => $locale]) ?>
<?php if ($fieldErrors !== []): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => count($fieldErrors)])) ?></p></div>
<?php endif ?>
<form class="admin-form-card" method="post" action="<?= $this->e($action) ?>">
    <?= $this->csrfField() ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>">
    <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
    <div class="admin-card admin-form">
        <?= $this->partial('menus/fields', ['locale' => $locale, 'values' => $values, 'errors' => $errors, 'params' => $params, 'entryGroups' => $entryGroups, 'termGroups' => $termGroups, 'parents' => $parents]) ?>
    </div>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <?php if (!$isNew): ?>
            <a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url('admin.menus.delete.confirm', ['location' => $location, 'id' => $item->id])) ?>"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.menus.delete')) ?></a>
            <?php endif ?>
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($isNew ? $this->t('admin.menus.create') : $this->t('admin.menus.save')) ?></button>
        </div>
    </div>
</form>
