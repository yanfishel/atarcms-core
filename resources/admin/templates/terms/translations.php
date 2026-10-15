<?php
/**
 * The Translations line of a saved term's form (partials/translations.php): the other languages' terms link to their
 * form (in the dialog, loaded into it), missing ones post "Create translation" (the term screens require the
 * permission the translate action requires).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Content\Taxonomy $taxonomy
 * @var AtarCms\Content\Term $term
 * @var string $locale
 * @var list<string> $locales
 * @var array<string, AtarCms\Content\Term> $translations
 * @var bool $dialog
 */
$routes = AtarCms\Admin\Taxonomy\TermRoutes::of(...);
$links = [];
foreach ($translations as $code => $translation) {
    if ($code !== $locale) {
        $links[$code] = $this->url(...$routes($taxonomy, '.edit', ['id' => $translation->id, 'locale' => $code]));
    }
}
?>
<?= $this->partial('partials/translations', [
    'locale' => $locale,
    'locales' => $locales,
    'translations' => $links,
    'create' => $this->url(...$routes($taxonomy, '.translate', ['id' => $term->id])),
    'dialog' => $dialog,
]) ?>
