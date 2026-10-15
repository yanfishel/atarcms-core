<?php
/**
 * The entry form, after the RusDM editor: two columns of tabs (EntryForm::layout()) and a sticky action bar.
 * Without JavaScript every tab panel is shown under its heading; tabs.js turns each column into tabs. The server
 * opens the first tab with errors of each column (else the first): until tabs.js runs, only that panel shows.
 * Errors of inputs the form does not show are listed in the alert above it.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Collection $collection
 * @var AtarCms\Content\Entry|null $entry
 * @var string $locale
 * @var list<string> $locales
 * @var array<string, AtarCms\Content\Entry> $translations
 * @var array<string, bool> $translationsEditable
 * @var bool $canTranslate whether the user may create a translation of the saved entry
 * @var list<AtarCms\Admin\FlashMessage> $offers the offer to create a translation after a switch of the content language
 * @var AtarCms\Admin\MachineTranslation|null $machine the machine translation panel of a copy (Plan 38)
 * @phpstan-import-type EntryFormValues from AtarCms\Admin\Content\EntryFormData
 * @var EntryFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $version
 * @var bool $canPublish
 * @var bool $canCreate
 * @var bool $canDelete
 * @var array<string, array<int, string>> $relationOptions
 * @var array<string, list<int>> $relationKept
 * @var array<int, string> $parentOptions
 * @var bool $parentUnpublished
 * @var array<string, AtarCms\Content\Taxonomy> $taxonomies
 * @var array<string, list<array{term: AtarCms\Content\Term, depth: int}>> $termOptions
 * @var array<string, list<string>> $tagOptions per tags field, the existing tag names of its taxonomy in the entry's language
 * @var array<string, array<int, AtarCms\Media\Media>> $mediaOptions
 * @var string|null $shareImage the URL of the saved entry's generated share image, shown first in the SEO tab (a user decision)
 * @var array{contact: AtarCms\Admin\Content\ContactDetails, map: AtarCms\Settings\GoogleMap|null}|null $contactPanel the
 *      contact page's contact details and map (side tabs Address, Contacts, Social; saved with the entry)
 */
$isNew = $entry === null;
// A preset page (Collection::presets()): always published (no status), its address read-only, a locked Delete.
$preset = !$isNew && $entry->isPreset();
$collectionLabel = $this->label($collection->label);
// A single collection has exactly one entry, so the page is named by the collection; otherwise by the action or the entry.
$heading = $collection->single ? $collectionLabel : ($isNew ? $this->t('admin.entries.new') : $entry->title);
$pageTitle = $isNew && !$collection->single ? $heading . ' · ' . $collectionLabel : $heading;
$action = $isNew
    ? $this->url('admin.entries.store', ['collection' => $collection->name])
    : $this->url('admin.entries.update', ['collection' => $collection->name, 'id' => $entry->id]);
$layout = AtarCms\Admin\Content\EntryForm::layout($collection, $values, $errors, $canPublish, $preset);
// Preview posts the unsaved form to the theme in a tab of its own (one per entry: pressing again refreshes it).
$previewAction = $collection->route === null ? null : ($isNew
    ? $this->url('admin.entries.preview.new', ['collection' => $collection->name])
    : $this->url('admin.entries.preview', ['collection' => $collection->name, 'id' => $entry->id]));
$previewTarget = 'atar-preview-' . $collection->name . '-' . ($isNew ? 'new' : $entry->id);
// Only drafts save by themselves (autosave.js): a new entry or a stored draft; a plain single is always published.
$autosave = $collection->plain || (!$isNew && $entry->status !== AtarCms\Content\EntryStatus::Draft) ? null : ($isNew
    ? $this->url('admin.entries.autosave.new', ['collection' => $collection->name])
    : $this->url('admin.entries.autosave', ['collection' => $collection->name, 'id' => $entry->id]));
$pluralForms = fn(string $key): string => (string) json_encode(
    AtarCms\I18n\PluralForms::of($this->locale(), fn(int $count): string => $this->t($key, ['count' => $count])),
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);
$error = function (string $key) use ($errors, $params): string {
    return isset($errors[$key])
        ? '<span class="admin-field-error" id="' . $this->e($key) . '-error">' . $this->e($this->t($errors[$key], $params[$key] ?? [])) . '</span>'
        : '';
};
$invalid = static fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-error"' : '';
$fieldClass = static fn(string ...$keys): string => 'admin-field' . (array_intersect($keys, array_keys($errors)) !== [] ? ' admin-field-invalid' : '');
$statuses = $canPublish ? ['published', 'draft', 'scheduled'] : ['draft'];
$currentStatus = $values['status'] !== '' ? $values['status'] : 'draft';
$hasMediaWidgets = false;
$hasRichText = false;
$hasTags = false;
$hasYouTube = false;
// The slides fields: each has its dialog after the form and the page loads slides.js.
$slidesFields = [];
foreach ($collection->fields as $field) {
    if ($field->type instanceof AtarCms\Content\Field\SlidesType) {
        $slidesFields[] = $field;
    }
    if ($field->type instanceof AtarCms\Content\Field\MediaFieldType) {
        $hasMediaWidgets = true;
    }
    if ($field->type instanceof AtarCms\Content\Field\RichTextType) {
        $hasRichText = true;
    }
    if ($field->type instanceof AtarCms\Content\Field\TagsType) {
        $hasTags = true;
    }
    if ($field->type instanceof AtarCms\Content\Field\YouTubeType) {
        $hasYouTube = true;
    }
}
// The form-wide errors (entry, locale) and those of inputs the form does not show are toasts; the summary counts the
// rest, which stay at their fields.
[$fieldErrors, $toasts] = AtarCms\Admin\FormErrors::split($errors, $params, array_values(array_diff(array_keys($errors), ['entry', 'locale'], $layout->unplaced)));
// The contact details' errors stay at their fields too and count on their tabs.
$contactTabErrors = $contactPanel === null ? [] : AtarCms\Admin\Settings\SettingsForm::tabErrors($contactPanel['contact']->errors, true);
$fieldErrorCount = count($fieldErrors) + array_sum($contactTabErrors);
$this->layout('layouts/admin', [
    'title' => $pageTitle,
    'toasts' => [...$toasts, ...$offers],
    // A collection in one language leaves the top bar on the remembered language: switching does not concern it.
    ...($collection->translatable ? ['contentLocale' => $locale] : []),
    'breadcrumbs' => $collection->single
        ? [[$heading]]
        : [[$collectionLabel, $this->url('admin.entries', ['collection' => $collection->name, 'locale' => $locale])], [$heading]],
    // Rich text brings CKEditor (its stylesheet, then the theme's editor stylesheets) and the media picker.
    // The contact page's map buttons open in the page's dialog.
    'scripts' => ['tabs.js', 'autosave.js', ...($hasRichText ? ['editor.js'] : []), ...($hasMediaWidgets || $hasRichText ? ['media.js'] : []), ...($slidesFields !== [] ? ['slides.js'] : []), ...($hasTags ? ['tags.js'] : []), ...($hasYouTube ? ['youtube.js'] : []), ...($contactPanel !== null ? ['dialog.js'] : []), ...($collection->translatable && $this->multilingual() ? ['translate.js'] : [])],
    'styles' => $hasRichText ? ['vendor/ckeditor5-48.5.2.css'] : [],
    'themeStyles' => $hasRichText ? $this->editorStylesheets() : [],
]);
?>
<?php
// The Translations line of a saved entry sits on the right of the title; the linked mode has none (Plan 37: only the
// top bar changes the language).
$aside = '';
if (!$isNew && $collection->translatable && !$this->linkedLanguage()) {
    $links = [];
    foreach ($translations as $code => $translation) {
        if ($code !== $locale) {
            $links[$code] = ($translationsEditable[$code] ?? false) ? $this->url('admin.entries.edit', ['collection' => $collection->name, 'id' => $translation->id, 'locale' => $code]) : null;
        }
    }
    $aside = $this->partial('partials/translations', [
        'locale' => $locale,
        'locales' => $locales,
        'translations' => $links,
        'create' => $canTranslate ? $this->url('admin.entries.translate', ['collection' => $collection->name, 'id' => $entry->id]) : null,
    ]);
}
?>
<?= $this->partial('partials/page-header', ['title' => $heading, 'locale' => $collection->translatable ? $locale : null, 'aside' => $aside]) ?>
<?php if ($fieldErrorCount > 0): ?>
<div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t('admin.form.fix_fields', ['count' => $fieldErrorCount])) ?></p></div>
<?php endif ?>
<?php if ($parentUnpublished): ?>
<div class="admin-alert admin-alert-warning"><?= $this->icon('info') ?><p><?= $this->e($this->t('admin.entries.parent_unpublished')) ?></p></div>
<?php endif ?>
<form class="admin-entry-form" method="post" action="<?= $this->e($action) ?>" data-content-locale="<?= $this->e($locale) ?>" data-entry-form<?php if ($autosave !== null): ?> data-autosave="<?= $this->e($autosave) ?>"<?php endif ?>>
    <?php // The form's first submit button is the one Enter presses: Save, not the Preview in the action bar. ?>
    <?php if ($previewAction !== null): ?>
    <button type="submit" class="admin-visually-hidden" tabindex="-1" aria-hidden="true"><?= $this->e($isNew ? $this->t('admin.entries.create') : $this->t('admin.entries.save')) ?></button>
    <?php endif ?>
    <?= $this->csrfField() ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>">
    <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
    <?php if ($machine !== null): ?>
    <?= $this->partial('partials/machine-translation', ['machine' => $machine]) ?>
    <?php endif ?>
    <?php
    // A plain single has nothing in the side column: the fields take the whole width. The contact page's form has the
    // contact details there, in three tabs after any others (Address with the map, Contacts, Social).
    $columns = array_filter($layout->columns());
    foreach ($contactPanel === null ? [] : $contactTabErrors as $contactTab => $count) {
        $columns['side'][] = new AtarCms\Admin\Content\FormTab($contactTab, [], [], $count);
    }
    ?>
    <div class="admin-form-layout<?= isset($columns['side']) ? '' : ' admin-form-layout-wide' ?>">
        <?php foreach ($columns as $column => $tabs): ?>
        <?php
        $open = 0;
        foreach ($tabs as $index => $tab) {
            if ($tab->errors > 0) {
                $open = $index;
                break;
            }
        }
        ?>
        <?php // A tab of its own for one field (a slides field) is named by the field's label. ?>
        <?php $tabLabel = fn(AtarCms\Admin\Content\FormTab $tab): string => $tab->field !== null ? $this->label($tab->field->label) : $this->t('admin.form.tab.' . $tab->name); ?>
        <div class="admin-form-column" data-tabs="admin.tabs.<?= $this->e($collection->name) ?>.<?= $this->e($column) ?>">
            <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($column === 'main' ? $this->t('admin.form.sections_main') : $this->t('admin.form.sections_side')) ?>">
                <?php foreach ($tabs as $index => $tab): ?>
                <button type="button" class="admin-tab" role="tab" id="tab-<?= $this->e($tab->name) ?>" aria-controls="panel-<?= $this->e($tab->name) ?>" aria-selected="<?= $index === $open ? 'true' : 'false' ?>" tabindex="<?= $index === $open ? '0' : '-1' ?>" data-tab="<?= $this->e($tab->name) ?>" data-errors="<?= $this->e($tab->errors) ?>">
                    <?= $this->e($tabLabel($tab)) ?>
                    <?php if ($tab->errors > 0): ?>
                    <span class="admin-tab-badge" aria-hidden="true"><?= $this->e($tab->errors) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.tab_errors', ['count' => $tab->errors])) ?></span>
                    <?php elseif ($tab->name === 'media'): ?>
                    <span data-tab-counter<?= $tab->count === 0 ? ' hidden' : '' ?>><span class="admin-tab-count" aria-hidden="true" data-tab-count><?= $this->e($tab->count) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.files_chosen')) ?>: <span data-tab-count><?= $this->e($tab->count) ?></span></span></span>
                    <?php endif ?>
                </button>
                <?php endforeach ?>
            </div>
            <?php foreach ($tabs as $index => $tab): ?>
            <section class="admin-tab-panel admin-card<?= $index === $open ? ' admin-tab-panel-open' : '' ?>" role="tabpanel" id="panel-<?= $this->e($tab->name) ?>" aria-labelledby="tab-<?= $this->e($tab->name) ?>">
                <h2 class="admin-tab-heading"><?= $this->e($tabLabel($tab)) ?></h2>
                <?php foreach ($tab->leadFields as $field): ?>
                <?= $this->partial('fields/field', [
                    'field' => $field,
                    'value' => $values['fields'][$field->name] ?? null,
                    'error' => $errors[$field->name] ?? null,
                    'errorParams' => $params[$field->name] ?? [],
                    'options' => $relationOptions[$field->name] ?? $mediaOptions[$field->name] ?? $tagOptions[$field->name] ?? [],
                    'kept' => $relationKept[$field->name] ?? [],
                    'locale' => $locale,
                ]) ?>
                <?php endforeach ?>
                <?php foreach ($tab->builtins as $builtin): ?>
                <?php if ($builtin === 'title'): ?>
                <div class="<?= $fieldClass('title') ?>">
                    <label for="title"><?= $this->icon('type') ?><?= $this->e($this->t('admin.entries.field.title')) ?> *</label>
                    <input id="title" name="title" required maxlength="255" value="<?= $this->e($values['title']) ?>"<?= $invalid('title') ?><?= $this->langAttrs($locale) ?>>
                    <?= $error('title') ?>
                </div>
                <?php elseif ($builtin === 'status'): ?>
                <fieldset class="admin-radio-list<?= isset($errors['status']) ? ' admin-field-invalid' : '' ?>">
                    <legend><?= $this->e($this->t('admin.entries.field.status')) ?></legend>
                    <?php foreach ($statuses as $status): ?>
                    <label class="admin-radio"><input type="radio" name="status" value="<?= $this->e($status) ?>"<?= $status === $currentStatus ? ' checked' : '' ?> data-status-choice<?= $invalid('status') ?>><span class="admin-radio-text"><span class="admin-radio-title"><?= $this->e($this->t('admin.entries.status.' . $status)) ?></span><span class="admin-help"><?= $this->e($this->t('admin.status.' . $status)) ?></span></span></label>
                    <?php endforeach ?>
                    <?= $error('status') ?>
                </fieldset>
                <?php elseif ($builtin === 'published_at'): ?>
                <div class="<?= $fieldClass('published_at') ?>" data-status-date>
                    <label for="published_at"><?= $this->icon('calendar') ?><?= $this->e($this->t('admin.entries.field.published_at', ['zone' => $this->timezone()])) ?></label>
                    <input type="datetime-local" id="published_at" name="published_at" value="<?= $this->e($values['published_at']) ?>" data-now="<?= $this->e($now) ?>"<?= $invalid('published_at') ?>>
                    <?= $error('published_at') ?>
                </div>
                <?php elseif ($builtin === 'parent'): ?>
                <div class="<?= $fieldClass('parent_id') ?>">
                    <label for="parent_id"><?= $this->icon('folder-tree') ?><?= $this->e($this->t('admin.entries.field.parent')) ?></label>
                    <select id="parent_id" name="parent_id"<?= $invalid('parent_id') ?>>
                        <option value=""><?= $this->e($this->t('admin.entries.field.no_parent')) ?></option>
                        <?php foreach ($parentOptions as $parentId => $parentTitle): ?>
                        <option value="<?= $this->e($parentId) ?>"<?= (string) $parentId === $values['parent_id'] ? ' selected' : '' ?>><?= $this->e($parentTitle) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= $error('parent_id') ?>
                </div>
                <div class="<?= $fieldClass('position') ?>">
                    <label for="position"><?= $this->icon('hash') ?><?= $this->e($this->t('admin.entries.field.position')) ?></label>
                    <input type="number" id="position" name="position" step="1" value="<?= $this->e($values['position']) ?>"<?= $invalid('position') ?>>
                    <span class="admin-help"><?= $this->e($this->t('admin.entries.field.position_help')) ?></span>
                    <?= $error('position') ?>
                </div>
                <?php elseif ($builtin === 'terms'): ?>
                <?php foreach ($taxonomies as $taxonomyName => $taxonomy): ?>
                <?= $this->partial('entries/terms', [
                    'taxonomy' => $taxonomy,
                    'nodes' => $termOptions[$taxonomyName] ?? [],
                    'chosen' => $values['terms'][$taxonomyName] ?? [],
                    'error' => $errors['terms.' . $taxonomyName] ?? null,
                    'single' => $taxonomyName === $collection->routeTaxonomy,
                ]) ?>
                <?php endforeach ?>
                <?php elseif ($builtin === 'slug' && $preset): ?>
                <div class="admin-field">
                    <label for="slug"><?= $this->icon('link') ?><?= $this->e($this->t('admin.entries.field.slug')) ?></label>
                    <input id="slug" name="slug" maxlength="190" dir="ltr" value="<?= $this->e($entry->slug) ?>" readonly aria-describedby="slug-preset">
                    <span class="admin-help" id="slug-preset"><?= $this->e($this->t('admin.entries.preset.slug_help')) ?></span>
                </div>
                <?php elseif ($builtin === 'slug'): ?>
                <div class="<?= $fieldClass('slug') ?>">
                    <label for="slug"><?= $this->icon('link') ?><?= $this->e($this->t('admin.entries.field.slug')) ?></label>
                    <input id="slug" name="slug" maxlength="190" dir="ltr" value="<?= $this->e($values['slug']) ?>"<?= $invalid('slug') ?>>
                    <span class="admin-help"><?= $this->e($this->t('admin.entries.field.slug_help')) ?></span>
                    <?= $error('slug') ?>
                </div>
                <?php elseif ($builtin === 'meta_title'): ?>
                <div class="<?= $fieldClass('meta_title') ?>">
                    <label for="meta_title"><?= $this->icon('search') ?><?= $this->e($this->t('admin.entries.seo.meta_title')) ?></label>
                    <input id="meta_title" name="meta_title" maxlength="255" value="<?= $this->e($values['meta_title']) ?>"<?= $invalid('meta_title') ?><?= $this->langAttrs($locale) ?>>
                    <span class="admin-help"><?= $this->e($this->t('admin.entries.seo.meta_title_help')) ?></span>
                    <?= $error('meta_title') ?>
                </div>
                <?php elseif ($builtin === 'meta_description'): ?>
                <div class="<?= $fieldClass('meta_description') ?>">
                    <label for="meta_description"><?= $this->icon('align-left') ?><?= $this->e($this->t('admin.entries.seo.meta_description')) ?></label>
                    <textarea id="meta_description" name="meta_description" maxlength="500" rows="3"<?= $invalid('meta_description') ?><?= $this->langAttrs($locale) ?>><?= $this->e($values['meta_description']) ?></textarea>
                    <span class="admin-help"><?= $this->e($this->t('admin.entries.seo.meta_description_help')) ?></span>
                    <?= $error('meta_description') ?>
                </div>
                <?php elseif ($builtin === 'share'): ?>
                <?php if ($shareImage !== null): ?>
                <div class="admin-share-preview">
                    <img class="admin-share-image" src="<?= $this->e($shareImage) ?>" alt="" width="1200" height="630">
                    <p class="admin-help"><?= $this->e($this->t('admin.form.share.generated')) ?></p>
                </div>
                <?php endif ?>
                <?php endif ?>
                <?php endforeach ?>
                <?php foreach ($tab->fields as $field): ?>
                <?= $this->partial('fields/field', [
                    'field' => $field,
                    'value' => $values['fields'][$field->name] ?? null,
                    'error' => $errors[$field->name] ?? null,
                    'errorParams' => $params[$field->name] ?? [],
                    'options' => $relationOptions[$field->name] ?? $mediaOptions[$field->name] ?? $tagOptions[$field->name] ?? [],
                    'kept' => $relationKept[$field->name] ?? [],
                    'locale' => $locale,
                ]) ?>
                <?php endforeach ?>
                <?php if ($contactPanel !== null && isset($contactTabErrors[$tab->name])): ?>
                <?php foreach (AtarCms\Admin\Settings\SettingsForm::tab($tab->name) as $name => $contactField): ?>
                <?= $this->partial('settings/field', ['name' => $name, 'field' => $contactField, 'values' => $contactPanel['contact']->values, 'errors' => $contactPanel['contact']->errors, 'params' => $contactPanel['contact']->params, 'images' => [], 'locale' => $locale]) ?>
                <?php endforeach ?>
                <?php if ($tab->name === 'address'): ?>
                <?= $this->partial('map/panel', ['map' => $contactPanel['map'], 'canManage' => true]) ?>
                <?php endif ?>
                <?php endif ?>
            </section>
            <?php endforeach ?>
        </div>
        <?php endforeach ?>
    </div>
    <div class="admin-action-bar">
        <?php // autosave.js replaces the text after its first save ("Saving…", "Saved 2 minutes ago"); the hidden status announces each save. ?>
        <p class="admin-action-bar-status" data-autosave-status data-saving="<?= $this->e($this->t('admin.autosave.saving')) ?>" data-saved-now="<?= $this->e($this->t('admin.autosave.saved_now')) ?>" data-saved-minutes="<?= $this->e($pluralForms('admin.autosave.saved_minutes')) ?>" data-saved-hours="<?= $this->e($pluralForms('admin.autosave.saved_hours')) ?>" data-locale="<?= $this->e($this->locale()) ?>"><span data-autosave-text><?= $this->e($isNew ? $this->t('admin.form.not_saved') : $this->t('admin.form.saved_at', ['date' => $this->readableDate($entry->updatedAt)])) ?></span><span class="admin-visually-hidden" role="status" data-autosave-announce></span></p>
        <?php // The error toast of a failed autosave; refusals put the server's reason (409) or one of these texts in it. ?>
        <template data-autosave-failed data-expired="<?= $this->e($this->t('admin.autosave.expired')) ?>" data-forbidden="<?= $this->e($this->t('admin.autosave.forbidden')) ?>"><?= trim($this->partial('partials/toast', ['type' => 'error', 'text' => $this->t('admin.autosave.failed')])) ?></template>
        <div class="admin-action-bar-buttons">
            <?php if ($canDelete): ?>
            <a class="admin-button admin-button-link admin-button-danger admin-action-compact" href="<?= $this->e($this->url('admin.entries.delete.confirm', ['collection' => $collection->name, 'id' => $entry->id])) ?>" title="<?= $this->e($this->t('admin.entries.delete')) ?>"><?= $this->icon('trash-2') ?><span class="admin-action-label"><?= $this->e($this->t('admin.entries.delete')) ?></span></a>
            <?php elseif ($preset): ?>
            <?php // A locked button ("Locked buttons" of the admin UI guide): it stays in the Tab order and tells why. ?>
            <button type="button" class="admin-button admin-button-link admin-button-danger admin-action-compact admin-locked" aria-disabled="true" title="<?= $this->e($this->t('admin.entries.preset.delete_locked')) ?>" aria-describedby="entry-delete-reason"><?= $this->icon('trash-2') ?><span class="admin-action-label"><?= $this->e($this->t('admin.entries.delete')) ?></span></button><span class="admin-visually-hidden" id="entry-delete-reason"><?= $this->e($this->t('admin.entries.preset.delete_locked')) ?></span>
            <?php endif ?>
            <?php if (!$isNew): ?>
            <a class="admin-button admin-button-secondary admin-action-compact" href="<?= $this->e($this->url('admin.entries.revisions', ['collection' => $collection->name, 'id' => $entry->id])) ?>" title="<?= $this->e($this->t('admin.entries.revisions')) ?>"><?= $this->icon('history') ?><span class="admin-action-label"><?= $this->e($this->t('admin.entries.revisions')) ?></span></a>
            <?php endif ?>
            <?php if ($previewAction !== null): ?>
            <button type="submit" class="admin-button admin-button-secondary admin-action-compact" formaction="<?= $this->e($previewAction) ?>" formtarget="<?= $this->e($previewTarget) ?>" formnovalidate title="<?= $this->e($this->t('admin.entries.preview')) ?>" data-preview><?= $this->icon('eye') ?><span class="admin-action-label"><?= $this->e($this->t('admin.entries.preview')) ?></span></button>
            <?php endif ?>
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($isNew ? $this->t('admin.entries.create') : $this->t('admin.entries.save')) ?></button>
        </div>
    </div>
</form>
<?php foreach ($slidesFields as $slidesField): ?>
<?= $this->partial('slides/dialog', ['field' => $slidesField, 'locale' => $locale]) ?>
<?php endforeach ?>
<?php if ($contactPanel !== null): ?>
<?php // The map's dialog (dialog.js) holds forms of its own, so it sits outside the entry form; its toast shows a done change. ?>
<dialog class="admin-dialog" data-dialog aria-labelledby="dialog-title">
    <div class="admin-dialog-content" data-dialog-content></div>
    <template data-dialog-toast><?= trim($this->partial('partials/toast', ['type' => 'success', 'text' => ''])) ?></template>
</dialog>
<?php endif ?>
<?php if (($hasMediaWidgets || $hasRichText) && $this->can('media.upload')): ?>
<?= $this->partial('media/dialog') ?>
<?php endif ?>
