<?php
/** @var AtarCms\View\Template $this */
/** @var string|null $errorKey */
/** @var string|null $googleUrl */
/** @var list<AtarCms\Auth\User> $devUsers active users offered for one-click sign-in (local environment only) */
/** @var array<string, string> $roles */
/** @var bool $loginLinkHint whether to name the console command that creates a login link (outside production) */
$this->layout('layouts/admin', ['title' => $this->t('admin.login.title')]);
?>
<section class="admin-login">
    <p class="admin-login-site"><?= trim($this->partial('partials/brand-mark')) ?><?= $this->e($this->siteName()) ?></p>
    <h1><?= $this->e($this->t('admin.login.title')) ?></h1>
    <p class="admin-muted"><?= $this->e($this->t('admin.login.intro')) ?></p>
    <?php if ($errorKey !== null): ?>
    <div class="admin-alert" role="alert"><?= $this->icon('alert-circle') ?><p><?= $this->e($this->t($errorKey)) ?></p></div>
    <?php endif ?>
    <?php if ($googleUrl !== null): ?>
    <a class="admin-button admin-button-primary admin-login-button" href="<?= $this->e($googleUrl) ?>"><?= $this->icon('log-in') ?><?= $this->e($this->t('admin.login.google')) ?></a>
    <?php else: ?>
    <p class="admin-muted"><?= $this->e($this->t('admin.login.google_not_configured')) ?></p>
    <?php if ($loginLinkHint): ?>
    <p class="admin-muted"><?= $this->e($this->t('admin.login.login_link_command')) ?> <code>bin/atar user:login-link</code></p>
    <?php endif ?>
    <?php endif ?>
    <?php if ($devUsers !== []): ?>
    <form class="admin-login-dev" method="post" action="<?= $this->e($this->url('admin.login.dev')) ?>">
        <?= $this->csrfField() ?>
        <p class="admin-muted"><?= $this->e($this->t('admin.login.dev')) ?></p>
        <?php foreach ($devUsers as $devUser): ?>
        <button class="admin-button admin-button-secondary admin-login-button" type="submit" name="user" value="<?= $devUser->id ?>"><?= $this->icon('log-in') ?><?= $this->e($devUser->displayName()) ?> <span class="admin-chip"><?= $this->e($this->label($roles[$devUser->role] ?? $devUser->role)) ?></span></button>
        <?php endforeach ?>
    </form>
    <?php endif ?>
</section>
