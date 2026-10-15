<?php
/**
 * The latest messages of the site's forms: the sender, the first line and the time, unread ones marked.
 *
 * @var AtarCms\View\Template $this
 * @var list<AtarCms\Messages\Message> $messages
 */
?>
<section class="admin-card admin-dash-card" id="dashboard-messages" aria-labelledby="dashboard-messages-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-messages-title"><?= $this->icon('inbox') ?><?= $this->e($this->t('admin.dashboard.latest.title')) ?></h2>
    </div>
    <?php if ($messages === []): ?>
    <p class="admin-dash-none"><?= $this->e($this->t('admin.messages.empty')) ?></p>
    <?php else: ?>
    <ul class="admin-dash-messages">
        <?php foreach ($messages as $message): ?>
        <?php $unread = $message->readAt === null; ?>
        <li class="admin-dash-message<?= $unread ? ' admin-dash-message-unread' : '' ?>">
            <a class="admin-dash-message-link" href="<?= $this->e($this->url('admin.messages.show', ['id' => (string) $message->id])) ?>">
                <?php // The row follows the interface direction; the visitor's texts are isolated in their own direction (bdi). ?>
                <span class="admin-dash-message-name"><bdi><?= $this->e($message->sender()) ?></bdi><?php if ($unread): ?><span class="admin-visually-hidden"> (<?= $this->e($this->t('admin.dashboard.latest.unread')) ?>)</span><?php endif ?></span>
                <span class="admin-dash-message-text"><bdi><?= $this->e($message->body !== null ? trim(strtok($message->body, "\n") ?: '') : $message->preview()) ?></bdi></span>
            </a>
            <span class="admin-dash-message-time"><?= $this->e($this->readableDate($message->createdAt)) ?></span>
        </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>
    <a class="admin-dash-more" href="<?= $this->e($this->url('admin.messages')) ?>"><?= $this->e($this->t('admin.dashboard.latest.all')) ?></a>
</section>
