<?php
/**
 * Recent activity: the newest rows of the activity history, each with the user's avatar, who did what to which item
 * (the words of the history screen, audit/subject.php) and when.
 *
 * @var AtarCms\View\Template $this
 * @var list<array{entry: AtarCms\Auth\AuditEntry, user: ?AtarCms\Auth\User}> $feed
 */
?>
<section class="admin-card admin-dash-feed" id="dashboard-feed" aria-labelledby="dashboard-feed-title">
    <div class="admin-dash-head">
        <h2 class="admin-dash-title" id="dashboard-feed-title"><?= $this->icon('history') ?><?= $this->e($this->t('admin.dashboard.feed.title')) ?></h2>
    </div>
    <?php if ($feed === []): ?>
    <p class="admin-dash-none"><?= $this->e($this->t('admin.audit.empty')) ?></p>
    <?php else: ?>
    <ol class="admin-dash-events">
        <?php foreach ($feed as ['entry' => $entry, 'user' => $user]): ?>
        <?php $actionKey = 'admin.audit.action.' . $entry->action; $item = $this->partial('audit/subject', ['entry' => $entry]); ?>
        <li class="admin-dash-event">
            <?php if ($user !== null): ?>
            <span class="admin-avatar admin-role admin-role-<?= $this->e($user->role) ?>" aria-hidden="true"><?php if ($user->avatarVersion !== null): ?><img class="admin-avatar-image" src="<?= $this->e($this->url('admin.avatar', ['id' => (string) $user->id]) . '?v=' . $user->avatarVersion) ?>" alt="" width="36" height="36"><?php else: ?><?= $this->e($user->initials()) ?><?php endif ?></span>
            <?php else: ?>
            <span class="admin-avatar" aria-hidden="true"><?= $this->icon('settings') ?></span>
            <?php endif ?>
            <div class="admin-dash-event-text">
                <p class="admin-dash-event-what"><strong><?= $this->e($user?->displayName() ?? $entry->userEmail ?? $this->t('admin.dashboard.feed.someone')) ?></strong> <?php if ($this->hasTranslation($actionKey)): ?><span title="<?= $this->e($entry->action) ?>"><?= $this->e($this->t($actionKey)) ?></span><?php else: ?><code><?= $this->e($entry->action) ?></code><?php endif ?></p>
                <?php if ($item !== ''): ?>
                <p class="admin-dash-event-item"><?= $item ?></p>
                <?php endif ?>
                <p class="admin-dash-event-time"><?= $this->e($this->readableDate($entry->createdAt)) ?></p>
            </div>
        </li>
        <?php endforeach ?>
    </ol>
    <?php endif ?>
    <a class="admin-dash-more" href="<?= $this->e($this->url('admin.audit')) ?>"><?= $this->e($this->t('admin.dashboard.feed.all')) ?></a>
</section>
