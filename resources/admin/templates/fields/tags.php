<?php
/**
 * A tags field (Plan 35). The text input carries the posted value, comma-separated names: without JavaScript it is
 * the whole widget. Under .js it is hidden and the tag box shows instead, rendered here with a pill per chosen tag so
 * the first paint is final: tags.js keeps the input equal to the pills, offers the taxonomy's tags of the entry's
 * language (data-tags-names, from $options) and builds pills and options only from the templates below.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Field\Field $field
 * @var string $name
 * @var string $id
 * @var mixed $value
 * @var array<int|string, string> $options the existing tag names, by name
 * @var bool $invalid
 * @var string $aria
 * @var string $lang
 */
$text = is_string($value) ? $value : (is_array($value) ? implode(', ', array_filter($value, 'is_string')) : '');
$chosen = AtarCms\Content\Field\TagsType::names($value);
$existing = array_values(array_unique(array_filter($options, 'is_string')));
$describedBy = $id . '-help' . ($field->max !== null ? ' ' . $id . '-full' : '') . ($invalid ? ' ' . $id . '-error' : '');
$help = $this->t('admin.tags.help') . ($field->max !== null ? ' ' . $this->t('admin.tags.max', ['max' => $field->max]) : '');
// The texts tags.js shows, with "#" for the name.
$texts = '';
foreach ([
    'remove' => 'admin.tags.remove',
    'add' => 'admin.tags.add',
    'added' => 'admin.tags.added',
    'removed' => 'admin.tags.removed',
    'duplicate' => 'admin.tags.duplicate',
] as $attribute => $key) {
    $texts .= ' data-' . $attribute . '="' . $this->e($this->t($key, ['name' => '#'])) . '"';
}
?>
<input id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($text) ?>" autocomplete="off" class="admin-tags-value" data-tags-value<?= $field->required ? ' required' : '' ?><?= $aria ?><?= $lang ?>>
<span class="admin-help admin-tags-help-plain"><?= $this->e($this->t('admin.entries.field.tags_help')) ?></span>
<div class="admin-tags" data-tags data-tags-names="<?= $this->e(json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"<?= $field->max !== null ? ' data-max="' . $this->e($field->max) . '"' : '' ?><?= $field->required ? ' data-tags-required' : '' ?><?= $texts ?> data-full="<?= $this->e($this->t('admin.tags.full')) ?>"<?= $lang ?>>
    <div class="admin-tags-box" data-tags-box>
        <ul class="admin-tags-list" aria-label="<?= $this->e($this->t('admin.tags.chosen')) ?>" data-tags-list><?php foreach ($chosen as $tag): ?><?= trim($this->partial('tags/pill', ['name' => $tag])) ?><?php endforeach ?></ul>
        <input class="admin-tags-query" id="<?= $this->e($id) ?>-query" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?= $this->e($id) ?>-listbox" autocomplete="off" maxlength="<?= AtarCms\Content\TermService::MAX_NAME ?>" aria-describedby="<?= $this->e($describedBy) ?>"<?= $invalid ? ' aria-invalid="true"' : '' ?> data-tags-query>
        <ul class="admin-tags-listbox" id="<?= $this->e($id) ?>-listbox" role="listbox" aria-label="<?= $this->e($this->t('admin.tags.suggestions')) ?>" hidden data-tags-listbox></ul>
    </div>
    <span class="admin-visually-hidden" role="status" data-tags-status></span>
    <span class="admin-help" id="<?= $this->e($id) ?>-help"><?= $this->e($help) ?></span>
    <?php if ($field->max !== null): ?>
    <?php // Empty until the box is full (tags.js fills it from data-full): the query names it in aria-describedby, and
    // a referenced element counts with its text even while hidden. ?>
    <span class="admin-help admin-tags-full" id="<?= $this->e($id) ?>-full" data-tags-full hidden></span>
    <?php endif ?>
    <template data-tags-pill><?= trim($this->partial('tags/pill', ['name' => ''])) ?></template>
    <template data-tags-option><li class="admin-tags-option" role="option"><span class="admin-tags-option-text" data-tags-text></span></li></template>
    <template data-tags-create><li class="admin-tags-option admin-tags-create" role="option"><?= $this->icon('plus') ?><span class="admin-tags-option-text" data-tags-text></span></li></template>
</div>
