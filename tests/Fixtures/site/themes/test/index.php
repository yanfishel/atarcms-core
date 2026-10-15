<?php $this->layout('layouts/default', ['title' => $collection->label]); ?>
<ul class="generic-entries" data-collection="<?= $this->e($collection->name) ?>"><?php foreach ($page->entries as $entry): ?><li><?= $this->e($entry->title) ?></li><?php endforeach ?></ul>
