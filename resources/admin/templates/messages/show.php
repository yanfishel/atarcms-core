<?php
/**
 * One message of the site's forms (opening it marks it read): the form, when it was received, the page it was sent
 * from and its e-mail copy; a table of its fields in the form's order (MessageFields: the e-mail and the phone as
 * links); the message text; "Delete…", "Mark as unread", Call (tel:, with a phone) and Reply (mailto:, with an
 * e-mail).
 *
 * @var AtarCms\View\Template $this
 * @var AtarCms\Messages\Message $message
 * @var ?string $formTitle the form's name; null on a site with the contact form only
 * @var list<array{name: string, label: string, value: string}> $rows
 */
$title = $this->t('admin.messages.show_title', ['name' => $message->sender()]);
$this->layout('layouts/admin', [
    'title' => $title,
    'breadcrumbs' => [[$this->t('admin.messages.title'), $this->url('admin.messages')], [$message->sender()]],
]);
$tel = $message->phone === null ? null : 'tel:' . preg_replace('/[^0-9+]/', '', $message->phone);
?>
<?= $this->partial('partials/page-header', ['title' => $title]) ?>
<article class="admin-card admin-message">
    <dl class="admin-message-meta">
        <?php if ($formTitle !== null): ?>
        <dt><?= $this->e($this->t('admin.messages.form')) ?></dt>
        <dd><?= $this->e($formTitle) ?></dd>
        <?php endif ?>
        <dt><?= $this->e($this->t('admin.messages.column.received')) ?></dt>
        <dd><?= $this->e($this->readableDate($message->createdAt)) ?></dd>
        <dt><?= $this->e($this->t('admin.messages.page')) ?></dt>
        <dd><a href="<?= $this->e($message->pageUrl) ?>" dir="ltr"><?= $this->e($message->pageUrl) ?></a><?= $this->multilingual() ? ' (' . $this->e($this->languageName($message->locale)) . ')' : '' ?></dd>
        <dt><?= $this->e($this->t('admin.messages.mail')) ?></dt>
        <dd><?= $this->e($message->mailedAt === null ? $this->t('admin.messages.not_mailed') : $this->t('admin.messages.mailed', ['time' => $this->readableDate($message->mailedAt)])) ?></dd>
    </dl>
    <?php if ($rows !== []): ?>
    <table class="admin-message-fields">
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr><th scope="row"><?= $this->e($row['label']) ?></th><td><?php if ($row['name'] === 'email' && $message->email !== null): ?><a href="mailto:<?= $this->e(rawurlencode($message->email)) ?>" dir="ltr"><?= $this->e($message->email) ?></a><?php elseif ($row['name'] === 'phone' && $tel !== null): ?><a href="<?= $this->e($tel) ?>" dir="ltr"><?= $this->e($message->phone) ?></a><?php else: ?><?= $this->e($row['value']) ?><?php endif ?></td></tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <?php endif ?>
    <?php if ($message->body !== null): ?>
    <div class="admin-message-body"><?= $this->e($message->body) ?></div>
    <?php endif ?>
</article>
<div class="admin-action-bar">
    <p class="admin-action-bar-status"></p>
    <div class="admin-action-bar-buttons">
        <a class="admin-button admin-button-danger" href="<?= $this->e($this->url('admin.messages.delete.confirm', ['id' => $message->id])) ?>"><?= $this->icon('trash-2') ?><?= $this->e($this->t('admin.messages.delete')) ?></a>
        <form method="post" action="<?= $this->e($this->url('admin.messages.unread', ['id' => $message->id])) ?>">
            <?= $this->csrfField() ?>
            <button type="submit" class="admin-button admin-button-secondary"><?= $this->icon('inbox') ?><?= $this->e($this->t('admin.messages.mark_unread')) ?></button>
        </form>
        <?php if ($tel !== null): ?>
        <a class="admin-button admin-button-secondary" href="<?= $this->e($tel) ?>"><?= $this->e($this->t('admin.messages.call')) ?></a>
        <?php endif ?>
        <?php if ($message->email !== null): ?>
        <a class="admin-button admin-button-primary" href="mailto:<?= $this->e(rawurlencode($message->email)) ?>?subject=<?= $this->e(rawurlencode($this->t('admin.messages.reply_subject'))) ?>"><?= $this->icon('arrow-right') ?><?= $this->e($this->t('admin.messages.reply')) ?></a>
        <?php endif ?>
    </div>
</div>
