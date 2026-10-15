<?php
/**
 * The phone, email and messengers of the site settings as one list of links, each an icon and the value (written left
 * to right in every language): tel:, mailto:, and the messengers' chat links (WhatsApp wa.me, Telegram t.me/@name,
 * Viber viber://). The visible text is only the value, so each link is named for screen readers ("WhatsApp: +972…").
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Settings\Settings $settings
 */
$items = [];
if ($settings->phone !== null) {
    $items[] = ['phone', null, 'tel:' . preg_replace('/[^0-9+]/', '', $settings->phone), $settings->phone, $this->t('site.contacts.phone')];
}
if ($settings->email !== null) {
    $items[] = ['mail', null, 'mailto:' . $settings->email, $settings->email, $this->t('site.contacts.email')];
}
$values = ['whatsapp' => $settings->whatsapp, 'viber' => $settings->viber, 'telegram' => $settings->telegram === null ? null : '@' . $settings->telegram];
foreach ($settings->messengers as $link) {
    $items[] = [null, $link->network, $link->url, (string) ($values[$link->network] ?? ''), $this->t('site.network.' . $link->network)];
}
if ($items === []) {
    return;
}
?>
<ul class="contact-links">
    <?php foreach ($items as [$icon, $brand, $url, $value, $label]): ?>
    <li><a href="<?= $this->e($url) ?>" aria-label="<?= $this->e($label . ': ' . $value) ?>"<?= $brand !== null ? ' rel="noopener"' : '' ?>><?= $brand !== null ? $this->partial('partials/brand-icon', ['network' => $brand]) : $this->partial('partials/icon', ['name' => (string) $icon]) ?><span dir="ltr"><?= $this->e($value) ?></span></a></li>
    <?php endforeach ?>
</ul>
