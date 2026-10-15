<?php $this->layout('layouts/default', ['title' => $seo?->title ?? $collection->label, 'description' => $seo?->description]); ?>
<div class="generic" data-collection="<?= $this->e($collection->name) ?>">
<?php if ($entry === null): ?>
<p class="empty">Nothing here yet.</p>
<?php else: ?>
<h1><?= $this->e($entry->title) ?></h1>
<?php endif ?>
</div>
