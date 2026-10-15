<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Collection $collection */
/** @var AtarCms\Content\EntryPage $page */
/** @var string $locale */
/** @var array{status: string, q: string, sort: string, dir: string, term: string} $filters */
/** @var array{0: string, 1: string} $activeSort the column and direction the list is in */
/** @var array<int, string> $authors */
/** @var array<string, list<string>> $groupLocales */
/** @var array<int, string> $parents */
/** @var bool $canCreate */
/** @var bool $canReorder */
/** @var array<int, bool> $editable */
/** @var array<string, AtarCms\Content\Taxonomy> $taxonomies */
/** @var array<string, list<array{term: AtarCms\Content\Term, depth: int}>> $termOptions */
/** @var array<int, AtarCms\Content\Term> $routeTerms entry id => its term of the route's taxonomy placeholder */
/** @var bool $showPhotos whether the collection has main photo fields (Collection::shareImageFields()) */
/** @var array<int, AtarCms\Media\Media> $photos entry id => its main photo */
/** @var list<AtarCms\Admin\FlashMessage> $toasts the offer to translate an entry after a switch of the content language (?translate=) */
$routeTaxonomy = $collection->routeTaxonomy === null ? null : ($taxonomies[$collection->routeTaxonomy] ?? null);
$title = $this->label($collection->label);
$base = ['collection' => $collection->name, 'locale' => $locale];
$query = static fn(array $extra): array => array_filter([...$base, ...$extra], static fn($v): bool => $v !== '' && $v !== null);
$current = array_filter(['status' => $filters['status'], 'q' => $filters['q'], 'sort' => $filters['sort'], 'dir' => $filters['dir'], 'term' => $filters['term']], static fn($v): bool => $v !== '');
$filtered = $filters['status'] !== '' || $filters['q'] !== '' || $filters['term'] !== '';
$newUrl = $this->url('admin.entries.new', ['collection' => $collection->name, 'locale' => $locale]);
// The headers show the order the list is in ($activeSort: the chosen one, else the default), not only a chosen one.
[$sortColumn, $sortDir] = $activeSort;
$sortLink = function (string $column, string $labelKey) use ($query, $current, $sortColumn, $sortDir): string {
    $active = $sortColumn === $column;
    $dir = $active && $sortDir === 'asc' ? 'desc' : 'asc';
    $arrow = $active ? $this->icon($sortDir === 'asc' ? 'chevron-up' : 'chevron-down') : '';

    return '<a class="admin-sort" href="' . $this->e($this->url('admin.entries', $query([...$current, 'sort' => $column, 'dir' => $dir]))) . '">'
        . $this->e($this->t($labelKey)) . $arrow . '</a>';
};
// The Media column (only for a collection with a gallery or YouTube field): an icon per extra medium the row's own
// values hold, a filled gallery (with its photo count) and a YouTube video (a video in the text does not count, a
// user decision).
$galleryFields = array_keys(array_filter($collection->fields, static fn(AtarCms\Content\Field\Field $field): bool => $field->type instanceof AtarCms\Content\Field\GalleryType));
$videoFields = array_keys(array_filter($collection->fields, static fn(AtarCms\Content\Field\Field $field): bool => $field->type instanceof AtarCms\Content\Field\YouTubeType));
$hasMedia = $galleryFields !== [] || $videoFields !== [];
$mediaMarks = function (AtarCms\Content\Entry $entry) use ($galleryFields, $videoFields): string {
    $photos = 0;
    foreach ($galleryFields as $name) {
        $value = $entry->data[$name] ?? null;
        $photos += is_array($value) ? count($value) : 0;
    }
    $marks = [];
    if ($photos > 0) {
        $marks[] = ['admin-entry-media-gallery', 'images', $this->t('admin.entries.has_gallery', ['count' => $photos])];
    }
    foreach ($videoFields as $name) {
        if (($entry->data[$name] ?? '') !== '') {
            $marks[] = ['admin-entry-media-video', 'video', $this->t('admin.entries.has_video')];
            break;
        }
    }
    $html = '';
    foreach ($marks as [$class, $icon, $label]) {
        $html .= '<span class="admin-entry-media-item ' . $class . '" role="img" aria-label="' . $this->e($label) . '" title="' . $this->e($label) . '">' . $this->icon($icon) . '</span>';
    }

    return $html === '' ? '' : '<span class="admin-entry-media">' . $html . '</span>';
};
$sorted = static fn(string $column): string => $sortColumn === $column ? ' aria-sort="' . ($sortDir === 'asc' ? 'ascending' : 'descending') . '"' : '';
$this->layout('layouts/admin', ['title' => $title, 'breadcrumbs' => [[$title]], 'scripts' => ['list-filters.js', ...($collection->translatable && $this->multilingual() ? ['translate.js'] : [])], 'toasts' => $toasts, ...($collection->translatable ? ['contentLocale' => $locale] : [])]);
?>
<?= $this->partial('partials/page-header', [
    'title' => $title,
    'locale' => $collection->translatable ? $locale : null,
    'actions' => [
        ...($canReorder ? [['label' => $this->t('admin.entries.order'), 'url' => $this->url('admin.entries.order', ['collection' => $collection->name, 'locale' => $locale]), 'icon' => 'list-tree']] : []),
        ...($canCreate ? [['label' => $this->t('admin.entries.new'), 'url' => $newUrl, 'icon' => 'plus', 'primary' => true]] : []),
    ],
]) ?>
<?php // list-filters.js submits the form on a change and after typing; htmx swaps only #entries-results, so the search box keeps its focus. ?>
<?php $listUrl = $this->url('admin.entries', ['collection' => $collection->name]); ?>
<form class="admin-filters" method="get" action="<?= $this->e($listUrl) ?>" hx-get="<?= $this->e($listUrl) ?>" hx-target="#entries-results" hx-select="#entries-results" hx-swap="outerHTML" hx-push-url="true" hx-sync="this:replace" role="search" data-list-filters>
    <?php foreach (['sort', 'dir'] as $kept): ?>
    <?php if ($filters[$kept] !== ''): ?>
    <input type="hidden" name="<?= $kept ?>" value="<?= $this->e($filters[$kept]) ?>">
    <?php endif ?>
    <?php endforeach ?>
    <label class="admin-filter"><span class="admin-filter-label"><?= $this->e($this->t('admin.entries.filter.status')) ?></span>
        <select name="status">
            <option value=""><?= $this->e($this->t('admin.entries.filter.all')) ?></option>
            <?php foreach (['published', 'draft', 'scheduled'] as $status): ?>
            <option value="<?= $this->e($status) ?>"<?= $status === $filters['status'] ? ' selected' : '' ?>><?= $this->e($this->t('admin.entries.status.' . $status)) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <?php if ($routeTaxonomy !== null && ($termOptions[$routeTaxonomy->name] ?? []) !== []): ?>
    <?php // A collection routed by its sections: a flat, indented list of its sections. ?>
    <label class="admin-filter"><span class="admin-filter-label"><?= $this->e($this->label($routeTaxonomy->label)) ?></span>
        <select name="term">
            <option value=""><?= $this->e($this->t('admin.entries.filter.all')) ?></option>
            <?php foreach ($termOptions[$routeTaxonomy->name] as $node): ?>
            <option value="<?= $this->e($node['term']->id) ?>"<?= (string) $node['term']->id === $filters['term'] ? ' selected' : '' ?>><?= $this->e(str_repeat('— ', $node['depth']) . $node['term']->name) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <?php elseif (array_filter($termOptions) !== []): ?>
    <label class="admin-filter"><span class="admin-filter-label"><?= $this->e($this->t('admin.entries.filter.term')) ?></span>
        <select name="term">
            <option value=""><?= $this->e($this->t('admin.entries.filter.all')) ?></option>
            <?php foreach ($termOptions as $taxonomyName => $nodes): ?>
            <optgroup label="<?= $this->e($this->label($taxonomies[$taxonomyName]->label)) ?>">
                <?php foreach ($nodes as $node): ?>
                <option value="<?= $this->e($node['term']->id) ?>"<?= (string) $node['term']->id === $filters['term'] ? ' selected' : '' ?>><?= $this->e(str_repeat('— ', $node['depth']) . $node['term']->name) ?></option>
                <?php endforeach ?>
            </optgroup>
            <?php endforeach ?>
        </select>
    </label>
    <?php endif ?>
    <label class="admin-filter admin-filter-search" for="entries-q"><span class="admin-filter-label"><?= $this->e($this->t('admin.entries.filter.search')) ?></span>
        <?= $this->icon('search') ?><input type="search" id="entries-q" name="q" maxlength="100" value="<?= $this->e($filters['q']) ?>" data-list-search>
    </label>
    <button type="submit" class="admin-button admin-button-secondary admin-list-apply"><?= $this->icon('filter') ?><?= $this->e($this->t('admin.entries.filter.apply')) ?></button>
</form>
<div id="entries-results">
<p class="admin-muted admin-list-count"><?= $this->e($this->t('admin.entries.count', ['count' => $page->total])) ?></p>
<?php if ($page->entries === []): ?>
<div class="admin-card admin-empty">
    <?= $this->icon($filtered ? 'search' : 'file-text') ?>
    <p class="admin-empty-title"><?= $this->e($filtered ? $this->t('admin.empty.filtered') : $this->t('admin.empty.title')) ?></p>
    <?php if ($canCreate && !$filtered): ?>
    <a class="admin-button admin-button-primary" href="<?= $this->e($newUrl) ?>"><?= $this->icon('plus') ?><?= $this->e($this->t('admin.empty.create')) ?></a>
    <?php endif ?>
</div>
<?php else: ?>
<div class="admin-card admin-table-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th<?= $sorted('title') ?>><?= $sortLink('title', 'admin.entries.column.title') ?></th>
                <?php if ($hasMedia): ?>
                <th><?= $this->e($this->t('admin.entries.column.media')) ?></th>
                <?php endif ?>
                <?php if ($routeTaxonomy !== null): ?>
                <th><?= $this->e($this->label($routeTaxonomy->label)) ?></th>
                <?php endif ?>
                <?php if ($collection->hierarchical): ?>
                <th><?= $this->e($this->t('admin.entries.column.parent')) ?></th>
                <th<?= $sorted('position') ?>><?= $sortLink('position', 'admin.entries.column.position') ?></th>
                <?php endif ?>
                <th<?= $sorted('published_at') ?>><?= $sortLink('published_at', 'admin.entries.column.published') ?></th>
                <th<?= $sorted('updated_at') ?>><?= $sortLink('updated_at', 'admin.entries.column.updated') ?></th>
                <th><?= $this->e($this->t('admin.entries.column.author')) ?></th>
                <th<?= $sorted('status') ?>><?= $sortLink('status', 'admin.entries.column.status') ?></th>
                <?php // Both language modes: plain flags, never links (the linked mode switches the language only in the top bar). ?>
                <?php if ($collection->translatable && $this->multilingual()): ?>
                <th><?= $this->e($this->t('admin.entries.column.languages')) ?></th>
                <?php endif ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($page->entries as $entry): ?>
            <?php $canEdit = $editable[$entry->id] ?? false; ?>
            <tr<?= $canEdit ? ' class="admin-table-row"' : '' ?>>
                <?php $titleHtml = ($canEdit ? '<a class="admin-row-link" href="' . $this->e($this->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $entry->id])) . '">' . $this->e($entry->title) . '</a>' : '<span class="admin-row-title">' . $this->e($entry->title) . '</span>') . '<span class="admin-row-sub">' . $this->e($entry->slug) . '</span>'; ?>
                <?php if ($showPhotos): ?>
                <?php // The main photo (decorative: the row link names the entry), else a placeholder in the same box. No
                // data-label: on a phone the picture and the title head the card, without a "Title" line above them. ?>
                <td><div class="admin-entry-title-cell"><?php if (isset($photos[$entry->id])): ?><img class="admin-entry-thumb" src="<?= $this->e($this->mediaThumb($photos[$entry->id])) ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="admin-entry-thumb admin-entry-thumb-empty"><?= $this->icon('image-off') ?></span><?php endif ?><div class="admin-entry-title-text"><?= $titleHtml ?></div></div></td>
                <?php else: ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.title')) ?>"><?= $titleHtml ?></td>
                <?php endif ?>
                <?php if ($hasMedia): ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.media')) ?>"><?= $mediaMarks($entry) ?></td>
                <?php endif ?>
                <?php if ($routeTaxonomy !== null): ?>
                <td data-label="<?= $this->e($this->label($routeTaxonomy->label)) ?>"><?= $this->e($routeTerms[$entry->id]->name ?? '') ?></td>
                <?php endif ?>
                <?php if ($collection->hierarchical): ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.parent')) ?>"><?= $this->e($entry->parentId === null ? '' : ($parents[$entry->parentId] ?? '#' . $entry->parentId)) ?></td>
                <td data-label="<?= $this->e($this->t('admin.entries.column.position')) ?>"><?= $this->e($entry->position) ?></td>
                <?php endif ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.published')) ?>"><?= trim($this->partial('partials/table-date', ['time' => $entry->publishedAt])) ?></td>
                <td data-label="<?= $this->e($this->t('admin.entries.column.updated')) ?>"><?= trim($this->partial('partials/table-date', ['time' => $entry->updatedAt])) ?></td>
                <?php // A preset page (Collection::presets()) has no author: its lock tells why it has no status choice, address change or Delete. ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.author')) ?>"><?php if ($entry->isPreset()): ?><span class="admin-chip admin-chip-preset" title="<?= $this->e($this->t('admin.entries.preset.title')) ?>"><?= $this->icon('lock') ?><?= $this->e($this->t('admin.entries.preset.chip')) ?><span class="admin-visually-hidden">: <?= $this->e($this->t('admin.entries.preset.title')) ?></span></span><?php else: ?><?= $this->e($entry->authorId === null ? '' : ($authors[$entry->authorId] ?? '')) ?><?php endif ?></td>
                <td data-label="<?= $this->e($this->t('admin.entries.column.status')) ?>"><span class="admin-chip admin-chip-<?= $this->e($entry->status->value) ?>"><?= $this->e($this->t('admin.entries.status.' . $entry->status->value)) ?></span></td>
                <?php if ($collection->translatable && $this->multilingual()): ?>
                <?php // Each language of the translation group: the theme's round flag named in itself, else its code. ?>
                <td data-label="<?= $this->e($this->t('admin.entries.column.languages')) ?>"><?= trim($this->partial('partials/flags', ['codes' => $groupLocales[$entry->translationGroup] ?? [$entry->locale]])) ?></td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>
<?= $this->partial('partials/pagination', [
    'page' => $page->number,
    'pages' => $page->lastPage(),
    'url' => fn(int $number): string => $this->url('admin.entries', $query([...$current, 'page' => $number])),
]) ?>
</div>
