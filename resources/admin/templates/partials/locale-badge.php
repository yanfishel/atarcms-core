<?php
/**
 * A locale mark, named by its title, which a visually hidden twin repeats for screen readers (the mark itself is
 * aria-hidden): the theme's round flag of the language (languageFlag(), as the public language menu shows it), else a
 * pill with the language's code on the accent colour (admin-locale-badge). It marks the content language beside the
 * title of a per-language screen or form, or beside a field kept per language on a form that mixes them (settings,
 * the media alternative texts). A site with one language shows no marks.
 *
 * @var AtarCms\View\Template $this
 * @var string $locale
 * @var string|null $title (optional) the mark's name; "Content language: {language}" by default
 * @var bool $announce (optional) false leaves out the hidden twin where the element is named already
 * @var bool $keep (optional) true where the mark tells languages apart on one screen (the media's alternative
 *                 texts, the top bar's trigger): the linked mode leaves out every other mark, since the screen
 *                 and its interface speak that one language already (Plan 37)
 */
?>
<?php if (count($this->siteLocales()) > 1 && (($keep ?? false) || !$this->linkedLanguage())): ?>
<?php $title ??= $this->t('admin.content_locale.current', ['language' => $this->languageName($locale)]); ?>
<?php $flag = $this->languageFlag($locale); ?>
<?php if ($flag !== null): ?>
<img class="admin-locale-flag" src="<?= $this->e($flag) ?>" alt="" title="<?= $this->e($title) ?>" aria-hidden="true" width="20" height="20"><?php else: ?>
<span class="admin-locale-badge" title="<?= $this->e($title) ?>" aria-hidden="true"><?= $this->e(strtoupper($locale)) ?></span><?php endif ?><?php if ($announce ?? true): ?><span class="admin-visually-hidden">(<?= $this->e($title) ?>)</span><?php endif ?>
<?php endif ?>
