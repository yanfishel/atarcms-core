<?php
/**
 * The term form as the dialog's content (Dialog, dialog.js): the heading names the dialog (#dialog-title), the form
 * posts through htmx and its answer replaces this fragment (errors) or reloads the list (saved). Cancel closes the
 * dialog. The dialog layout: the header (title with the language pill, the Translations line except for a section, close), then the form as the scrolling body and the
 * footer with the buttons.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term|null $term
 * @var string $locale
 * @var list<string> $locales
 * @var array<string, AtarCms\Content\Term> $translations
 * @var bool $translatable false for tags (Collections::onlyTagged()): kept separately per language, no Translations line
 * @phpstan-import-type TermFormValues from AtarCms\Admin\Taxonomy\TermFormData
 * @var TermFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $version
 * @var list<array{term: AtarCms\Content\Term, depth: int}> $parents
 */
$isNew = $term === null;
$routes = AtarCms\Admin\Taxonomy\TermRoutes::of(...);
$heading = match (true) {
    !$isNew => $term->name,
    $taxonomy->isRootTree() => $this->t('admin.sections.new'),
    default => $this->t('admin.terms.new') . ' · ' . $this->label($taxonomy->label),
};
$action = $isNew ? $this->url(...$routes($taxonomy, '.store')) : $this->url(...$routes($taxonomy, '.update', ['id' => $term->id]));
// A refusal without a field never comes back into the dialog (TermsController reloads the screen with it).
[$fieldErrors] = AtarCms\Admin\FormErrors::split($errors, $params, AtarCms\Admin\Taxonomy\TermForm::fields($taxonomy));
?>
<?php $pill = trim($this->partial('partials/locale-badge', ['locale' => $locale])); ?>
<div class="admin-dialog-header">
    <h2 id="dialog-title"><?= $this->e($heading) ?><?= $pill === '' ? '' : ' ' . $pill ?></h2>
    <?php // The Translations line holds forms of its own, so it sits in the header, outside the dialog's form. The
    // section dialog has none (a user decision of Plan 32): its full page keeps it. Tags have none at all (Plan 35), and
    // the linked mode none either (Plan 37: only the top bar changes the language). ?>
    <?php if (!$isNew && !$taxonomy->isRootTree() && $translatable && !$this->linkedLanguage()): ?>
    <?= $this->partial('terms/translations', ['taxonomy' => $taxonomy, 'term' => $term, 'locale' => $locale, 'locales' => $locales, 'translations' => $translations, 'dialog' => true]) ?>
    <?php endif ?>
    <?= $this->partial('partials/dialog-close') ?>
</div>
<form class="admin-dialog-form" method="post" action="<?= $this->e($action) ?>" hx-post="<?= $this->e($action) ?>" hx-target="closest [data-dialog-content]" hx-swap="innerHTML">
    <div class="admin-dialog-body">
        <?= $this->partial('terms/alert', ['errors' => $fieldErrors]) ?>
        <?= $this->csrfField() ?>
        <input type="hidden" name="version" value="<?= $this->e($version) ?>">
        <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
        <?php if ($taxonomy->isRootTree()): ?>
        <?= $this->partial('terms/tabs', ['taxonomy' => $taxonomy, 'values' => $values, 'errors' => $errors, 'params' => $params, 'parents' => $parents, 'dialog' => true, 'locale' => $locale]) ?>
        <?php else: ?>
        <?= $this->partial('terms/fields', ['taxonomy' => $taxonomy, 'values' => $values, 'errors' => $errors, 'params' => $params, 'parents' => $parents, 'dialog' => true, 'locale' => $locale]) ?>
        <?php endif ?>
    </div>
    <div class="admin-dialog-footer">
        <button type="button" class="admin-button admin-button-secondary" data-dialog-close><?= $this->e($this->t('admin.terms.cancel')) ?></button>
        <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($isNew ? $this->t('admin.terms.create') : $this->t('admin.terms.save')) ?></button>
    </div>
</form>
