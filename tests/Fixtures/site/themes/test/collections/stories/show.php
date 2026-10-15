<?php $this->layout('layouts/default', ['title' => $seo->title, 'description' => $seo->description]); ?>
<?php $section = $this->terms($entry, 'sections')[0] ?? null; ?>
<nav class="trail"><?php foreach ($section === null ? [] : $this->termTrail($section) as $crumb): ?><a href="<?= $this->e($this->termUrl($crumb)) ?>"><?= $this->e($crumb->name) ?></a> / <?php endforeach ?></nav>
<div class="generic" data-collection="stories">
<h1><?= $this->e($entry->title) ?></h1>
</div>
