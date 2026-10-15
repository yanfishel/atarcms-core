<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Collections;
use AtarCms\Content\EntryUrls;
use AtarCms\Content\PublicContent;
use AtarCms\Http\Responses;
use AtarCms\Http\Xml;
use AtarCms\I18n\Locales;
use AtarCms\I18n\LocalizedUrls;
use AtarCms\I18n\Translator;
use AtarCms\Kernel\Clock;
use AtarCms\Kernel\Config;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * RSS 2.0 feed of a collection's newest public entries in the request locale.
 */
final class FeedController
{
    private const int ITEMS = 20;

    public function __construct(
        private readonly PublicContent $content,
        private readonly Collections $collections,
        private readonly EntryUrls $urls,
        private readonly LocalizedUrls $localizedUrls,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Responses $responses,
        private readonly SiteSettings $settings,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $collection = ContentRequest::collection($request, $this->collections);
        $locale = ContentRequest::locale($request, $collection, $this->locales);

        $entries = $this->content->query($collection, $locale)->latest(self::ITEMS);
        $base = $this->config->appUrl();
        $label = $this->translator->canTranslate($locale, $collection->label) ? $this->translator->translate($locale, $collection->label) : $collection->label;
        $title = $label . ' — ' . $this->settings->siteName($locale);
        $updated = $entries === [] ? $this->clock->now() : ($entries[0]->publishedAt ?? $entries[0]->updatedAt);

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '  <channel>',
            '    <title>' . Xml::escape($title) . '</title>',
            '    <link>' . Xml::escape($base . $this->localizedUrls->path(EntryUrls::indexRoute($collection), [], $locale)) . '</link>',
            '    <description>' . Xml::escape($title) . '</description>',
            '    <language>' . Xml::escape($locale) . '</language>',
            '    <lastBuildDate>' . $updated->format(DATE_RSS) . '</lastBuildDate>',
            sprintf(
                '    <atom:link href="%s" rel="self" type="application/rss+xml"/>',
                Xml::escape($base . $this->localizedUrls->path(EntryUrls::feedRoute($collection), [], $locale)),
            ),
        ];
        $paths = $this->urls->paths($entries);
        foreach ($entries as $entry) {
            if (!isset($paths[$entry->id])) {
                continue;
            }
            $url = $base . $paths[$entry->id];
            $lines[] = '    <item>';
            $lines[] = '      <title>' . Xml::escape($entry->title) . '</title>';
            $lines[] = '      <link>' . Xml::escape($url) . '</link>';
            $lines[] = '      <guid isPermaLink="true">' . Xml::escape($url) . '</guid>';
            if ($entry->publishedAt !== null) {
                $lines[] = '      <pubDate>' . $entry->publishedAt->format(DATE_RSS) . '</pubDate>';
            }
            if ($entry->seo->metaDescription !== null) {
                $lines[] = '      <description>' . Xml::escape($entry->seo->metaDescription) . '</description>';
            }
            $lines[] = '    </item>';
        }
        $lines[] = '  </channel>';
        $lines[] = '</rss>';

        return $this->responses->xml(implode("\n", $lines) . "\n", 'application/rss+xml');
    }
}
