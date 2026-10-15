<?php
/**
 * The SEO tab of the section form (terms/tabs.php): the title and the description for search engines, as long as an
 * entry's, each with its help and its error line ({title} is not explained here: a user decision).
 *
 * @var AtarCms\View\Template $this
 * @phpstan-import-type TermFormValues from AtarCms\Admin\Taxonomy\TermFormData
 * @var TermFormValues $values
 * @var array<string, string> $errors
 * @var array<string, array<string, int|float|string>> $params
 * @var string $locale the section's language (the inputs carry its lang and dir)
 */
$error = function (string $key) use ($errors, $params): string {
    return isset($errors[$key])
        ? '<span class="admin-field-error" id="' . $this->e($key) . '-error">' . $this->e($this->t($errors[$key], $params[$key] ?? [])) . '</span>'
        : '';
};
$invalid = static fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-error"' : '';
$fieldClass = static fn(string $key): string => 'admin-field' . (isset($errors[$key]) ? ' admin-field-invalid' : '');
?>
<div class="<?= $fieldClass('meta_title') ?>">
    <label for="meta_title"><?= $this->icon('search') ?><?= $this->e($this->t('admin.sections.seo.title')) ?></label>
    <input id="meta_title" name="meta_title" maxlength="255" value="<?= $this->e($values['meta_title']) ?>"<?= $invalid('meta_title') ?><?= $this->langAttrs($locale) ?>>
    <span class="admin-help"><?= $this->e($this->t('admin.sections.seo.title_help')) ?></span>
    <?= $error('meta_title') ?>
</div>
<div class="<?= $fieldClass('meta_description') ?>">
    <label for="meta_description"><?= $this->icon('align-left') ?><?= $this->e($this->t('admin.sections.seo.description')) ?></label>
    <textarea id="meta_description" name="meta_description" maxlength="500" rows="3"<?= $invalid('meta_description') ?><?= $this->langAttrs($locale) ?>><?= $this->e($values['meta_description']) ?></textarea>
    <span class="admin-help"><?= $this->e($this->t('admin.sections.seo.description_help')) ?></span>
    <?= $error('meta_description') ?>
</div>
