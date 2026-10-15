<?php
/**
 * The translations switch of a saved entry or term form: a segmented control with one segment per site language, in
 * the site's order. The form's own language is the raised segment (its flag, when the theme has one, and its code);
 * an existing translation links to its form (only when the user may edit it, else its code with the check mark as
 * plain text); a missing one is a small POST button to the translate action, "copy for translation" (only with the
 * right to translate, else "—"), with a twin machine translation button (Plan 38: name "machine", the languages
 * icon) rendered hidden: translate.js shows it only where the browser can translate the pair. The "Translations" name is visually hidden; each segment names its language in itself for screen readers
 * ("English: open the translation"). Plain links and forms: no script; in the dialog the links load into it.
 * Nothing on a site with one language.
 *
 * @var AtarCms\View\Template $this
 * @var string $locale the form's language
 * @var list<string> $locales the site's languages
 * @var array<string, string|null> $translations the other languages' translations: locale => the URL of its form,
 *                                               null when the user may not edit it
 * @var string|null $create the translate action's URL, null without the right to translate
 * @var bool $dialog (optional) inside the dialog (dialog.js opens the links there)
 */
?>
<?php if (count($locales) > 1): ?>
<nav class="admin-translations" aria-labelledby="translations-title">
    <span class="admin-visually-hidden" id="translations-title"><?= $this->e($this->t('admin.translations.title')) ?></span>
    <ul class="admin-translations-list">
        <?php foreach ($locales as $code): ?>
        <?php $language = $this->languageName($code); ?>
        <li class="admin-translations-item">
            <?php if ($code === $locale): ?>
            <?php $title = $this->t('admin.content_locale.current', ['language' => $language]); ?>
            <?php $flag = $this->languageFlag($code); ?>
            <span class="admin-translations-current" title="<?= $this->e($title) ?>"><?php if ($flag !== null): ?><img class="admin-locale-flag" src="<?= $this->e($flag) ?>" alt="" aria-hidden="true" width="20" height="20"><?php endif ?><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?></span><span class="admin-visually-hidden">(<?= $this->e($title) ?>)</span></span>
            <?php elseif (array_key_exists($code, $translations) && $translations[$code] !== null): ?>
            <a href="<?= $this->e($translations[$code]) ?>" hreflang="<?= $this->e($code) ?>" title="<?= $this->e($language) ?>"<?= ($dialog ?? false) ? ' data-dialog-open' : '' ?>><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?></span><?= $this->icon('check') ?><span class="admin-visually-hidden"><?= $this->e($this->t('admin.translations.open', ['language' => $language])) ?></span></a>
            <?php elseif (array_key_exists($code, $translations)): ?>
            <span class="admin-translations-exists" title="<?= $this->e($language) ?>"><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?></span><?= $this->icon('check') ?><span class="admin-visually-hidden"><?= $this->e($this->t('admin.translations.exists', ['language' => $language])) ?></span></span>
            <?php elseif ($create !== null): ?>
            <form class="admin-translations-form" method="post" action="<?= $this->e($create) ?>">
                <?= $this->csrfField() ?>
                <input type="hidden" name="locale" value="<?= $this->e($code) ?>">
                <button type="submit" class="admin-translations-create" title="<?= $this->e($this->t('admin.translations.create', ['language' => $language])) ?>"><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?></span><?= $this->icon('plus') ?><span class="admin-visually-hidden"><?= $this->e($this->t('admin.translations.create', ['language' => $language])) ?></span></button>
                <button type="submit" class="admin-translations-create" name="machine" value="1" title="<?= $this->e($this->t('admin.machine.segment', ['language' => $language])) ?>" data-machine-offer data-machine-from="<?= $this->e($locale) ?>" data-machine-to="<?= $this->e($code) ?>" hidden><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?></span><?= $this->icon('languages') ?><span class="admin-visually-hidden"><?= $this->e($this->t('admin.machine.segment', ['language' => $language])) ?></span></button>
            </form>
            <?php else: ?>
            <span class="admin-translations-missing" title="<?= $this->e($language) ?>"><span aria-hidden="true"><?= $this->e(strtoupper($code)) ?> —</span><span class="admin-visually-hidden"><?= $this->e($this->t('admin.translations.missing', ['language' => $language])) ?></span></span>
            <?php endif ?>
        </li>
        <?php endforeach ?>
    </ul>
</nav>
<?php endif ?>
