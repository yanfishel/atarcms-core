<?php
/**
 * The theme dropdown: Light, Dark or System (the device's choice). It needs JavaScript (theme.js stores the choice,
 * the bootstrap in <head> applies it before the first paint), so it shows only under .js; without it the site follows
 * the system.
 *
 * @var AtarCms\View\Template $this
 */
$choices = ['light' => 'sun', 'dark' => 'moon', 'system' => 'monitor'];
?>
<details class="dropdown theme-menu">
    <summary aria-label="<?= $this->e($this->t('site.theme.label')) ?>" data-theme-current><?php foreach ($choices as $choice => $icon): ?><span data-theme-icon="<?= $this->e($choice) ?>"<?= $choice === 'system' ? '' : ' hidden' ?>><?= $this->partial('partials/icon', ['name' => $icon]) ?></span><?php endforeach ?></summary>
    <ul class="dropdown-panel">
        <?php foreach ($choices as $choice => $icon): ?>
        <li><button type="button" data-theme-choice="<?= $this->e($choice) ?>" aria-pressed="<?= $choice === 'system' ? 'true' : 'false' ?>"><?= $this->partial('partials/icon', ['name' => $icon]) ?><?= $this->e($this->t('site.theme.' . $choice)) ?></button></li>
        <?php endforeach ?>
    </ul>
</details>
