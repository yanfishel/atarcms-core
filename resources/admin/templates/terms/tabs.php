<?php
/**
 * The section form's inputs in two tabs (tabs.js, the entry form's markup): Main (terms/fields.php) and SEO
 * (terms/seo.php). The server opens the first tab with errors, else Main; the empty data-tabs key keeps tabs.js from
 * remembering the tab, so the dialog always opens on the name. Without JavaScript both panels show one after the other
 * under their headings. On the page ($dialog false) each panel is a card; in the dialog the panels sit in the dialog.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @phpstan-import-type TermFormValues from AtarCms\Admin\Taxonomy\TermFormData
 * @var TermFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var list<array{term: AtarCms\Content\Term, depth: int}> $parents
 * @var bool $dialog
 * @var string $locale the term's language (its inputs carry its lang and dir)
 */
$tabs = [
    'main' => count(array_intersect(['name', 'slug', 'parent_id'], array_keys($errors))),
    'seo' => count(array_intersect(['meta_title', 'meta_description'], array_keys($errors))),
];
$labels = ['main' => $this->t('admin.form.tab.main'), 'seo' => $this->t('admin.form.tab.seo')];
$open = $tabs['main'] === 0 && $tabs['seo'] > 0 ? 'seo' : 'main';
$panelClass = static fn(string $tab): string => 'admin-tab-panel' . ($dialog ? '' : ' admin-card') . ($tab === $open ? ' admin-tab-panel-open' : '');
?>
<div class="admin-form-column" data-tabs="">
    <div class="admin-tab-list" role="tablist" aria-label="<?= $this->e($this->t('admin.sections.form_tabs')) ?>">
        <?php foreach ($tabs as $tab => $count): ?>
        <button type="button" class="admin-tab" role="tab" id="tab-<?= $this->e($tab) ?>" aria-controls="panel-<?= $this->e($tab) ?>" aria-selected="<?= $tab === $open ? 'true' : 'false' ?>" tabindex="<?= $tab === $open ? '0' : '-1' ?>" data-tab="<?= $this->e($tab) ?>" data-errors="<?= $this->e($count) ?>">
            <?= $this->e($labels[$tab]) ?>
            <?php if ($count > 0): ?>
            <span class="admin-tab-badge" aria-hidden="true"><?= $this->e($count) ?></span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.form.tab_errors', ['count' => $count])) ?></span>
            <?php endif ?>
        </button>
        <?php endforeach ?>
    </div>
    <section class="<?= $panelClass('main') ?>" role="tabpanel" id="panel-main" aria-labelledby="tab-main">
        <h2 class="admin-tab-heading"><?= $this->e($labels['main']) ?></h2>
        <?= $this->partial('terms/fields', ['taxonomy' => $taxonomy, 'values' => $values, 'errors' => $errors, 'params' => $params, 'parents' => $parents, 'dialog' => $dialog, 'locale' => $locale]) ?>
    </section>
    <section class="<?= $panelClass('seo') ?>" role="tabpanel" id="panel-seo" aria-labelledby="tab-seo">
        <h2 class="admin-tab-heading"><?= $this->e($labels['seo']) ?></h2>
        <?= $this->partial('terms/seo', ['values' => $values, 'errors' => $errors, 'params' => $params, 'locale' => $locale]) ?>
    </section>
</div>
