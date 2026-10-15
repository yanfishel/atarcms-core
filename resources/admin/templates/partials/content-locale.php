<?php
/**
 * The top bar's content language switch, on every admin page of a site with more than one language: the trigger
 * shows the current language's flag alone, an icon button as the other top-bar triggers (without a flag the languages
 * icon and its pill), the panel lists the site's languages, each with its flag and named in
 * itself, as plain POST forms (no script) to POST /admin/preferences/content-locale with the page to return to
 * (ContentLocaleSwitch keeps the screen in the new language). In the linked mode (linkedLanguage()) it is the one
 * language switch of the admin, the interface included.
 *
 * @var AtarCms\View\Template $this
 * @var string $current the page's content language
 * @var list<string> $locales the site's languages
 */
// In the linked mode the menu switches the interface too: "Language: …" rather than "Content language: …".
$name = $this->t($this->linkedLanguage() ? 'admin.content_locale.language' : 'admin.content_locale.current', ['language' => $this->languageName($current)]);
$return = $this->requestTarget();
?>
<details class="admin-menu admin-content-locale" data-menu>
    <?php // The current language's flag alone; without flags the icon and its pill. ?>
    <?php $currentFlag = $this->languageFlag($current); ?>
    <summary class="admin-menu-trigger" title="<?= $this->e($name) ?>" aria-label="<?= $this->e($name) ?>"><?php if ($currentFlag !== null): ?><img class="admin-locale-flag admin-locale-flag-large" src="<?= $this->e($currentFlag) ?>" alt="" width="22" height="22"><?php else: ?><?= $this->icon('languages') ?><?= trim($this->partial('partials/locale-badge', ['locale' => $current, 'title' => $name, 'announce' => false, 'keep' => true])) ?><?php endif ?></summary>
    <div class="admin-menu-panel">
        <ul class="admin-menu-list">
            <?php foreach ($locales as $code): ?>
            <li>
                <form class="admin-menu-form" method="post" action="<?= $this->e($this->url('admin.preferences.content_locale')) ?>">
                    <?= $this->csrfField() ?>
                    <input type="hidden" name="locale" value="<?= $this->e($code) ?>">
                    <input type="hidden" name="return" value="<?= $this->e($return) ?>">
                    <?php $isCurrent = $code === $current; ?>
                    <button type="submit" class="admin-menu-item"<?= $isCurrent ? ' aria-current="true"' : '' ?>><?php $flag = $this->languageFlag($code); ?><?= $flag === null ? '' : '<img class="admin-locale-flag" src="' . $this->e($flag) . '" alt="" width="20" height="20">' ?><span<?= $this->langAttrs($code) ?>><?= $this->e($this->languageName($code)) ?></span><?= $isCurrent ? $this->icon('check') : '' ?></button>
                </form>
            </li>
            <?php endforeach ?>
        </ul>
    </div>
</details>
