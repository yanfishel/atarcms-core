<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Content\TreeOrder;
use AtarCms\Http\Exception\ValidationFailed;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Parses a tree editor submission: the tree as JSON ("tree"), the version the page was loaded at, the locale and,
 * from an arrow button, a move ("move" = "{id}:{up|down|indent|outdent}", a negative id for a pinned collection of the
 * Sections screen) applied to the submitted tree.
 */
final class TreeForm
{
    public static function parse(ServerRequestInterface $request): TreeFormData
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $string = static function (string $key) use ($body): string {
            $value = $body[$key] ?? '';

            return is_string($value) ? $value : '';
        };

        try {
            $tree = TreeOrder::parse($string('tree'));
            $move = $string('move');
            if ($move !== '') {
                if (preg_match('/^(-?[1-9]\d{0,9}):([a-z]+)$/', $move, $match) !== 1) {
                    throw new ValidationFailed(['tree' => 'validation.tree.invalid']);
                }
                $tree = TreeOrder::move($tree, (int) $match[1], $match[2]);
            }
        } catch (ValidationFailed $e) {
            return new TreeFormData(null, $string('version'), $string('locale'), $e->errors);
        }

        return new TreeFormData($tree, $string('version'), $string('locale'), []);
    }
}
