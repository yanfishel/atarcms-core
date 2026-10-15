<?php
/**
 * A decorative icon of the theme's sprite (assets/icons.svg, Lucide symbols, ISC licence in icons.LICENSE).
 *
 * @var AtarCms\View\Template $this
 * @var string $name a symbol id of the sprite
 */
?>
<svg class="icon" aria-hidden="true" focusable="false"><use href="<?= $this->e($this->asset('icons.svg')) ?>#<?= $this->e($name) ?>"></use></svg>
