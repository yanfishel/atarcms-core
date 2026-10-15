<?php
/**
 * A media widget: the chosen files as thumbnails and a text input of their ids (the no-JS fallback, hidden by
 * media.js), plus a "Choose…" button that opens the picker dialog for users with "media.upload". Each chosen item has an
 * icon-only remove button in its corner; media.js clones the <template> copy for items picked in the dialog. The server
 * validates the ids either way.
 *
 * @var AtarCms\View\Template $this
 * @var string $name the input name
 * @var string $id the input id
 * @var mixed $value an id, a list of ids or the submitted string
 * @var array<int, AtarCms\Media\Media> $media the chosen media that exist
 * @var bool $multiple
 * @var string $kind "image", "video" or "all": what the picker lists (MediaListQuery::FIXED_KINDS)
 * @var bool $invalid (optional) the value has an error, shown under the widget as "{$id}-error"
 */
$items = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : (is_int($value) ? [$value] : []));
$ids = [];
foreach ($items as $item) {
    $item = is_int($item) ? (string) $item : (is_string($item) ? trim($item) : '');
    if (preg_match('/^[1-9]\d{0,9}$/', $item) === 1) {
        $ids[] = (int) $item;
    }
}
$text = is_string($value) ? $value : implode(',', $ids);
?>
<div class="admin-media-field" data-media-field data-multiple="<?= $multiple ? '1' : '0' ?>" data-kind="<?= $this->e($kind) ?>">
    <?php if ($multiple): ?>
    <span class="admin-help admin-media-order-help"><?= $this->e($this->t('admin.media.field.gallery_help')) ?></span>
    <?php endif ?>
    <ul class="admin-media-chosen" data-media-chosen>
        <?php foreach ($ids as $mediaId): ?>
        <?php $chosenMedia = $media[$mediaId] ?? null; ?>
        <li data-id="<?= $this->e($mediaId) ?>"<?= $chosenMedia === null ? '' : ' data-name="' . $this->e($chosenMedia->originalName) . '"' ?>>
            <?php if ($chosenMedia !== null && $kind === 'video' && $chosenMedia->isVideo()): ?>
            <?php // A video's card is its player: no thumbnail and no name (the remove button still sits in the corner). ?>
            <video class="admin-media-video" controls preload="metadata" src="<?= $this->e($this->mediaUrl($chosenMedia)) ?>"></video>
            <?php elseif ($chosenMedia !== null): ?>
            <?= $this->partial('media/thumb', ['media' => $chosenMedia]) ?>
            <span class="admin-media-name" title="<?= $this->e($chosenMedia->originalName) ?>"><?= $this->e($chosenMedia->originalName) ?></span>
            <?php else: ?>
            <span class="admin-media-name">#<?= $this->e($mediaId) ?></span>
            <?php endif ?>
            <button type="button" class="admin-media-remove" data-media-remove title="<?= $this->e($this->t('admin.media.field.remove')) ?>" aria-label="<?= $this->e($this->t('admin.media.field.remove')) ?>"><?= $this->icon('x') ?></button>
        </li>
        <?php endforeach ?>
    </ul>
    <template data-media-remove-template><button type="button" class="admin-media-remove" data-media-remove title="<?= $this->e($this->t('admin.media.field.remove')) ?>" aria-label="<?= $this->e($this->t('admin.media.field.remove')) ?>"><?= $this->icon('x') ?></button></template>
    <input class="admin-media-ids" id="<?= $this->e($id) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($text) ?>" autocomplete="off" data-media-ids<?= ($invalid ?? false) ? ' aria-invalid="true" aria-describedby="' . $this->e($id) . '-error"' : '' ?>>
    <span class="admin-help admin-media-ids-help"><?= $this->e($this->t('admin.media.field.ids_help')) ?></span>
    <?php if ($this->can('media.upload')): ?>
    <button type="button" class="admin-button admin-button-secondary admin-button-compact admin-media-choose" data-media-choose><?= $this->icon('folder-open') ?><?= $this->e($this->t('admin.media.field.choose')) ?></button>
    <?php endif ?>
</div>
