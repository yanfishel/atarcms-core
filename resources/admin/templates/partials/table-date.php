<?php
/**
 * A date and time in a table cell: the day in words on one line that never breaks (readableDate() without the
 * time: the interface language, the site zone), the time under it, smaller. Nothing for null.
 *
 * @var AtarCms\View\Template $this
 * @var DateTimeInterface|null $time
 */
?>
<?php if ($time !== null): ?>
<span class="admin-table-date"><?= $this->e($this->readableDate($time, false)) ?></span><span class="admin-table-time"><?= $this->e($this->date($time, 'H:i')) ?></span>
<?php endif ?>
