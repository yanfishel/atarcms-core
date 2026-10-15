<?php
/**
 * The dialog of a slides field (slides.js opens it for Add slide and Edit), in the shared dialog layout. It follows
 * the entry form, since its close button is a form of its own, and its inputs have no names: Done copies them into
 * the widget's item, Cancel and Close drop them. The image and the optional video are media widgets of media/field.php
 * (kinds "image" and "video"; media.js opens the picker above this dialog). The title switches between data-title-add
 * and data-title-edit.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Field\Field $field
 * @var string $locale the entry's language: the texts carry its lang and dir
 */
$prefix = 'slides-' . $field->name;
$lang = $this->langAttrs($locale);
?>
<dialog class="admin-dialog admin-slides-dialog" data-slides-dialog="<?= $this->e($field->name) ?>" aria-labelledby="<?= $this->e($prefix) ?>-heading" data-title-add="<?= $this->e($this->t('admin.slides.add')) ?>" data-title-edit="<?= $this->e($this->t('admin.slides.edit')) ?>">
    <div class="admin-dialog-header">
        <h2 id="<?= $this->e($prefix) ?>-heading"><?= $this->e($this->t('admin.slides.add')) ?></h2>
        <?= $this->partial('partials/dialog-close') ?>
    </div>
    <div class="admin-dialog-body">
        <div class="admin-field" data-slides-media="image">
            <label for="<?= $this->e($prefix) ?>-image"><?= $this->icon('image') ?><?= $this->e($this->t('admin.slides.image')) ?> *</label>
            <?= $this->partial('media/field', ['name' => '', 'id' => $prefix . '-image', 'value' => null, 'media' => [], 'multiple' => false, 'kind' => 'image']) ?>
            <span class="admin-field-error" id="<?= $this->e($prefix) ?>-image-error" data-slides-image-error hidden><?= $this->e($this->t('admin.slides.image_required')) ?></span>
        </div>
        <div class="admin-field" data-slides-media="video">
            <label for="<?= $this->e($prefix) ?>-video"><?= $this->icon('video') ?><?= $this->e($this->t('admin.slides.video')) ?></label>
            <?= $this->partial('media/field', ['name' => '', 'id' => $prefix . '-video', 'value' => null, 'media' => [], 'multiple' => false, 'kind' => 'video']) ?>
            <span class="admin-help" id="<?= $this->e($prefix) ?>-video-help"><?= $this->e($this->t('admin.slides.video_help')) ?></span>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($prefix) ?>-title"><?= $this->icon('type') ?><?= $this->e($this->t('admin.slides.title')) ?></label>
            <input id="<?= $this->e($prefix) ?>-title" maxlength="255" data-slides-field="title"<?= $lang ?>>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($prefix) ?>-subtitle"><?= $this->icon('align-left') ?><?= $this->e($this->t('admin.slides.subtitle')) ?></label>
            <input id="<?= $this->e($prefix) ?>-subtitle" maxlength="500" data-slides-field="subtitle"<?= $lang ?>>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($prefix) ?>-url"><?= $this->icon('link') ?><?= $this->e($this->t('admin.slides.url')) ?></label>
            <input id="<?= $this->e($prefix) ?>-url" maxlength="255" dir="ltr" autocomplete="off" aria-describedby="<?= $this->e($prefix) ?>-url-help" data-slides-field="url">
            <span class="admin-help" id="<?= $this->e($prefix) ?>-url-help"><?= $this->e($this->t('admin.slides.url_help')) ?></span>
        </div>
        <div class="admin-field">
            <label for="<?= $this->e($prefix) ?>-button"><?= $this->icon('type') ?><?= $this->e($this->t('admin.slides.button')) ?></label>
            <input id="<?= $this->e($prefix) ?>-button" maxlength="100" aria-describedby="<?= $this->e($prefix) ?>-button-help" data-slides-field="button"<?= $lang ?>>
            <span class="admin-help" id="<?= $this->e($prefix) ?>-button-help"><?= $this->e($this->t('admin.slides.button_help')) ?></span>
        </div>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-slides-cancel><?= $this->e($this->t('admin.slides.cancel')) ?></button>
        <button type="button" class="admin-button admin-button-primary" data-slides-done><?= $this->e($this->t('admin.slides.done')) ?></button>
    </div>
</dialog>
