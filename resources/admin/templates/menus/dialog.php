<?php
/**
 * The menu item form as the dialog's content (Dialog, dialog.js): the header (title with the language pill, close),
 * the form's fields as the scrolling body and the footer with the buttons. The form posts through htmx: its answer
 * replaces this fragment (field errors) or reloads the menu screen (saved, or refused without a field). Cancel closes
 * the dialog.
 *
 * @var AtarCms\View\Template $this
 * @var string $location
 * @var AtarCms\Content\Menu\MenuItem|null $item
 * @var string $label the item's label or its target's title ('' for a new item)
 * @var string|null $parentLabel the label of the item a new one goes inside ("Add inside"), else null
 * @var string $locale
 * @phpstan-import-type MenuItemFormValues from AtarCms\Admin\Menu\MenuItemFormData
 * @var MenuItemFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $version
 * @var array<string, array{label: string, options: array<int, string>}> $entryGroups
 * @var array<string, array{label: string, nodes: list<array{term: AtarCms\Content\Term, depth: int}>}> $termGroups
 * @var array<int, array{label: string, depth: int}> $parents
 */
$isNew = $item === null;
$heading = match (true) {
    !$isNew => $label,
    $parentLabel !== null => $this->t('admin.menus.add_inside', ['label' => $parentLabel]),
    default => $this->t('admin.menus.new'),
};
$action = $isNew
    ? $this->url('admin.menus.store', ['location' => $location])
    : $this->url('admin.menus.update', ['location' => $location, 'id' => $item->id]);
// Only field errors reach this fragment: for a refusal without a field (stale, locale) MenuItemsController::form()
// answers HX-Redirect to the menu screen, with the reason as an error toast, before rendering it.
[$fieldErrors] = AtarCms\Admin\FormErrors::split($errors, $params, AtarCms\Admin\Menu\MenuItemForm::FIELDS);
$pill = trim($this->partial('partials/locale-badge', ['locale' => $locale]));
?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($heading) ?><?= $pill === '' ? '' : ' ' . $pill ?></h2>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($action) ?>" hx-post="<?= $this->e($action) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <?= $this->partial('terms/alert', ['errors' => $fieldErrors]) ?>
        <?= $this->csrfField() ?>
        <input type="hidden" name="version" value="<?= $this->e($version) ?>">
        <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
        <?= $this->partial('menus/fields', ['locale' => $locale, 'values' => $values, 'errors' => $errors, 'params' => $params, 'entryGroups' => $entryGroups, 'termGroups' => $termGroups, 'parents' => $parents]) ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close><?= $this->e($this->t('admin.menus.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($isNew ? $this->t('admin.menus.create') : $this->t('admin.menus.save')) ?></button>
    </div>
</form>
