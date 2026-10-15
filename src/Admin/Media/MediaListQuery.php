<?php

declare(strict_types=1);

namespace AtarCms\Admin\Media;

use AtarCms\Media\MediaFolderTree;
use AtarCms\Media\MediaQuery;

/**
 * The GET parameters of the media library and the picker: folder, view, type, sort, search ("everywhere" ignores the
 * folder), only unused files (library), page, and the picker's kind ("image" or "video" fixes the type), language of the alt
 * texts. Invalid values fall back to the defaults; links are built from params(),
 * so the state survives navigation. The view is not a link parameter: it is remembered in a cookie (MediaPreferences).
 */
final readonly class MediaListQuery
{
    public const array VIEWS = ['grid', 'list'];

    /** The picker kinds that fix the type group (MediaQuery::TYPE_IMAGE, TYPE_VIDEO); "all" leaves it to the filter. */
    public const array FIXED_KINDS = [MediaQuery::TYPE_IMAGE, MediaQuery::TYPE_VIDEO];

    public function __construct(
        public ?int $folder = null,
        public string $view = 'grid',
        public string $type = MediaQuery::TYPE_ALL,
        public string $sort = MediaQuery::SORT_NEWEST,
        public string $search = '',
        public bool $everywhere = false,
        public bool $unused = false,
        public int $page = 1,
        public string $kind = 'all',
        public string $locale = '',
    ) {}

    /**
     * @param array<mixed> $query the request's query parameters
     * @param string $view the view to show (MediaPreferences::view())
     * @param ?int $folder the folder to show, already resolved against the tree
     */
    public static function fromQuery(array $query, string $view, ?int $folder): self
    {
        $string = static fn(string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';
        $kind = in_array($string('kind'), self::FIXED_KINDS, true) ? $string('kind') : 'all';
        $type = in_array($string('type'), MediaQuery::TYPES, true) ? $string('type') : MediaQuery::TYPE_ALL;

        return new self(
            folder: $folder,
            view: in_array($view, self::VIEWS, true) ? $view : 'grid',
            type: $kind === 'all' ? $type : $kind,
            sort: in_array($string('sort'), MediaQuery::SORTS, true) ? $string('sort') : MediaQuery::SORT_NEWEST,
            search: mb_substr(trim($string('q')), 0, 100),
            everywhere: $string('everywhere') === '1',
            unused: $string('unused') === '1',
            page: preg_match('/^[1-9]\d{0,5}$/', $string('page')) === 1 ? (int) $string('page') : 1,
            kind: $kind,
            locale: $string('locale'),
        );
    }

    /**
     * The scope applies only to a search: without one the folder shown is listed, whatever the scope.
     */
    public function searchesEverywhere(): bool
    {
        return $this->everywhere && $this->search !== '';
    }

    /**
     * Another folder, on its first page.
     */
    public function withFolder(?int $folder): self
    {
        return new self($folder, $this->view, $this->type, $this->sort, $this->search, $this->everywhere, $this->unused, 1, $this->kind, $this->locale);
    }

    public function withPage(int $page): self
    {
        return $this->copy(page: $page);
    }

    /**
     * The first page without search, newest first (what the picker shows after an upload).
     */
    public function withoutSearch(): self
    {
        return $this->copy(page: 1, search: '', everywhere: false, sort: MediaQuery::SORT_NEWEST);
    }

    public function withoutLocale(): self
    {
        return $this->copy(locale: '');
    }

    private function copy(
        ?int $page = null,
        ?string $search = null,
        ?bool $everywhere = null,
        ?string $sort = null,
        ?string $locale = null,
    ): self {
        return new self(
            folder: $this->folder,
            view: $this->view,
            type: $this->type,
            sort: $sort ?? $this->sort,
            search: $search ?? $this->search,
            everywhere: $everywhere ?? $this->everywhere,
            unused: $this->unused,
            page: $page ?? $this->page,
            kind: $this->kind,
            locale: $locale ?? $this->locale,
        );
    }

    /**
     * @return array<string, string> the non-default parameters, for links and hidden inputs (the folder always, so
     *                               the picker can tell "the root" from "no folder given")
     */
    public function params(bool $picker = false): array
    {
        $params = [
            'folder' => $this->folder === null ? ($picker ? '0' : '') : (string) $this->folder,
            'type' => $this->type !== MediaQuery::TYPE_ALL && $this->kind === 'all' ? $this->type : '',
            'sort' => $this->sort !== MediaQuery::SORT_NEWEST ? $this->sort : '',
            'q' => $this->search,
            'everywhere' => $this->everywhere ? '1' : '',
            'unused' => $this->unused ? '1' : '',
        ];
        if ($picker) {
            $params += [
                'kind' => $this->kind,
                'locale' => $this->locale,
            ];
        }
        $params['page'] = $this->page > 1 ? (string) $this->page : '';

        return array_filter($params, static fn(string $value): bool => $value !== '');
    }

    /**
     * What the repository lists: the folder's own files, or with a search the folder and its subfolders (every file
     * from the root), or every file when "everywhere" is on.
     *
     * @param list<int> $alsoUsed files used outside the entries (the site settings' logo, icon and sharing image), for the unused filter
     */
    public function toMediaQuery(MediaFolderTree $tree, array $alsoUsed): MediaQuery
    {
        if ($this->searchesEverywhere()) {
            [$folderIds, $root] = [null, false];
        } elseif ($this->search !== '') {
            [$folderIds, $root] = $this->folder === null ? [null, false] : [$tree->withDescendants($this->folder), false];
        } else {
            [$folderIds, $root] = $this->folder === null ? [[], true] : [[$this->folder], false];
        }

        return new MediaQuery(
            folderIds: $folderIds,
            root: $root,
            search: $this->search,
            type: $this->type,
            sort: $this->sort,
            unused: $this->unused,
            alsoUsed: $alsoUsed,
            page: $this->page,
        );
    }
}
