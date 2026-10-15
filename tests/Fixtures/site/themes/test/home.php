<?php $this->layout('layouts/default', ['title' => $title]); ?>
<h1><?= $this->e($title) ?></h1>
<p class="greeting"><?= $this->e($this->t('site.greeting')) ?></p>
<a href="<?= $this->e($this->url('news.show', ['slug' => 'привет'])) ?>">News</a>
