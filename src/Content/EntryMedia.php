<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Content\Field\MediaFieldType;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Media\MediaPath;
use AtarCms\Media\MediaRepository;

/**
 * The media an entry uses: the ids of its media fields (validated here) and the media its rich
 * text links to. EntryService writes them to entry_media in the entry's own transaction (store()), which lets the
 * database refuse to delete a used file.
 */
final class EntryMedia
{
    public function __construct(private readonly MediaRepository $media) {}

    /**
     * Every media id of a media field must exist (validation.field.media; restoring a revision
     * drops ids that no longer exist instead), and image/gallery fields take images only
     * (validation.field.media_type). A slides field keeps its maps: its "image" must be an image and its optional
     * "video" a video; a restore drops the slides whose image is gone and clears a video that is gone.
     *
     * @param array<string, mixed> $data normalized field values
     *
     * @return array<string, mixed> the data without dropped ids
     */
    public function validate(Collection $collection, array $data, bool $restore, ValidationErrors $errors): array
    {
        $ids = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof MediaFieldType) {
                $ids = [...$ids, ...$field->type->mediaIds($data[$name] ?? null)];
            }
        }
        $found = $this->media->byIds($ids);

        foreach ($collection->fields as $name => $field) {
            $type = $field->type;
            if (!$type instanceof MediaFieldType || !array_key_exists($name, $data)) {
                continue;
            }
            if ($type instanceof SlidesType) {
                // A slides value is a list of maps, not of ids: a restore drops the slides whose image is gone.
                $slides = is_array($data[$name]) ? $data[$name] : [];
                $kept = [];
                $error = null;
                foreach (array_values($slides) as $index => $slide) {
                    $id = is_array($slide) ? ($slide['image'] ?? null) : null;
                    if (!is_array($slide) || !is_int($id) || !isset($found[$id])) {
                        $error ??= $restore ? null : ['validation.field.media', []];

                        continue;
                    }
                    if (!$found[$id]->isImage()) {
                        $error ??= ['validation.field.media_type', []];
                    }
                    // The video is optional: a restore clears a video that is gone and keeps the slide (its picture).
                    if (array_key_exists('video', $slide)) {
                        $video = $slide['video'];
                        if (!is_int($video) || !isset($found[$video])) {
                            if (!$restore) {
                                $error ??= ['validation.field.media', []];
                            }
                            unset($slide['video']);
                        } elseif (!$found[$video]->isVideo()) {
                            $error ??= ['validation.slides.video', ['slide' => $index + 1]];
                        }
                    }
                    $kept[] = $slide;
                }
                if ($error !== null) {
                    $errors->add($name, ...$error);
                } elseif ($kept === []) {
                    unset($data[$name]);
                    if ($field->required) {
                        $errors->add($name, 'validation.field.required');
                    }
                } else {
                    $data[$name] = $kept;
                }

                continue;
            }
            $chosen = $type->mediaIds($data[$name]);
            $existing = array_values(array_filter($chosen, static fn(int $id): bool => isset($found[$id])));
            if ($existing !== $chosen && !$restore) {
                $errors->add($name, 'validation.field.media');

                continue;
            }
            if ($type->imagesOnly() && array_filter($existing, static fn(int $id): bool => !$found[$id]->isImage()) !== []) {
                $errors->add($name, 'validation.field.media_type');

                continue;
            }
            if ($existing === []) {
                unset($data[$name]);
                if ($field->required) {
                    $errors->add($name, 'validation.field.required');
                }

                continue;
            }
            $data[$name] = is_array($data[$name]) ? $existing : $existing[0];
        }

        return $data;
    }

    /**
     * @return list<string> the names of $collection's fields that can reference media: media fields and rich text
     */
    public static function fields(Collection $collection): array
    {
        $names = [];
        foreach ($collection->fields as $name => $field) {
            if ($field->type instanceof MediaFieldType || $field->type instanceof RichTextType) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<array{field: string, mediaId: int}> the media fields' ids and the media linked from rich text
     *                                                  (unknown links are ignored)
     */
    public function references(Entry $entry): array
    {
        $references = [];
        $linked = [];
        foreach ($entry->collection->fields as $name => $field) {
            $stored = $entry->data[$name] ?? null;
            if ($field->type instanceof MediaFieldType) {
                foreach ($field->type->mediaIds($stored) as $id) {
                    $references[] = ['field' => $name, 'mediaId' => $id];
                }
            } elseif ($field->type instanceof RichTextType && is_string($stored)) {
                foreach (self::paths($stored) as $path) {
                    $linked[$path][] = $name;
                }
            }
        }
        foreach ($this->media->idsByPaths(array_keys($linked)) as $path => $id) {
            foreach ($linked[$path] as $name) {
                $references[] = ['field' => $name, 'mediaId' => $id];
            }
        }

        return $references;
    }

    /**
     * Replaces the entry's rows in entry_media; call inside the entry's transaction. A medium deleted in the meantime
     * fails with the foreign key error 1452 (EntryService::mediaGoneError()).
     */
    public function store(Entry $entry): void
    {
        $this->media->replaceEntryReferences($entry->id, $this->references($entry));
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int> the ids of the media chosen in the entries' media fields, in order
     *                   of appearance (for mediaFor(); rich-text images are already HTML)
     */
    public function ids(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            foreach ($entry->collection->fields as $name => $field) {
                if ($field->type instanceof MediaFieldType) {
                    foreach ($field->type->mediaIds($entry->data[$name] ?? null) as $id) {
                        $ids[$id] = $id;
                    }
                }
            }
        }

        return array_values($ids);
    }

    /**
     * The stored files linked from sanitized HTML: src/href values "/uploads/{path}" or
     * "/media/{variant}/{path}[.webp|.avif|.png][?v=…]", optionally with a host ("https://host/uploads/…", "//host/uploads/…"
     * as pasted by an editor). Any host counts: the random paths cannot match another site's files by accident, and a
     * link through another name of the site (www., an old domain) still protects the file.
     *
     * @return list<string>
     */
    public static function paths(string $html): array
    {
        preg_match_all(
            '~(?:src|href)="(?:(?:https?:)?//[^/"\s]+)?/(?:uploads|media/[a-z][a-z0-9_]{0,31})/(' . MediaPath::PATTERN . ')~',
            $html,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }
}
