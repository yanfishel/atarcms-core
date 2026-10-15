<?php
/**
 * A network's brand icon from the theme's brand sprite (assets/brands.svg: Simple Icons, CC0; see brands.LICENSE).
 * Networks Simple Icons lacks (Rutube, Dzen, LinkedIn) show a neutral globe; the Telegram channel shows Telegram.
 *
 * @var AtarCms\View\Template $this
 * @var string $network a Settings::SOCIAL_NETWORKS or Settings::MESSENGERS name
 */
$symbol = ['telegram_channel' => 'telegram', 'rutube' => 'globe', 'dzen' => 'globe', 'linkedin' => 'globe'][$network] ?? $network;
?>
<svg class="icon icon-brand" aria-hidden="true" focusable="false"><use href="<?= $this->e($this->asset('brands.svg')) ?>#<?= $this->e($symbol) ?>"></use></svg>
