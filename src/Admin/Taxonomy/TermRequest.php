<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\ContentLocale;
use AtarCms\Content\Taxonomies;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermRepository;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\Locales;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the taxonomy and term named in an admin taxonomy URL; anything that does not match is a 404. The sections'
 * screens (/admin/sections) name no taxonomy: theirs is the hierarchical taxonomy routed at "/{slug}", which the
 * /admin/taxonomies screens no longer show.
 */
final class TermRequest
{
    public function __construct(
        private readonly Taxonomies $taxonomies,
        private readonly TermRepository $terms,
        private readonly Locales $locales,
        private readonly ContentLocale $contentLocale,
    ) {}

    public function taxonomy(ServerRequestInterface $request): Taxonomy
    {
        $name = $request->getAttribute('taxonomy');
        if (!is_string($name)) {
            $root = $this->taxonomies->root();

            return $root !== null && $root->isRootTree() ? $root : throw new NotFound('This site has no sections');
        }
        $taxonomy = $this->taxonomies->find($name);
        if ($taxonomy === null || $taxonomy->isRootTree()) {
            throw new NotFound('No such taxonomy');
        }

        return $taxonomy;
    }

    /**
     * The term of the URL's id; a term of another taxonomy is a 404.
     */
    public function term(ServerRequestInterface $request, Taxonomy $taxonomy): Term
    {
        $id = $request->getAttribute('id');
        $term = is_string($id) && strlen($id) <= 10 ? $this->terms->find((int) $id) : null;
        if ($term === null || $term->taxonomy !== $taxonomy->name) {
            throw new NotFound(sprintf('No %s term #%s', $taxonomy->name, is_string($id) ? $id : '?'));
        }

        return $term;
    }

    /**
     * The content language of a screen that takes it from ?locale= (the list, "Add new", the order screen): the
     * requested locale, remembered for the user, or the user's preference (ContentLocale).
     */
    public function contentLocale(ServerRequestInterface $request): string
    {
        return $this->contentLocale->resolve($request, $request->getQueryParams()['locale'] ?? null);
    }

    /**
     * The edit page of $term remembers its language when ?locale= names it (the form's language switch, the
     * redirect after creating a translation); see ContentLocale::rememberRequested().
     */
    public function rememberLocale(ServerRequestInterface $request, Term $term): void
    {
        $this->contentLocale->rememberRequested($request, $term->locale);
    }

    /**
     * A supported locale (a submitted form's locale); the default locale otherwise.
     */
    public function locale(mixed $value): string
    {
        return is_string($value) && $this->locales->isSupported($value) ? $value : $this->locales->default();
    }
}
