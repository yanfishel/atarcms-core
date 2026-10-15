<?php $this->layout('layouts/default', ['title' => 'Menus']); ?>
<nav class="menu-main"><?= $this->partial('partials/menu', ['links' => $this->menu('main')]) ?></nav>
<nav class="menu-footer"><?= $this->partial('partials/menu', ['links' => $this->menu('footer')]) ?></nav>
<?php if (!empty($repeat)): ?><nav class="menu-main-again"><?= $this->partial('partials/menu', ['links' => $this->menu('main')]) ?></nav><?php endif ?>
