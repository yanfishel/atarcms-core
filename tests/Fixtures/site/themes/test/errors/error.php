<?php $this->layout('layouts/default', ['title' => $reason]); ?>
<p class="error-generic"><?= $this->e($status) ?> <?= $this->e($reason) ?></p>
