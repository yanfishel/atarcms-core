<?php

declare(strict_types=1);

namespace AtarCms\Admin\Dashboard;

use AtarCms\Auth\AuditEntry;
use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Entry;
use AtarCms\Messages\Message;

/**
 * What the dashboard shows one user: each block is null (or empty) when the user may not see it or the site has
 * nothing of its kind.
 */
final readonly class Dashboard
{
    /**
     * @param array<string, int> $tones collection name => its colour slot (1–8) by its place in the config
     * @param list<Collection> $quick the collections the user may add to (a single: may edit)
     * @param array{count: int, recent: int, days: list<int>}|null $published public entries, those of the last 30 days, per day
     * @param array{drafts: int, scheduled: int}|null $pending
     * @param array{unread: int, recent: int, days: list<int>, latest: list<Message>}|null $messages
     * @param array{count: int, bytes: int}|null $media
     * @param array{edits: list<int>, publications: list<int>}|null $activity per column of $buckets
     * @param array<string, array{collection: Collection, published: int, scheduled: int, draft: int}> $statuses
     * @param array{groups: int, locales: array<string, int>}|null $translations every site language, in the site's order
     * @param list<array{name: string, count: int}>|null $tags null when no viewable collection has a tags field
     * @param array{recent: list<Entry>, drafts: list<Entry>, scheduled: list<Entry>} $progress
     * @param array<int, bool> $editable entry id => the user may edit it
     * @param list<array{entry: AuditEntry, user: ?User}>|null $feed
     */
    public function __construct(
        public User $user,
        public string $today,
        public array $tones,
        public array $quick,
        public ?array $published,
        public ?array $pending,
        public ?array $messages,
        public ?array $media,
        public DayBuckets $buckets,
        public int $range,
        public ?array $activity,
        public array $statuses,
        public ?array $translations,
        public ?array $tags,
        public array $progress,
        public array $editable,
        public ?array $feed,
        public bool $hidden,
    ) {}
}
