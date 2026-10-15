<?php
/**
 * The contact details of the site settings (the contacts page, the side column): the address and opening hours under
 * their labels (several lines each), then the phone, email and messengers as icon links (partials/contact-links.php),
 * each only when set. With $messengers false the messengers are left out (the side column). Every value is user
 * text: escape it.
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Settings\Settings $settings
 * @var bool $messengers whether to list the messengers too (default true)
 */
$links = ($messengers ?? true) ? $settings : new AtarCms\Settings\Settings(phone: $settings->phone, email: $settings->email);
?>
<?php if ($settings->address !== null || $settings->openingHours !== null): ?>
<dl class="contact-list">
    <?php if ($settings->address !== null): ?>
    <div class="contact-item">
        <dt><?= $this->partial('partials/icon', ['name' => 'map-pin']) ?><?= $this->e($this->t('site.contacts.address')) ?></dt>
        <dd><?= nl2br($this->e($settings->address)) ?></dd>
    </div>
    <?php endif ?>
    <?php if ($settings->openingHours !== null): ?>
    <div class="contact-item">
        <dt><?= $this->partial('partials/icon', ['name' => 'clock']) ?><?= $this->e($this->t('site.contacts.hours')) ?></dt>
        <dd><?= nl2br($this->e($settings->openingHours)) ?></dd>
    </div>
    <?php endif ?>
</dl>
<?php endif ?>
<?= $this->partial('partials/contact-links', ['settings' => $links]) ?>
