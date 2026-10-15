<?php

declare(strict_types=1);

namespace AtarCms\Media;

/**
 * What one page of the media library shows (MediaRepository::page()): the folders searched, the search text, the
 * type group, the order, and whether only unused files are listed.
 */
final readonly class MediaQuery
{
    public const string TYPE_ALL = 'all';

    public const string TYPE_IMAGE = 'image';

    public const string TYPE_VIDEO = 'video';

    public const string TYPE_DOCUMENT = 'document';

    public const array TYPES = [self::TYPE_ALL, self::TYPE_IMAGE, self::TYPE_VIDEO, self::TYPE_DOCUMENT];

    public const string SORT_NEWEST = 'newest';

    public const array SORTS = [self::SORT_NEWEST, 'oldest', 'name', 'size'];

    /**
     * @param list<int>|null $folderIds the folders whose files are listed; null for every file, whatever its folder
     * @param bool $root also list the files outside any folder (ignored when $folderIds is null)
     * @param string $type one of TYPES: "image" for image/*, "video" for video/*, "document" for every other type
     * @param string $sort one of SORTS: newest or oldest upload first, name A–Z, largest first
     * @param bool $unused only files no entry references (entry_media) and not in $alsoUsed
     * @param list<int> $alsoUsed files used outside the entries (the site settings' logo, icon and sharing image)
     */
    public function __construct(
        public ?array $folderIds = null,
        public bool $root = false,
        public string $search = '',
        public string $type = self::TYPE_ALL,
        public string $sort = self::SORT_NEWEST,
        public bool $unused = false,
        public array $alsoUsed = [],
        public int $page = 1,
        public int $perPage = MediaRepository::PER_PAGE,
    ) {}

    /**
     * The files of one folder (null: those outside any folder).
     */
    public static function inFolder(?int $folder): self
    {
        return $folder === null ? new self(folderIds: [], root: true) : new self(folderIds: [$folder]);
    }
}
