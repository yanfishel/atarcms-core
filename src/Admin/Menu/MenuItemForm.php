<?php

declare(strict_types=1);

namespace AtarCms\Admin\Menu;

use AtarCms\Content\Menu\MenuItem;
use AtarCms\Content\Menu\MenuItemInput;
use AtarCms\Content\Menu\MenuTarget;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin menu item form: parses a submission into a MenuItemInput and gives the values to (re-)render it.
 *
 * @phpstan-import-type MenuItemFormValues from MenuItemFormData
 */
final class MenuItemForm
{
    /** The inputs that show their own errors; any other error (stale, locale) is a toast. */
    public const array FIELDS = ['label', 'target_type', 'entry_id', 'term_id', 'url', 'parent_id'];

    public static function parse(ServerRequestInterface $request): MenuItemFormData
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $string = static function (string $key) use ($body): string {
            $value = $body[$key] ?? '';

            return is_string($value) ? $value : '';
        };
        $values = [
            'label' => $string('label'),
            'target_type' => $string('target_type'),
            'entry_id' => $string('entry_id'),
            'term_id' => $string('term_id'),
            'url' => $string('url'),
            'new_tab' => $string('new_tab') === '1',
            'parent_id' => $string('parent_id'),
        ];
        $target = MenuTarget::tryFrom($values['target_type']);
        $errors = $target === null ? ['target_type' => 'validation.menu.target'] : [];

        return new MenuItemFormData(
            new MenuItemInput(
                label: $values['label'],
                target: $target ?? MenuTarget::Url,
                entryId: self::id($values['entry_id']),
                termId: self::id($values['term_id']),
                url: $values['url'],
                newTab: $values['new_tab'],
                parentId: self::id($values['parent_id']),
            ),
            $values,
            $string('version'),
            $string('locale'),
            $errors,
        );
    }

    /**
     * @return MenuItemFormValues
     */
    public static function values(MenuItem $item): array
    {
        return [
            'label' => $item->label,
            'target_type' => $item->target->value,
            'entry_id' => $item->entryId === null ? '' : (string) $item->entryId,
            'term_id' => $item->termId === null ? '' : (string) $item->termId,
            'url' => $item->url ?? '',
            'new_tab' => $item->newTab,
            'parent_id' => $item->parentId === null ? '' : (string) $item->parentId,
        ];
    }

    /**
     * @return MenuItemFormValues
     */
    public static function defaults(): array
    {
        return ['label' => '', 'target_type' => MenuTarget::Entry->value, 'entry_id' => '', 'term_id' => '', 'url' => '', 'new_tab' => false, 'parent_id' => ''];
    }

    /**
     * A positive id, or null (MenuService then reports the missing target).
     */
    private static function id(string $raw): ?int
    {
        $raw = trim($raw);

        return preg_match('/^[1-9]\d{0,9}$/', $raw) === 1 ? (int) $raw : null;
    }
}
