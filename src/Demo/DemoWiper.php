<?php

declare(strict_types=1);

namespace AtarCms\Demo;

use AtarCms\Content\EntryRepository;
use AtarCms\Content\Menu\MenuRepository;
use AtarCms\Content\TermRepository;
use AtarCms\Database\Connection;
use AtarCms\Media\MediaFolderRepository;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Messages\MessageRepository;
use AtarCms\Search\SearchRepository;
use AtarCms\Settings\SettingsRepository;
use AtarCms\Settings\SiteSettings;

/**
 * Empties the site's content for bin/atar demo:seed: entries (with their revisions, terms, media references and
 * search rows), terms, menu items, media rows and folders, messages and the site settings except KEPT_SETTINGS, in one
 * transaction; then every uploaded file, generated share image and cached image size. Users, their preferences and
 * sessions, avatars, the activity history and the migrations stay.
 */
final readonly class DemoWiper
{
    /**
     * The settings that survive (the owner's choices): the "hide from search engines", accessibility toolbar and chat
     * button switches, the toolbar's corner, the verification codes, the analytics counters and the advertising
     * pixels (the cookie banner's text is per-language content and goes).
     */
    public const array KEPT_SETTINGS = [
        SiteSettings::HIDE_FROM_SEARCH,
        SiteSettings::ACCESSIBILITY_TOOLBAR,
        SiteSettings::ACCESSIBILITY_TOOLBAR_POSITION,
        SiteSettings::CHAT_BUTTON,
        SiteSettings::YANDEX_VERIFICATION,
        SiteSettings::GOOGLE_VERIFICATION,
        SiteSettings::YANDEX_METRICA,
        SiteSettings::YANDEX_WEBVISOR,
        SiteSettings::GOOGLE_ANALYTICS,
        SiteSettings::META_PIXEL,
        SiteSettings::GOOGLE_ADS,
        SiteSettings::VK_PIXEL,
        SiteSettings::TIKTOK_PIXEL,
    ];

    public function __construct(
        private Connection $db,
        private EntryRepository $entries,
        private TermRepository $terms,
        private MenuRepository $menus,
        private MediaRepository $media,
        private MediaFolderRepository $folders,
        private MessageRepository $messages,
        private SearchRepository $search,
        private SettingsRepository $settings,
        private MediaStorage $storage,
    ) {}

    /**
     * What wipe() would delete.
     *
     * @return array{entries: int, terms: int, menu_items: int, media: int, messages: int}
     */
    public function counts(): array
    {
        return [
            'entries' => $this->entries->countAll(),
            'terms' => $this->terms->countAll(),
            'menu_items' => $this->menus->countAll(),
            'media' => $this->media->countAll(),
            'messages' => $this->messages->count(),
        ];
    }

    /**
     * @return array{entries: int, terms: int, menu_items: int, media: int, messages: int, files: int}
     */
    public function wipe(): array
    {
        $counts = $this->db->transaction(function (): array {
            // Entries first: their media references keep files from being deleted; media before their folders.
            $counts = [
                'entries' => $this->entries->deleteAll(),
                'terms' => $this->terms->deleteAll(),
                'menu_items' => $this->menus->deleteAll(),
                'media' => $this->media->deleteAll(),
                'messages' => $this->messages->deleteAll(),
            ];
            $this->folders->deleteAll();
            $this->settings->deleteExcept(self::KEPT_SETTINGS);

            return $counts;
        });
        $this->search->clear();

        return [
            'entries' => $counts['entries'],
            'terms' => $counts['terms'],
            'menu_items' => $counts['menu_items'],
            'media' => $counts['media'],
            'messages' => $counts['messages'],
            'files' => $this->storage->purge(),
        ];
    }
}
