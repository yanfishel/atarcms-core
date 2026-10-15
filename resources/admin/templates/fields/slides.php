<?php
/**
 * The slides widget (Field::slides()): one item per slide. Without JavaScript each item is a fieldset of its inputs
 * (the image number, the texts, the order and a delete box) and an empty item adds a slide; SlidesType::normalize()
 * applies the order and the deletes. With JavaScript (slides.js, .js in admin.css) the fieldsets hide behind compact
 * rows (thumbnail, title, subtitle, moves, Edit, Delete; dragged by their handle), the empty item goes, and Add/Edit
 * open the field's dialog (slides/dialog.php, after the entry form), which writes into the item's inputs: nothing
 * reaches the server before the entry's own Save. New items are cloned from the <template> with "__index__" replaced
 * by the next free index (data-next).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Field\Field $field
 * @var string $name the input name prefix, fields[{field}]
 * @var string $id
 * @var mixed $value the stored list or the submitted rows
 * @var array<int, AtarCms\Media\Media> $options the slides' images and videos that exist
 * @var bool $invalid
 * @var string $lang the lang and dir attributes of the entry's language for its texts
 */
$rows = AtarCms\Content\Field\SlidesType::rows($value);
$blank = ['image' => '', 'video' => '', 'title' => '', 'subtitle' => '', 'url' => '', 'button' => ''];
$item = fn(int|string $index, array $row, string $legend, bool $isBlank = false, bool $isNew = false): string => $this->partial('slides/item', [
    'name' => $name,
    'id' => $id,
    'index' => $index,
    'row' => $row,
    'media' => $options[(int) $row['image']] ?? null,
    'video' => $options[(int) $row['video']] ?? null,
    'legend' => $legend,
    'blank' => $isBlank,
    'new' => $isNew,
    'lang' => $lang,
]);
?>
<div class="admin-slides" id="<?= $this->e($id) ?>" data-slides="<?= $this->e($field->name) ?>" data-next="<?= $this->e(count($rows) + 1) ?>" data-max="<?= $this->e((int) ($field->max ?? AtarCms\Content\Field\SlidesType::DEFAULT_MAX)) ?>" data-untitled="<?= $this->e($this->t('admin.slides.untitled')) ?>" data-has-video="<?= $this->e($this->t('admin.slides.has_video', ['name' => '#'])) ?>"<?= $invalid ? ' aria-describedby="' . $this->e($id) . '-error"' : '' ?>>
    <p class="admin-help"><?= $this->e($this->t('admin.slides.help')) ?></p>
    <p class="admin-slides-empty" data-slides-empty<?= $rows !== [] ? ' hidden' : '' ?>><?= $this->e($this->t('admin.slides.empty')) ?></p>
    <ol class="admin-slides-list" data-slides-list aria-label="<?= $this->e($this->label($field->label)) ?>">
        <?php foreach ($rows as $index => $row): ?>
        <?= $item($index, $row, $this->t('admin.slides.slide', ['number' => $index + 1])) ?>
        <?php endforeach ?>
        <?= $item(count($rows), $blank, $this->t('admin.slides.new'), isBlank: true) ?>
    </ol>
    <template data-slides-template><?= trim($item('__index__', $blank, $this->t('admin.slides.new'), isNew: true)) ?></template>
    <button type="button" class="admin-button admin-button-secondary admin-slides-add" data-slides-add hidden><?= $this->icon('plus') ?><?= $this->e($this->t('admin.slides.add')) ?></button>
</div>
