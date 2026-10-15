<?php
/**
 * The menu item form's fields, shared by the full page (menus/form.php) and the dialog (menus/dialog.php): "Inside"
 * first (the parent, like the sections' form; MenuItemsController offers only the places the item fits), then the
 * label, the target (entry, term or address; admin.css shows only the chosen one's picker with JavaScript) and "new
 * tab". A parent that is not offered (refused, gone) selects nothing, so the select shows the top level.
 *
 * @var AtarCms\View\Template $this
 * @var string $locale
 * @phpstan-import-type MenuItemFormValues from AtarCms\Admin\Menu\MenuItemFormData
 * @var MenuItemFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var array<string, array{label: string, options: array<int, string>}> $entryGroups
 * @var array<string, array{label: string, nodes: list<array{term: AtarCms\Content\Term, depth: int}>}> $termGroups
 * @var array<int, array{label: string, depth: int}> $parents item id => an "Inside" option, in tree order
 */
$error = function (string $key) use ($errors, $params): string {
    return isset($errors[$key])
        ? '<span class="admin-field-error">' . $this->e($this->t($errors[$key], $params[$key] ?? [])) . '</span>'
        : '';
};
$fieldClass = static fn(string $key): string => 'admin-field' . (isset($errors[$key]) ? ' admin-field-invalid' : '');
$targets = ['entry' => 'admin.menus.field.target_entry', 'term' => 'admin.menus.field.target_term', 'url' => 'admin.menus.field.target_url'];
?>
<div class="<?= $fieldClass('parent_id') ?>">
    <label for="parent_id"><?= $this->icon('list-tree') ?><?= $this->e($this->t('admin.menus.field.parent')) ?></label>
    <select id="parent_id" name="parent_id"<?= isset($errors['parent_id']) ? ' aria-invalid="true" aria-describedby="parent_id-error"' : '' ?>>
        <option value=""><?= $this->e($this->t('admin.menus.field.no_parent')) ?></option>
        <?php foreach ($parents as $id => $option): ?>
        <option value="<?= $this->e($id) ?>"<?= (string) $id === $values['parent_id'] ? ' selected' : '' ?>><?= $this->e(str_repeat('— ', $option['depth']) . $option['label']) ?></option>
        <?php endforeach ?>
    </select>
    <?php if (isset($errors['parent_id'])): ?>
    <span class="admin-field-error" id="parent_id-error"><?= $this->e($this->t($errors['parent_id'], $params['parent_id'] ?? [])) ?></span>
    <?php endif ?>
</div>
<div class="<?= $fieldClass('label') ?>">
    <label for="label"><?= $this->icon('type') ?><?= $this->e($this->t('admin.menus.field.label')) ?></label>
    <input id="label" name="label" maxlength="200" value="<?= $this->e($values['label']) ?>"<?= $this->langAttrs($locale) ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.menus.field.label_help')) ?></span>
    <?= $error('label') ?>
</div>
<fieldset class="admin-fieldset">
    <legend><?= $this->e($this->t('admin.menus.field.target')) ?></legend>
    <?php foreach ($targets as $type => $key): ?>
    <label class="admin-check"><input type="radio" name="target_type" value="<?= $this->e($type) ?>"<?= $values['target_type'] === $type ? ' checked' : '' ?>> <?= $this->e($this->t($key)) ?></label>
    <?php endforeach ?>
    <?= $error('target_type') ?>
    <div class="<?= $fieldClass('entry_id') ?>" data-target="entry">
        <label for="entry_id"><?= $this->icon('file-text') ?><?= $this->e($this->t('admin.menus.field.entry')) ?></label>
        <select id="entry_id" name="entry_id">
            <option value=""><?= $this->e($this->t('admin.menus.field.choose')) ?></option>
            <?php foreach ($entryGroups as $group): ?>
            <optgroup label="<?= $this->e($this->label($group['label'])) ?>">
                <?php foreach ($group['options'] as $id => $title): ?>
                <option value="<?= $this->e($id) ?>"<?= (string) $id === $values['entry_id'] ? ' selected' : '' ?>><?= $this->e($title) ?></option>
                <?php endforeach ?>
            </optgroup>
            <?php endforeach ?>
        </select>
        <?= $error('entry_id') ?>
    </div>
    <div class="<?= $fieldClass('term_id') ?>" data-target="term">
        <label for="term_id"><?= $this->icon('tag') ?><?= $this->e($this->t('admin.menus.field.term')) ?></label>
        <select id="term_id" name="term_id">
            <option value=""><?= $this->e($this->t('admin.menus.field.choose')) ?></option>
            <?php foreach ($termGroups as $group): ?>
            <optgroup label="<?= $this->e($this->label($group['label'])) ?>">
                <?php foreach ($group['nodes'] as $node): ?>
                <option value="<?= $this->e($node['term']->id) ?>"<?= (string) $node['term']->id === $values['term_id'] ? ' selected' : '' ?>><?= $this->e(str_repeat('— ', $node['depth']) . $node['term']->name) ?></option>
                <?php endforeach ?>
            </optgroup>
            <?php endforeach ?>
        </select>
        <?= $error('term_id') ?>
    </div>
    <div class="<?= $fieldClass('url') ?>" data-target="url">
        <label for="url"><?= $this->icon('link') ?><?= $this->e($this->t('admin.menus.field.url')) ?></label>
        <input id="url" name="url" maxlength="2048" dir="ltr" value="<?= $this->e($values['url']) ?>">
        <span class="admin-help"><?= $this->e($this->t('admin.menus.field.url_help')) ?></span>
        <?= $error('url') ?>
    </div>
</fieldset>
<label class="admin-check"><input type="checkbox" name="new_tab" value="1"<?= $values['new_tab'] ? ' checked' : '' ?>> <?= $this->e($this->t('admin.menus.field.new_tab')) ?></label>
