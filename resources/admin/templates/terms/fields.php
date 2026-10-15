<?php
/**
 * The term form's inputs (for a hierarchical taxonomy the parent first, then name and address), each with its error
 * line. There is no order input: a new term goes last among its siblings, and the order screens (drag and drop, the
 * arrows) move it. In the dialog ($dialog) the name takes the focus.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @phpstan-import-type TermFormValues from AtarCms\Admin\Taxonomy\TermFormData
 * @var TermFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var list<array{term: AtarCms\Content\Term, depth: int}> $parents
 * @var bool $dialog
 * @var string $locale the term's language (the name carries its lang and dir)
 */
$error = function (string $key) use ($errors, $params): string {
    return isset($errors[$key])
        ? '<span class="admin-field-error" id="' . $this->e($key) . '-error">' . $this->e($this->t($errors[$key], $params[$key] ?? [])) . '</span>'
        : '';
};
$invalid = static fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-error"' : '';
$fieldClass = static fn(string $key): string => 'admin-field' . (isset($errors[$key]) ? ' admin-field-invalid' : '');
?>
<?php if ($taxonomy->hierarchical): ?>
<div class="<?= $fieldClass('parent_id') ?>">
    <label for="parent_id"><?= $this->icon('folder-tree') ?><?= $this->e($this->t('admin.terms.field.parent')) ?></label>
    <select id="parent_id" name="parent_id"<?= $invalid('parent_id') ?>>
        <option value=""><?= $this->e($this->t('admin.terms.field.no_parent')) ?></option>
        <?php foreach ($parents as $node): ?>
        <option value="<?= $this->e($node['term']->id) ?>"<?= (string) $node['term']->id === $values['parent_id'] ? ' selected' : '' ?>><?= $this->e(str_repeat('— ', $node['depth']) . $node['term']->name) ?></option>
        <?php endforeach ?>
    </select>
    <?= $error('parent_id') ?>
</div>
<?php endif ?>
<div class="<?= $fieldClass('name') ?>">
    <label for="name"><?= $this->icon('type') ?><?= $this->e($this->t('admin.terms.field.name')) ?> *</label>
    <input id="name" name="name" required maxlength="200" value="<?= $this->e($values['name']) ?>"<?= $dialog ? ' autofocus' : '' ?><?= $invalid('name') ?><?= $this->langAttrs($locale) ?>>
    <?= $error('name') ?>
</div>
<div class="<?= $fieldClass('slug') ?>">
    <label for="slug"><?= $this->icon('link') ?><?= $this->e($this->t('admin.terms.field.slug')) ?></label>
    <input id="slug" name="slug" maxlength="190" dir="ltr" value="<?= $this->e($values['slug']) ?>"<?= $invalid('slug') ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.terms.field.slug_help')) ?></span>
    <?= $error('slug') ?>
</div>
