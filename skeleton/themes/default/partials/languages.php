<?php
/**
 * The language switch: each language named in itself, linking to this page in that language (or its home page).
 *
 * @var AtarCms\View\Template $this
 * @var array<string, string> $alternates locale => URL
 */
if (count($alternates) < 2) {
    return;
}
?>
<ul class="languages" aria-label="<?= $this->e($this->t('site.languages')) ?>">
    <?php foreach ($alternates as $code => $href): ?>
    <li><a href="<?= $this->e($href) ?>" hreflang="<?= $this->e($code) ?>"<?= $code === $this->locale() ? ' aria-current="true"' : '' ?>><span<?= $this->langAttrs($code) ?>><?= $this->e($this->languageName($code)) ?></span></a></li>
    <?php endforeach ?>
</ul>
