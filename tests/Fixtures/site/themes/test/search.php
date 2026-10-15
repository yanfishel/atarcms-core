<?php $this->layout('layouts/default', ['title' => 'Search', 'robots' => 'noindex']); ?>
<form class="search" action="<?= $this->e($this->url('search')) ?>"><input name="q" value="<?= $this->e($query) ?>"></form>
<?php if ($tooShort): ?><p class="too-short">Type a longer word</p><?php endif ?>
<p class="total"><?= $this->e($total) ?></p>
<ol class="results"><?php foreach ($results as $result): ?><li><a href="<?= $this->e($result->url) ?>"><?= $this->e($result->entry->title) ?></a> <span class="description"><?= $this->e($result->description) ?></span></li><?php endforeach ?></ol>
<p class="pages"><?= $this->e($page) ?>/<?= $this->e($pages) ?></p>
