<?php
/**
 * The header's language dropdown: the current language named in itself, opening a list of every language linking to
 * this page in it (or its home page). A <details>: it works without JavaScript; theme.js closes it on Escape and on a
 * click elsewhere.
 *
 * @var AtarCms\View\Template $this
 * @var array<string, string> $alternates locale => URL
 */
if (count($alternates) < 2) {
    return;
}
$current = $this->locale();
$name = $this->languageName($current);
// A language's flag is assets/flags/{locale}.png (decorative: the name says it), shown round.
$flag = fn(string $code): string => is_file(__DIR__ . '/../assets/flags/' . $code . '.png')
    ? '<img class="flag" src="' . $this->e($this->asset('flags/' . $code . '.png')) . '" alt="" width="24" height="24">'
    : '';
$currentFlag = $flag($current);
// Without a flag the button names the current language short: two letters of its own name ("Fr"), one in a
// right-to-left script; the accessible name keeps it whole.
$short = mb_convert_case(mb_substr($name, 0, AtarCms\I18n\TextDirection::of($current) === 'rtl' ? 1 : 2), MB_CASE_TITLE);
?>
<details class="dropdown language-menu">
    <summary aria-label="<?= $this->e($this->t('site.languages')) ?>: <?= $this->e($name) ?>"><?php if ($currentFlag !== ''): ?><?= $currentFlag ?><?php else: ?><?= $this->partial('partials/icon', ['name' => 'languages']) ?><span<?= $this->langAttrs($current) ?>><?= $this->e($short) ?></span><?php endif ?><?= $this->partial('partials/icon', ['name' => 'chevron-down']) ?></summary>
    <ul class="dropdown-panel">
        <?php // The names carry their language and direction; the items follow the page, so they all line up the same way. ?>
        <?php foreach ($alternates as $code => $href): ?>
        <li><a href="<?= $this->e($href) ?>" hreflang="<?= $this->e($code) ?>"<?= $code === $current ? ' aria-current="true"' : '' ?>><?= $flag($code) ?><span<?= $this->langAttrs($code) ?>><?= $this->e($this->languageName($code)) ?></span><?= $code === $current ? $this->partial('partials/icon', ['name' => 'check']) : '' ?></a></li>
        <?php endforeach ?>
    </ul>
</details>
