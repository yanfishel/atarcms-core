<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin's modal dialog (dialog.js): a link marked data-dialog-open loads its page through htmx, which sends
 * "HX-Request: true", and the controller answers with the dialog's fragment instead of the page. Forms in the fragment
 * post through htmx too: a refused one comes back as the fragment with its errors (status 200, since htmx swaps no
 * error response), a done one answers "HX-Redirect", and htmx loads that page (the screen behind the dialog) with
 * the flash. Without JavaScript nothing sends the header and the same URLs serve the full pages.
 */
final class Dialog
{
    public static function requested(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true';
    }

    /**
     * Where a done dialog sends the browser back: the screen it was opened on (htmx's HX-Current-URL) when that is
     * $list's own page, its query (a search) kept with $list's parameters winning and without "translate"; else $list.
     */
    public static function back(ServerRequestInterface $request, string $list): string
    {
        $current = parse_url($request->getHeaderLine('HX-Current-URL'));
        $target = parse_url($list);
        if (!is_array($current) || !is_array($target) || !isset($target['path']) || ($current['path'] ?? null) !== $target['path']) {
            return $list;
        }
        parse_str($current['query'] ?? '', $kept);
        parse_str($target['query'] ?? '', $wanted);
        unset($kept['translate']);
        $query = http_build_query(array_filter([...$kept, ...$wanted], is_string(...)), '', '&', PHP_QUERY_RFC3986);

        return $target['path'] . ($query === '' ? '' : '?' . $query);
    }

    public static function redirect(Responses $responses, string $location): ResponseInterface
    {
        return $responses->html('')->withHeader('HX-Redirect', $location);
    }
}
