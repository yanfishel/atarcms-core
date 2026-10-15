<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Field\Field $field */
/** @var mixed $value */
/** @var string|null $error */
/** @var array<string, int|float|string> $errorParams */
/** @var array<int|string, string> $options */
/** @var list<int> $kept (optional) */
/** @var string|null $locale (optional) the language of the entry: text widgets carry its lang and dir */
?>
<?php // A rich-text field's help sits on the left of the editor's word count (admin.css: .admin-field-rich-text). ?>
<div class="admin-field<?= $field->type instanceof AtarCms\Content\Field\RichTextType ? ' admin-field-rich-text' : '' ?><?= $field->tall ? ' admin-field-tall' : '' ?><?= $error !== null ? ' admin-field-invalid' : '' ?>">
    <?php // A boolean is its own label; a slides field is named by its tab and its list. ?>
    <?php if (!in_array($field->type->name(), ['boolean', 'slides'], true)): ?>
    <label for="field-<?= $this->e($field->name) ?>"><?= $this->icon(AtarCms\Admin\Content\FieldIcons::of($field)) ?><?= $this->e($this->label($field->label)) ?><?= $field->required ? ' *' : '' ?></label>
    <?php endif ?>
    <?= $this->widget($field, $value, $options, $kept ?? [], $error !== null, $locale ?? null) ?>
    <?php if ($field->help !== null): ?>
    <span class="admin-help admin-field-help"><?= $this->e($this->label($field->help)) ?></span>
    <?php endif ?>
    <?php if ($error !== null): ?>
    <span class="admin-field-error" id="field-<?= $this->e($field->name) ?>-error"><?= $this->e($this->t($error, $errorParams)) ?></span>
    <?php endif ?>
</div>
