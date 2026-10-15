<?php

declare(strict_types=1);

namespace AtarCms\Content\ShareImage;

use AtarCms\Content\Entry;
use AtarCms\Content\EntryRepository;
use AtarCms\Http\RequestMemo;
use AtarCms\I18n\TextDirection;
use AtarCms\Kernel\Config;
use AtarCms\Media\Media;
use AtarCms\Media\MediaPath;
use AtarCms\Media\MediaRepository;
use AtarCms\Media\MediaStorage;
use AtarCms\Media\Share\FontGlyphs;
use AtarCms\Media\Share\ShareBrand;
use AtarCms\Media\Share\ShareCard;
use AtarCms\Media\Share\ShareCardInput;
use AtarCms\Media\Share\ShareFont;
use AtarCms\Settings\AtarLogo;
use AtarCms\Settings\SiteSettings;
use AtarCms\View\ThemeConfig;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The generated share images of entries: after a save (EntryService, outside its transaction) an entry of a routed
 * collection (not a plain single) with a main photo (MainPhoto) gets a 1200 × 630 card (ShareCard), titled by its title
 * for search engines or else by its own title (title()), in
 * storage/uploads/share/{id}-{fingerprint}.jpg, recorded in entries.share_image. The fingerprint covers everything
 * the picture shows, so an unchanged entry is not drawn again and a brand change is picked up on the next save (or by
 * bin/atar share-images:rebuild). Nothing here ever throws: a failure is logged and leaves the entry without a picture,
 * so og:image falls back to the main photo.
 */
final class ShareImages
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly ShareCard $card,
        private readonly SiteSettings $settings,
        private readonly Config $config,
        private readonly ThemeConfig $theme,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Makes, keeps or removes the entry's picture to match its saved state.
     *
     * @param ShareBrand|null $brand the brand of the entry's language (brand()); a caller handling many entries passes
     *                               it to read the settings once per language
     */
    public function sync(Entry $entry, ?ShareBrand $brand = null): ShareImageResult
    {
        return $this->attempt($entry, $brand, true);
    }

    /**
     * @param bool $retry when another writer changed the stored name meanwhile (the compare-and-set lost), reload the
     *                    entry and decide once more from the fresh row: the other writer may have recorded an older
     *                    picture (a rebuild that read the row before this save)
     */
    private function attempt(Entry $entry, ?ShareBrand $brand, bool $retry): ShareImageResult
    {
        $written = null;
        try {
            $title = self::title($entry);
            $photo = $entry->collection->route === null || $entry->collection->plain || $title === '' ? null : $this->mainPhoto(null, $entry);
            if ($photo === null) {
                return $this->clear($entry);
            }
            $brand ??= $this->brand($entry->locale);
            $rtl = TextDirection::of($entry->locale) === 'rtl';
            $name = $entry->id . '-' . self::fingerprint($title, $rtl, $photo, $brand) . '.jpg';
            $current = $entry->shareImage;
            if ($current === $name && is_file($this->storage->share($name))) {
                return new ShareImageResult(ShareImageOutcome::Unchanged, $name);
            }
            $this->storage->writeShare($name, $this->card->render(new ShareCardInput($this->storage->original($photo->path), $title, $brand, $rtl)));
            $written = $current === $name ? null : $name;
            if ($current !== $name && !$this->entries->setShareImage($entry->id, $name, $current)) {
                $fresh = $this->entries->find($entry->id);
                if ($fresh?->shareImage !== $name) {
                    $this->storage->deleteShare($name);
                }
                $written = null;
                if ($fresh === null) {
                    return new ShareImageResult(ShareImageOutcome::None, null);
                }

                return $retry ? $this->attempt($fresh, $brand, false) : new ShareImageResult(ShareImageOutcome::Unchanged, $fresh->shareImage);
            }
            $written = null;
            if ($current !== null && $current !== $name) {
                $this->deleteFile($current);
            }

            return new ShareImageResult(ShareImageOutcome::Made, $name);
        } catch (Throwable $e) {
            $this->logger->warning('Share image of entry {id} failed: {message}', ['id' => $entry->id, 'message' => $e->getMessage()]);
            try {
                if ($written !== null) {
                    // Drawn but never recorded: nothing would ever delete it.
                    $this->storage->deleteShare($written);
                }
                $this->clear($entry);
            } catch (Throwable) {
                // The warning above is what matters; the stale name only costs a lost picture.
            }

            return new ShareImageResult(ShareImageOutcome::Failed, null);
        }
    }

    /**
     * Deletes the picture of an entry that is gone (EntryService::delete(), after its transaction).
     */
    public function remove(Entry $entry): void
    {
        if ($entry->shareImage !== null) {
            $this->deleteFile($entry->shareImage);
        }
    }

    /**
     * The site's brand in $locale: its name and short name, the logo (the site icon, else the AtarCMS logo of the
     * site colour, Settings::accent()), the domain of app.url and the theme's layout.
     */
    public function brand(string $locale): ShareBrand
    {
        $settings = $this->settings->of($locale);
        $icon = $settings->iconId === null ? null : $this->media->find($settings->iconId);
        $accent = $settings->accent();

        return new ShareBrand(
            name: $this->settings->siteName($locale, $settings),
            shortName: $this->settings->shortName($locale, $settings),
            domain: self::domain($this->config->appUrl()),
            accent: $accent->logoColor(),
            logoFile: $icon !== null && $icon->isImage() ? $this->storage->original($icon->path) : AtarLogo::file($accent, 192),
            logoSource: $icon !== null && $icon->isImage() ? 'icon:' . $icon->id . ':' . $icon->path : 'atar:' . $accent->value,
            layout: $this->theme->shareLayout,
            rtl: TextDirection::of($locale) === 'rtl',
        );
    }

    /**
     * What og:image shows for the entry: its generated picture, else its main photo, else nothing.
     */
    public function choose(?ServerRequestInterface $request, Entry $entry): ShareImageChoice
    {
        if ($entry->shareImage !== null) {
            return new ShareImageChoice(generated: $entry->shareImage);
        }

        return new ShareImageChoice(photo: $this->mainPhoto($request, $entry));
    }

    /**
     * The entry's main photo: the first candidate (MainPhoto) that is an image; with a request each medium is memoised
     * like OgImage's ("media:{id}").
     */
    public function mainPhoto(?ServerRequestInterface $request, Entry $entry): ?Media
    {
        $candidates = MainPhoto::candidates($entry);
        if ($candidates === []) {
            return null;
        }
        if ($request === null) {
            return self::firstImage($candidates, $this->media->byIds($candidates));
        }
        foreach ($candidates as $id) {
            $media = RequestMemo::through($request, 'media:' . $id, fn(): ?Media => $this->media->find($id));
            if ($media instanceof Media && $media->isImage()) {
                return $media;
            }
        }

        return null;
    }

    /**
     * The main photos of a list of entries (the admin lists' thumbnails), with one media query for all of them.
     *
     * @param list<Entry> $entries
     *
     * @return array<int, Media> entry id => its main photo, in the order of $entries; entries without one are left out
     */
    public function mainPhotos(array $entries): array
    {
        $candidates = [];
        foreach ($entries as $entry) {
            $candidates[$entry->id] = MainPhoto::candidates($entry);
        }
        $all = array_merge(...array_values($candidates));
        if ($all === []) {
            return [];
        }
        $found = $this->media->byIds($all);
        $photos = [];
        foreach ($candidates as $id => $ids) {
            $photo = self::firstImage($ids, $found);
            if ($photo !== null) {
                $photos[$id] = $photo;
            }
        }

        return $photos;
    }

    /**
     * @param list<int> $candidates
     * @param array<int, Media> $found
     */
    private static function firstImage(array $candidates, array $found): ?Media
    {
        foreach ($candidates as $id) {
            if (isset($found[$id]) && $found[$id]->isImage()) {
                return $found[$id];
            }
        }

        return null;
    }

    /**
     * The host of $url as people read it: lower case, without "www." and the port, an internationalised domain in its
     * own script.
     */
    public static function domain(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'xn--')) {
            $unicode = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($unicode) ? $unicode : $host;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The title the picture shows: the title for search engines, else (when it is empty, or only of characters the
     * font lacks) the entry's own title; '' when neither has anything to draw.
     */
    private static function title(Entry $entry): string
    {
        $meta = FontGlyphs::clean(trim($entry->seo->metaTitle ?? ''), ShareFont::path());

        return $meta !== '' ? $meta : FontGlyphs::clean(trim($entry->title), ShareFont::path());
    }

    /**
     * 16 hex characters of a hash of everything the picture shows.
     */
    private static function fingerprint(string $title, bool $rtl, Media $photo, ShareBrand $brand): string
    {
        return substr(hash('sha256', json_encode([
            ShareCard::VERSION,
            $title,
            $rtl,
            $brand->rtl,
            $photo->id,
            $photo->path,
            $brand->layout->value,
            $brand->shownName(),
            $brand->domain,
            $brand->logoSource,
            $brand->accent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    private function clear(Entry $entry): ShareImageResult
    {
        $current = $entry->shareImage;
        if ($current === null) {
            return new ShareImageResult(ShareImageOutcome::None, null);
        }
        if ($this->entries->setShareImage($entry->id, null, $current)) {
            $this->deleteFile($current);
        }

        return new ShareImageResult(ShareImageOutcome::Removed, null);
    }

    private function deleteFile(string $name): void
    {
        if (MediaPath::isShareName($name)) {
            $this->storage->deleteShare($name);
        }
    }
}
