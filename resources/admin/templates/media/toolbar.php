<?php
/**
 * The toolbar of the library and the picker: one GET form. First row: the folder's path, then the view and order
 * dropdowns. Second row: the search box with its scope dropdown inside on the right, the type dropdown (shown fixed to
 * images or videos for an image-only or video-only picker) and the "Unused" toggle. With JavaScript (media.js) any change and a search of 3+ characters (or an emptied one) submit the
 * form through htmx, which swaps only the results ($results) so typing keeps the focus; without it the submit button
 * stays. The scope applies only to a search (MediaListQuery::searchesEverywhere()).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Media\MediaFolderTree $tree
 * @var AtarCms\Admin\Media\MediaListQuery $list
 * @var bool $picker
 * @var string $action the form's URL
 * @var string $results the selector of the results element the form swaps
 * @var callable(int|null): string $folderUrl a folder => its URL
 * @var string|null $target (optional) the hx-target of the path's links (the picker)
 */
$target ??= null;
$prefix = $picker ? 'picker' : 'media';
$keep = array_intersect_key($list->params($picker), array_flip(['folder', 'kind', 'locale']));
if (!$picker) {
    $keep['folder'] = $list->folder === null ? '' : (string) $list->folder;
}
$views = [
    ['value' => 'grid', 'label' => $this->t('admin.media.view.grid'), 'icon' => 'layout-grid'],
    ['value' => 'list', 'label' => $this->t('admin.media.view.list'), 'icon' => 'list'],
];
$sorts = [
    ['value' => 'newest', 'label' => $this->t('admin.media.sort.newest'), 'icon' => 'calendar-arrow-down'],
    ['value' => 'oldest', 'label' => $this->t('admin.media.sort.oldest'), 'icon' => 'calendar-arrow-up'],
    ['value' => 'name', 'label' => $this->t('admin.media.sort.name'), 'icon' => 'arrow-down-a-z'],
    ['value' => 'size', 'label' => $this->t('admin.media.sort.size'), 'icon' => 'arrow-down-wide-narrow'],
];
$types = [
    ['value' => 'all', 'label' => $this->t('admin.media.type.all'), 'icon' => 'files'],
    ['value' => 'image', 'label' => $this->t('admin.media.type.image'), 'icon' => 'image'],
    ['value' => 'video', 'label' => $this->t('admin.media.type.video'), 'icon' => 'video'],
    ['value' => 'document', 'label' => $this->t('admin.media.type.document'), 'icon' => 'file-text'],
];
$scopes = [
    ['value' => '', 'label' => $this->t('admin.media.scope.folder'), 'icon' => null],
    ['value' => '1', 'label' => $this->t('admin.media.scope.everywhere'), 'icon' => null],
];
?>
<form id="<?= $prefix ?>-filters" class="admin-media-toolbar" method="get" action="<?= $this->e($action) ?>" hx-get="<?= $this->e($action) ?>" hx-target="<?= $this->e($results) ?>" hx-select="<?= $this->e($results) ?>" hx-swap="outerHTML"<?= $picker ? '' : ' hx-push-url="true"' ?> role="search" data-media-filters>
    <?php foreach ($keep as $name => $value): ?>
    <input type="hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>">
    <?php endforeach ?>
    <div class="admin-media-toolbar-row">
        <?= $this->partial('media/trail', ['tree' => $tree, 'current' => $list->folder, 'url' => $folderUrl, 'target' => $target]) ?>
        <div class="admin-media-toolbar-end">
            <?= $this->partial('media/dropdown', ['name' => 'view', 'label' => $this->t('admin.media.view.label'), 'options' => $views, 'current' => $list->view, 'show' => 'icon']) ?>
            <?= $this->partial('media/dropdown', ['name' => 'sort', 'label' => $this->t('admin.media.filter.sort'), 'options' => $sorts, 'current' => $list->sort, 'show' => 'icon']) ?>
        </div>
    </div>
    <div class="admin-media-toolbar-row">
        <div class="admin-media-search">
            <label class="admin-media-search-field" for="<?= $prefix ?>-q"><?= $this->icon('search') ?><span class="admin-visually-hidden"><?= $this->e($this->t('admin.media.search')) ?></span><input type="search" id="<?= $prefix ?>-q" name="q" value="<?= $this->e($list->search) ?>" maxlength="100" placeholder="<?= $this->e($this->t('admin.media.search_placeholder')) ?>" autocomplete="off" data-media-search></label>
            <?= $this->partial('media/dropdown', ['name' => 'everywhere', 'label' => $this->t('admin.media.scope.label'), 'options' => $scopes, 'current' => $list->everywhere ? '1' : '', 'show' => 'text']) ?>
        </div>
        <?php if ($list->kind !== 'all'): ?>
        <?php
        $fixedNote = $this->t($list->kind === 'video' ? 'admin.media.type_fixed_video' : 'admin.media.type_fixed');
        $fixedType = $this->t('admin.media.type.' . $list->kind);
        $fixed = $this->t('admin.media.filter.type') . ': ' . $fixedType . '. ' . $fixedNote;
        ?>
        <span class="admin-media-dropdown-trigger admin-media-dropdown-fixed" role="img" title="<?= $this->e($fixedNote) ?>" aria-label="<?= $this->e($fixed) ?>"><span class="admin-media-dropdown-current"><span class="admin-media-option-icon"><?= $this->icon($list->kind === 'video' ? 'video' : 'image') ?></span><span class="admin-media-option-text"><?= $this->e($fixedType) ?></span></span></span>
        <?php else: ?>
        <?= $this->partial('media/dropdown', ['name' => 'type', 'label' => $this->t('admin.media.filter.type'), 'options' => $types, 'current' => $list->type, 'show' => 'both']) ?>
        <?php endif ?>
        <label class="admin-toggle" for="<?= $prefix ?>-unused"><input type="checkbox" role="switch" id="<?= $prefix ?>-unused" name="unused" value="1"<?= $list->unused ? ' checked' : '' ?>><?= $this->e($this->t('admin.media.filter.unused')) ?></label>
        <button type="submit" class="admin-button admin-button-secondary admin-media-apply"><?= $this->icon('filter') ?><?= $this->e($this->t('admin.media.filter.apply')) ?></button>
    </div>
</form>
