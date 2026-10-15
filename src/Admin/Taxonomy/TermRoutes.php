<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Content\Taxonomy;

/**
 * The admin routes of a taxonomy's terms: /admin/taxonomies/{taxonomy}/… ("admin.terms…"), or for the sections (the
 * hierarchical taxonomy routed at "/{slug}", Taxonomy::isRootTree()) their own screen /admin/sections/… ("admin.sections…",
 * without the taxonomy parameter). Templates call $this->url(...TermRoutes::of($taxonomy, '.edit', ['id' => $id])).
 */
final class TermRoutes
{
    /**
     * @param string $action '' for the list, or a route name suffix such as ".edit"
     * @param array<string, scalar> $params
     *
     * @return array{0: string, 1: array<string, scalar>} the route name and its parameters
     */
    public static function of(Taxonomy $taxonomy, string $action = '', array $params = []): array
    {
        return $taxonomy->isRootTree()
            ? ['admin.sections' . $action, $params]
            : ['admin.terms' . $action, ['taxonomy' => $taxonomy->name, ...$params]];
    }
}
