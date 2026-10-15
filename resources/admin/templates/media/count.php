<?php
/**
 * The results' count line, with "Select all" / "Clear selection" at its right edge: rendered hidden, media.js shows
 * them (the library, a gallery's picker) and keeps them enabled only when they would change something.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Admin\Media\MediaListQuery $list
 * @var int $total
 */
?>
<div class="admin-media-count-row">
    <p class="admin-muted admin-list-count"><?= $this->e($this->t($list->searchesEverywhere() ? 'admin.media.count_everywhere' : 'admin.media.count', ['count' => $total])) ?></p>
    <?php if ($total > 0): ?>
    <span class="admin-media-select-actions">
        <button type="button" class="admin-button admin-button-link admin-button-small" hidden data-select-all><?= $this->e($this->t('admin.media.select_all')) ?></button>
        <button type="button" class="admin-button admin-button-link admin-button-small" hidden data-select-none><?= $this->e($this->t('admin.media.select_none')) ?></button>
    </span>
    <?php endif ?>
</div>
