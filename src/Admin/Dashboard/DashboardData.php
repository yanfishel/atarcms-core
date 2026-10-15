<?php

declare(strict_types=1);

namespace AtarCms\Admin\Dashboard;

use AtarCms\Admin\ContentLocale;
use AtarCms\Auth\AuditEntry;
use AtarCms\Auth\AuditLog;
use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryListing;
use AtarCms\Content\RevisionRepository;
use AtarCms\Content\TermRepository;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\SiteTime;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaService;
use AtarCms\Messages\Message;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\MessageService;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gathers the dashboard of the request's user: a few aggregate queries, each block only for who may see it (entries
 * over the collections the user may view, messages, media and the activity log by their permissions).
 */
final readonly class DashboardData
{
    /** Entries per tab of "In progress". */
    public const int PER_LIST = 5;

    public const int MESSAGES = 3;

    public const int TAGS = 8;

    public const int FEED = 6;

    /** The days of the KPI tiles' sparklines and their "in the last N days". */
    public const int TILE_DAYS = 30;

    /** The categorical colour slots; the last one is the neutral for every later collection. */
    public const int TONES = 8;

    public function __construct(
        private Collections $collections,
        private EntryAccess $access,
        private EntryListing $listing,
        private RevisionRepository $revisions,
        private TermRepository $terms,
        private MessageRepository $messages,
        private MediaRepository $media,
        private AuditLog $audit,
        private UserRepository $users,
        private Authorizer $authorizer,
        private Locales $locales,
        private ContentLocale $contentLocale,
        private SiteSettings $settings,
        private SiteTime $time,
        private Clock $clock,
    ) {}

    public function for(ServerRequestInterface $request, int $range): Dashboard
    {
        $user = CurrentUser::require($request);
        $now = $this->clock->now();
        $viewable = $this->access->viewable($user, $this->collections);
        $names = array_map(static fn(Collection $c): string => $c->name, $viewable);
        $tiles = new DayBuckets($this->time, $now, self::TILE_DAYS);
        $buckets = new DayBuckets($this->time, $now, $range);

        $published = $pending = $activity = null;
        $statuses = [];
        if ($viewable !== []) {
            $counts = $this->listing->statusCounts($names, $now);
            // The Published tile counts what the Content card shows: singles (one fixed page each) stay out of both.
            $content = array_values(array_map(
                static fn(Collection $c): string => $c->name,
                array_filter($viewable, static fn(Collection $c): bool => !$c->single),
            ));
            $days = $tiles->count($this->listing->publishedSlots($content, $tiles->since(), $now));
            $published = [
                'count' => array_sum(array_column(array_intersect_key($counts, array_flip($content)), 'published')),
                'recent' => array_sum($days),
                'days' => $days,
            ];
            // Drafts and waiting entries of every viewable collection, as In progress lists them.
            $pending = ['drafts' => array_sum(array_column($counts, 'draft')), 'scheduled' => array_sum(array_column($counts, 'scheduled'))];
            $activity = [
                'edits' => $buckets->count($this->revisions->editSlots($names, $buckets->since())),
                'publications' => $buckets->count($this->listing->publishedSlots($names, $buckets->since(), $now)),
            ];
            foreach ($viewable as $collection) {
                // A single is one page: its row would say nothing about the site's content.
                if (!$collection->single) {
                    $statuses[$collection->name] = ['collection' => $collection, ...($counts[$collection->name] ?? ['published' => 0, 'scheduled' => 0, 'draft' => 0])];
                }
            }
        }

        $progress = [
            'recent' => $this->listing->recentlyUpdated($names, self::PER_LIST),
            'drafts' => $this->listing->drafts($names, self::PER_LIST),
            'scheduled' => $this->listing->scheduled($names, $now, self::PER_LIST),
        ];
        $editable = [];
        foreach (array_merge(...array_values($progress)) as $entry) {
            $editable[$entry->id] = $this->access->canEdit($user, $entry);
        }

        return new Dashboard(
            user: $user,
            today: DayBuckets::label($this->time->format($now, 'Y-m-d'), RequestLocale::of($request)->code, 'yMMMMEEEEd'),
            tones: $this->tones(),
            // A plain single's button edits a page that is always published: only for who may change it.
            quick: array_values(array_filter($viewable, fn(Collection $c): bool => $c->plain
                ? $this->access->canEditPublished($user, $c)
                : $this->access->canCreate($user, $c))),
            published: $published,
            pending: $pending,
            messages: $this->authorizer->can($user, MessageService::PERMISSION) ? $this->messages($tiles) : null,
            media: $this->authorizer->can($user, MediaService::UPLOAD) ? $this->media->totals() : null,
            buckets: $buckets,
            range: $range,
            activity: $activity,
            statuses: $statuses,
            translations: $this->translations($viewable),
            tags: $this->tags($request, $viewable, $names),
            progress: $progress,
            editable: $editable,
            feed: $this->authorizer->can($user, AuditLog::PERMISSION) ? $this->feed() : null,
            // A reminder for those who may switch it off: a site hidden from search engines stays out of them.
            hidden: $this->authorizer->can($user, SettingsService::PERMISSION) && $this->settings->of('')->hideFromSearch,
        );
    }

    /**
     * @return array<string, int>
     */
    private function tones(): array
    {
        $tones = [];
        foreach (array_values($this->collections->all()) as $index => $collection) {
            $tones[$collection->name] = min($index + 1, self::TONES);
        }

        return $tones;
    }

    /**
     * @return array{unread: int, recent: int, days: list<int>, latest: list<Message>}
     */
    private function messages(DayBuckets $tiles): array
    {
        $days = $tiles->count($this->messages->receivedSlots($tiles->since()));

        return [
            'unread' => $this->messages->unreadCount(),
            'recent' => array_sum($days),
            'days' => $days,
            'latest' => $this->messages->latest(self::MESSAGES, 0),
        ];
    }

    /**
     * @param list<Collection> $viewable
     *
     * @return array{groups: int, locales: array<string, int>}|null
     */
    private function translations(array $viewable): ?array
    {
        $translatable = array_values(array_map(
            static fn(Collection $c): string => $c->name,
            array_filter($viewable, static fn(Collection $c): bool => $c->translatable),
        ));
        if (count($this->locales->all()) < 2 || $translatable === []) {
            return null;
        }
        $coverage = $this->listing->translationCoverage($translatable);
        $locales = [];
        foreach ($this->locales->all() as $locale) {
            $locales[$locale] = $coverage['locales'][$locale] ?? 0;
        }

        return ['groups' => $coverage['groups'], 'locales' => $locales];
    }

    /**
     * @param list<Collection> $viewable
     * @param list<string> $names
     *
     * @return list<array{name: string, count: int}>|null
     */
    private function tags(ServerRequestInterface $request, array $viewable, array $names): ?array
    {
        $taxonomies = [];
        foreach ($viewable as $collection) {
            foreach ($collection->termTaxonomies() as $taxonomy) {
                if ($collection->tagsField($taxonomy) !== null) {
                    $taxonomies[$taxonomy] = $taxonomy;
                }
            }
        }

        return $taxonomies === [] ? null : $this->terms->popular(array_values($taxonomies), $this->contentLocale->current($request), $names, self::TAGS);
    }

    /**
     * @return list<array{entry: AuditEntry, user: ?User}>
     */
    private function feed(): array
    {
        $entries = $this->audit->latest(self::FEED);
        $ids = [];
        foreach ($entries as $entry) {
            if ($entry->userId !== null) {
                $ids[$entry->userId] = $entry->userId;
            }
        }
        $users = $this->users->byIds(array_values($ids));

        return array_map(static fn(AuditEntry $entry): array => [
            'entry' => $entry,
            'user' => $entry->userId === null ? null : ($users[$entry->userId] ?? null),
        ], $entries);
    }
}
