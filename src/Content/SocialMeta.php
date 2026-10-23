<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Content\StructuredData\StructuredData;
use AtarCms\Http\CanonicalUrl;
use AtarCms\I18n\RequestLocale;
use AtarCms\Media\OgImage;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Open Graph and Twitter card tags of a public page, printed by the socialMeta() theme helper. Only pages with a
 * canonical URL (CanonicalUrl: entries, archives, term pages) get them, so previews, search and error pages have none;
 * og:url is that canonical URL. What the page does not give falls back to the site settings of its locale (the site
 * title, the default description and sharing image). The page's schema.org JSON-LD (StructuredData) follows the tags,
 * with the same description and image.
 */
final class SocialMeta
{
    public function __construct(
        private readonly OgImage $images,
        private readonly SiteSettings $settings,
        private readonly StructuredData $structuredData,
    ) {}

    /**
     * @param string|null $title the page title; when empty the site title of the settings, else the site name
     *                           (SiteSettings::siteName())
     * @param string|null $description og:description; when empty the default description of the settings, else left out
     * @param Entry|null $entry the entry the page shows: og:type "article" (unless it belongs to a single()
     *                          collection) and its sharing image as og:image (OgImage: the generated share image with
     *                          its size, else the main photo; else the default sharing image)
     * @param string|null $imageVariant the image variant of og:image (the original when null)
     */
    public function tags(ServerRequestInterface $request, ?string $title, ?string $description = null, ?Entry $entry = null, ?string $imageVariant = null): string
    {
        $canonical = CanonicalUrl::of($request);
        if ($canonical === null) {
            return '';
        }
        $site = $this->settings->forRequest($request);
        $siteName = $this->settings->siteName(RequestLocale::of($request)->code, $site);
        $title = trim($title ?? '');
        $title = $title !== '' ? $title : ($site->metaTitle ?? $siteName);
        $description = trim($description ?? '');
        $description = $description !== '' ? $description : ($site->metaDescription ?? '');
        $image = $entry === null ? null : $this->images->url($request, $entry, $imageVariant);
        if ($image === null && $site->ogImageId !== null) {
            $image = $this->images->byId($request, $site->ogImageId, $imageVariant);
        }
        $properties = [
            'og:type' => $entry !== null && !$entry->collection->single ? 'article' : 'website',
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $canonical->url,
            'og:site_name' => $siteName,
            'og:locale' => self::ogLocale(RequestLocale::of($request)->code),
            'og:image' => $image ?? '',
        ];
        if ($image !== null && $entry?->shareImage !== null) {
            // The generated share image: its size is known.
            $properties['og:image:width'] = (string) OgImage::GENERATED_SIZE[0];
            $properties['og:image:height'] = (string) OgImage::GENERATED_SIZE[1];
        }
        $tags = [];
        foreach ($properties as $property => $content) {
            if ($content !== '') {
                $tags[] = sprintf('<meta property="%s" content="%s">', $property, self::escape($content));
            }
        }
        $tags[] = sprintf('<meta name="twitter:card" content="%s">', $image === null ? 'summary' : 'summary_large_image');
        $structuredData = $this->structuredData->script($request, $entry, $siteName, $title, $description, $image);
        if ($structuredData !== '') {
            $tags[] = $structuredData;
        }

        return implode("\n", $tags);
    }

    /**
     * The Open Graph form of a site locale code: "pt-br" → "pt_BR", "en" → "en". A subtag that is not a two-letter
     * region ("zh-hans", "es-419") is left out.
     */
    public static function ogLocale(string $locale): string
    {
        $parts = explode('-', $locale, 2);
        $region = $parts[1] ?? '';

        return preg_match('/^[a-z]{2}$/', $region) === 1 ? $parts[0] . '_' . strtoupper($region) : $parts[0];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
