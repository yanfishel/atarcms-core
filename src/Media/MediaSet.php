<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * Media loaded for a set of entries (mediaFor()), looked up by the ids their fields return.
 */
final readonly class MediaSet
{
    /**
     * @param array<int, Media> $media id => media
     */
    public function __construct(private array $media = []) {}

    /**
     * @param mixed $id what an image or file field returns (?int)
     */
    public function get(mixed $id): ?Media
    {
        return is_int($id) ? ($this->media[$id] ?? null) : null;
    }

    /**
     * @param mixed $ids what a gallery field returns (list<int>)
     *
     * @return list<Media> in the given order; unknown ids are skipped
     */
    public function list(mixed $ids): array
    {
        $list = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $media = $this->get($id);
            if ($media !== null) {
                $list[] = $media;
            }
        }

        return $list;
    }
}
