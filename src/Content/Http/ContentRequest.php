<?php

declare(strict_types=1);

namespace AtarCms\Content\Http;

use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Http\Exception\NotFound;
use AtarCms\I18n\Locales;
use AtarCms\I18n\RequestLocale;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared reads of a content route's request: the Collection::class attribute and the request locale,
 * used by ContentController and FeedController.
 */
final class ContentRequest
{
    /**
     * The Collection a content route carries: the route attribute Collection::class holds its name (route
     * attributes are plain data).
     */
    public static function collection(ServerRequestInterface $request, Collections $collections): Collection
    {
        $name = $request->getAttribute(Collection::class);
        $collection = is_string($name) ? $collections->find($name) : null;

        return $collection ?? throw new LogicException('Content routes carry their collection name; register them with ContentModule::registerRoutes()');
    }

    /**
     * The request locale; 404 when the collection is not translated into it.
     */
    public static function locale(ServerRequestInterface $request, Collection $collection, Locales $locales): string
    {
        $locale = RequestLocale::of($request)->code;
        if (!$collection->translatable && $locale !== $locales->default()) {
            throw new NotFound(sprintf('Collection "%s" is not translated into "%s"', $collection->name, $locale));
        }

        return $locale;
    }
}
