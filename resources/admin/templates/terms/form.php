<?php
/** @var AtarCms\View\Template $this */
/** @var AtarCms\Content\Taxonomy $taxonomy */
/** @var AtarCms\Content\Term|null $term */
/** @var string $locale */
/** @var list<string> $locales */
/** @var array<string, AtarCms\Content\Term> $translations */
/** @var bool $translatable false for tags (Collections::onlyTagged()): kept separately per language, no Translations line */
/** @phpstan-import-type TermFormValues from AtarCms\Admin\Taxonomy\TermFormData */
/** @var TermFormValues $values */
/** @var array<string, string> $errors */
/** @var array<string, array<string, int|float|string>> $params */
/** @var string $version */
/** @var list<array{term: AtarCms\Content\Term, depth: int}> $parents */
/** @var string|null $viewUrl */
/** @var AtarCms\Admin\MachineTranslation|null $machine the machine translation panel of a copy (Plan 38) */
$isNew = $term === null;
$taxonomyLabel = $this->label($taxonomy->label);
$heading = $isNew ? $this->t('admin.terms.new') . ' · ' . $taxonomyLabel : $term->name;
$routes = AtarCms\Admin\Taxonomy\TermRoutes::of(...);
$action = $isNew ? $this->url(...$routes($taxonomy, '.store')) : $this->url(...$routes($taxonomy, '.update', ['id' => $term->id]));
// Errors without a field of the form are toasts; the summary counts the rest.
[$fieldErrors, $toasts] = AtarCms\Admin\FormErrors::split($errors, $params, AtarCms\Admin\Taxonomy\TermForm::fields($taxonomy));
$this->layout('layouts/admin', [
    'title' => $heading,
    'breadcrumbs' => [[$taxonomyLabel, $this->url(...$routes($taxonomy, '', ['locale' => $locale]))], [$heading]],
    'scripts' => [...($taxonomy->isRootTree() ? ['tabs.js'] : []), ...($translatable && $this->multilingual() ? ['translate.js'] : [])],
    'toasts' => $toasts,
    'contentLocale' => $locale,
]);
?>
<?= $this->partial('partials/page-header', [
    'title' => $heading,
    'locale' => $locale,
    // The Translations line of a saved term sits on the right of the title; tags have none (Plan 35), nor has the
    // linked mode (Plan 37: only the top bar changes the language).
    'aside' => $isNew || !$translatable || $this->linkedLanguage() ? '' : $this->partial('terms/translations', ['taxonomy' => $taxonomy, 'term' => $term, 'locale' => $locale, 'locales' => $locales, 'translations' => $translations, 'dialog' => false]),
]) ?>
<?= $this->partial('terms/alert', ['errors' => $fieldErrors]) ?>
<form class="admin-form-card" method="post" action="<?= $this->e($action) ?>">
    <?= $this->csrfField() ?>
    <?php if ($machine !== null): ?>
    <?= $this->partial('partials/machine-translation', ['machine' => $machine]) ?>
    <?php endif ?>
    <input type="hidden" name="version" value="<?= $this->e($version) ?>">
    <input type="hidden" name="locale" value="<?= $this->e($locale) ?>">
    <?php if ($taxonomy->isRootTree()): ?>
    <?= $this->partial('terms/tabs', ['taxonomy' => $taxonomy, 'values' => $values, 'errors' => $errors, 'params' => $params, 'parents' => $parents, 'dialog' => false, 'locale' => $locale]) ?>
    <?php else: ?>
    <div class="admin-card admin-form">
        <?= $this->partial('terms/fields', ['taxonomy' => $taxonomy, 'values' => $values, 'errors' => $errors, 'params' => $params, 'parents' => $parents, 'dialog' => false, 'locale' => $locale]) ?>
    </div>
    <?php endif ?>
    <div class="admin-action-bar">
        <p class="admin-action-bar-status"></p>
        <div class="admin-action-bar-buttons">
            <?php if (!$isNew): ?>
            <a class="admin-button admin-button-link admin-button-danger" href="<?= $this->e($this->url(...$routes($taxonomy, '.delete.confirm', ['id' => $term->id]))) ?>"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.terms.delete')) ?></a>
            <?php endif ?>
            <?php if ($viewUrl !== null): ?>
            <a class="admin-button admin-button-secondary" href="<?= $this->e($viewUrl) ?>" target="_blank" rel="noopener"><?= $this->icon('eye') ?><?= $this->e($this->t('admin.terms.view')) ?></a>
            <?php endif ?>
            <button type="submit" class="admin-button admin-button-primary"><?= $this->icon('save') ?><?= $this->e($isNew ? $this->t('admin.terms.create') : $this->t('admin.terms.save')) ?></button>
        </div>
    </div>
</form>
