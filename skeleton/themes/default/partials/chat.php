<?php
/**
 * The chat button (the contact page's "Show a chat button", off by default): a round button in the bottom end corner that
 * opens the filled messengers (WhatsApp, Viber, Telegram) as plain links; with one messenger the button is that link.
 * No widget script and no cookie: the links open the messenger. The panel is a <details>, so it works without
 * JavaScript; js/theme.js closes it on Escape and on a click elsewhere, like the header dropdowns.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Settings\SocialLink> $messengers the filled messengers (Settings::$messengers), at least one
 * @var bool $raised whether the accessibility toolbar's button holds the bottom end corner: the chat button sits above
 *      it (site.css); the bottom start corner is the cookie banner's
 */
$open = $this->t('site.chat.open');
?>
<?php if (count($messengers) === 1): ?>
<?php $only = $messengers[0]; $label = $this->t('site.chat.via', ['network' => $this->t('site.network.' . $only->network)]); ?>
<div class="chat"<?= $raised ? ' data-chat-raised' : '' ?>><a class="chat-trigger" href="<?= $this->e($only->url) ?>" rel="noopener" aria-label="<?= $this->e($label) ?>" title="<?= $this->e($label) ?>"><?= trim($this->partial('partials/brand-icon', ['network' => $only->network])) ?></a></div>
<?php else: ?>
<details class="chat"<?= $raised ? ' data-chat-raised' : '' ?>>
    <summary class="chat-trigger" aria-label="<?= $this->e($open) ?>" title="<?= $this->e($open) ?>"><?= trim($this->partial('partials/icon', ['name' => 'message-circle'])) ?></summary>
    <div class="chat-panel">
        <p class="chat-title"><?= $this->e($open) ?></p>
        <ul class="chat-links">
            <?php foreach ($messengers as $link): ?>
            <li><a href="<?= $this->e($link->url) ?>" rel="noopener"><?= trim($this->partial('partials/brand-icon', ['network' => $link->network])) ?><?= $this->e($this->t('site.network.' . $link->network)) ?></a></li>
            <?php endforeach ?>
        </ul>
    </div>
</details>
<?php endif ?>
