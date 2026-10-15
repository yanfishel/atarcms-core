<?php
/**
 * A library item's checkbox for the bulk actions: it joins the bulk form (form="media-bulk") from outside it.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\Media $media
 */
?>
<label class="admin-media-select" for="media-check-<?= $this->e($media->id) ?>"><input type="checkbox" id="media-check-<?= $this->e($media->id) ?>" name="ids[]" value="<?= $this->e($media->id) ?>" form="media-bulk"><span class="admin-visually-hidden"><?= $this->e($this->t('admin.media.bulk.select', ['name' => $media->originalName])) ?></span></label>
