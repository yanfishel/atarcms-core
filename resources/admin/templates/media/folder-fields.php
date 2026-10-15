<?php
/**
 * The fields of the folder form (full page and dialog): the name and the folder it sits in (the folder itself and its
 * subfolders cannot be chosen).
 *
 * @var AtarCms\View\Template $this
 * @var array{name: string, parent_id: int|null} $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var list<array{folder: AtarCms\Media\MediaFolder, depth: int}> $options
 * @var list<int> $excluded
 */
$fieldClass = static fn(string $key): string => 'admin-field' . (isset($errors[$key]) ? ' admin-field-invalid' : '');
$aria = static fn(string $key, string $id): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
?>
<div class="<?= $fieldClass('name') ?>">
    <label for="folder-name"><?= $this->icon('folder') ?><?= $this->e($this->t('admin.media.folders.name')) ?></label>
    <input id="folder-name" name="name" required maxlength="100" autofocus value="<?= $this->e($values['name']) ?>"<?= $aria('name', 'folder-name') ?>>
    <?php if (isset($errors['name'])): ?>
    <span class="admin-field-error" id="folder-name-error"><?= $this->e($this->t($errors['name'], $params['name'] ?? [])) ?></span>
    <?php endif ?>
</div>
<div class="<?= $fieldClass('parent_id') ?>">
    <label for="folder-parent"><?= $this->icon('folder-tree') ?><?= $this->e($this->t('admin.media.folders.parent')) ?></label>
    <select id="folder-parent" name="parent_id"<?= $aria('parent_id', 'folder-parent') ?>>
        <option value=""><?= $this->e($this->t('admin.media.folders.root')) ?></option>
        <?php foreach ($options as $option): ?>
        <option value="<?= $this->e($option['folder']->id) ?>"<?= $option['folder']->id === $values['parent_id'] ? ' selected' : '' ?><?= in_array($option['folder']->id, $excluded, true) ? ' disabled' : '' ?>><?= $this->e(str_repeat('— ', $option['depth'] + 1) . $option['folder']->name) ?></option>
        <?php endforeach ?>
    </select>
    <?php if (isset($errors['parent_id'])): ?>
    <span class="admin-field-error" id="folder-parent-error"><?= $this->e($this->t($errors['parent_id'], $params['parent_id'] ?? [])) ?></span>
    <?php endif ?>
</div>
