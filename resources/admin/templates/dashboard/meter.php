<?php
/**
 * A meter's bar (translations, tags): the track and the filled part from the inline start, hidden from screen
 * readers (the row says the numbers in words).
 *
 * @var AtarCms\View\Template $this
 * @var int $value
 * @var int $scale
 * @var bool $rtl
 */
?>
<svg class="admin-dash-meter-bar" viewBox="0 0 <?= $this->e((string) $scale) ?> 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="admin-dash-meter-track" x="0" y="0" width="<?= $this->e((string) $scale) ?>" height="1"/><?php if ($value > 0): ?><rect class="admin-dash-meter-fill" x="<?= $this->e((string) ($rtl ? $scale - min($value, $scale) : 0)) ?>" y="0" width="<?= $this->e((string) min($value, $scale)) ?>" height="1"/><?php endif ?></svg>
