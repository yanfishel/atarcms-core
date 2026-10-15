<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Kernel\ConfigException;
use InvalidArgumentException;

/**
 * An icon of the admin sprite (resources/admin/assets/icons.svg, a vendored subset of Lucide), rendered as
 * <svg class="admin-icon"><use href="…/icons.svg?v=…#name"></use></svg> (plus "admin-icon-mirror" for MIRRORED names). Without a label the icon is decorative
 * (aria-hidden); with one it is an image named by aria-label (never a <title>, which browsers show as a tooltip), for buttons and links that show only the icon.
 */
final class AdminIcon
{
    public const string NAME_PATTERN = '/^[a-z0-9-]+$/D';

    /**
     * Icons whose picture has a direction (back and forward, in and out, lines of text): they carry the
     * "admin-icon-mirror" class, which admin.css mirrors in a right-to-left page. Clocks, letters, vertical arrows and
     * pictures of things are never mirrored.
     *
     * @var list<string>
     */
    public const array MIRRORED = [
        'arrow-left', 'arrow-right', 'chevron-left', 'chevron-right', 'external-link', 'log-in', 'log-out',
        'folder-input', 'folder-tree', 'align-left', 'list', 'list-tree', 'arrow-down-wide-narrow',
    ];

    /**
     * The symbol ids of a sprite file.
     *
     * @return non-empty-list<string>
     *
     * @throws ConfigException when the file is missing or holds no symbol (one clear error instead of every icon of
     *                         the admin menu reported as missing)
     */
    public static function symbols(string $spriteFile): array
    {
        $sprite = is_file($spriteFile) ? (string) file_get_contents($spriteFile) : '';
        preg_match_all('/<symbol id="([^"]+)"/', $sprite, $matches);
        $symbols = array_values(array_unique($matches[1]));
        if ($symbols === []) {
            throw new ConfigException(sprintf('The icon sprite %s is missing or has no symbols', $spriteFile));
        }

        return $symbols;
    }

    /**
     * @param string $sprite the sprite's URL (AssetUrl::url('icons.svg'))
     * @param string $name a symbol id of the sprite: lowercase letters, digits and "-"
     */
    public static function svg(string $sprite, string $name, ?string $label = null): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid icon name "%s"; use lowercase letters, digits and "-"', $name));
        }
        $use = '<use href="' . self::escape($sprite . '#' . $name) . '"></use>';
        $class = in_array($name, self::MIRRORED, true) ? 'admin-icon admin-icon-mirror' : 'admin-icon';
        if ($label === null || $label === '') {
            return '<svg class="' . $class . '" aria-hidden="true">' . $use . '</svg>';
        }

        // Named by aria-label, not an SVG <title>: browsers show a <title> as their own tooltip box.
        return '<svg class="' . $class . '" role="img" aria-label="' . self::escape($label) . '">' . $use . '</svg>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
