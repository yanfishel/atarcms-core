<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Field\Field;
use AtarCms\Content\Field\GalleryType;
use AtarCms\Content\Field\RelationType;
use AtarCms\Content\Field\RichTextType;
use AtarCms\Content\Field\SlidesType;
use AtarCms\Content\Field\TagsType;
use AtarCms\Content\Field\TextareaType;
use AtarCms\Content\Field\TextType;
use AtarCms\Content\Menu\MainMenu;
use AtarCms\Content\Taxonomy;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\Locales;
use AtarCms\I18n\Translator;
use AtarCms\Messages\MessageService;
use AtarCms\View\ThemeConfig;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin described for an AI browser agent (/admin/ai-guide): one markdown document, English only (a document for
 * machines, not interface text). The static part (resources/admin/ai-guide.md) explains how the admin works; a "## "
 * section whose first line is "<!-- requires: {permission} -->" is kept only for users with that permission. The
 * generated part lists the site's languages, the screens the user may open (the sidebar's items, filtered as the
 * sidebar filters them), their content types with the fields, their taxonomies and their menu locations.
 */
final readonly class AiGuide
{
    /** The language of the document: labels that are translation keys read in English. */
    private const string LOCALE = 'en';

    private const string SOURCE = __DIR__ . '/../../resources/admin/ai-guide.md';

    public function __construct(
        private AdminMenu $menu,
        private Authorizer $authorizer,
        private Collections $collections,
        private MainMenu $mainMenu,
        private ThemeConfig $theme,
        private Locales $locales,
        private Translator $translator,
        private Router $router,
        private Roles $roles,
        private LinkedLanguage $linked,
    ) {}

    public function render(ServerRequestInterface $request): string
    {
        $user = CurrentUser::require($request);
        $items = $this->menu->visibleTo($this->authorizer, $user);

        return implode("\n", array_filter([
            $this->staticPart($user),
            $this->site($user, $items),
            $this->contentTypes($user, $items),
            $this->taxonomies($items),
            $this->menus($items),
        ], static fn(string $part): bool => $part !== ''));
    }

    private function staticPart(User $user): string
    {
        $text = str_replace("\r\n", "\n", (string) file_get_contents(self::SOURCE));
        $kept = '';
        foreach (preg_split('/^(?=## )/m', $text) ?: [] as $section) {
            if (preg_match('/^## [^\n]*\n<!-- requires: ([a-z0-9_.*]+) -->\n/', $section, $match) === 1) {
                if (!$this->authorizer->can($user, $match[1])) {
                    continue;
                }
                $section = str_replace('<!-- requires: ' . $match[1] . " -->\n", '', $section);
            }
            $kept .= $section;
        }
        // A gating comment written any other way would leave its section to everyone: refuse it loudly.
        if (str_contains($kept, '<!--')) {
            throw new LogicException('resources/admin/ai-guide.md holds a comment that is not "<!-- requires: {permission} -->" on the line right after a "## " heading');
        }

        return rtrim($kept) . "\n";
    }

    /**
     * @param list<AdminMenuItem> $items
     */
    private function site(User $user, array $items): string
    {
        $default = $this->locales->default();
        $languages = array_map(static fn(string $code): string => $code === $default ? $code . ' (default)' : $code, $this->locales->all());
        $lines = ['## This site', ''];
        $lines[] = '- Site languages: ' . implode(', ', $languages);
        if (count($languages) > 1) {
            $lines[] = '- Admin language mode: ' . ($this->linked->enabled()
                ? 'linked (the top bar\'s language menu switches the interface and the content together)'
                : 'separate (the top bar switches the content language; the Profile sets the interface language)');
        }
        $lines[] = sprintf('- Signed in as: %s (role: %s)', $user->email, $this->label($this->roles->label($user->role)));
        $lines[] = '';
        $lines[] = '### Screens you can open';
        $lines[] = '';
        foreach ($items as $item) {
            $lines[] = sprintf('- %s: %s', $this->label($item->label), $this->router->url($item->route, $item->params));
        }
        if ($this->authorizer->can($user, MessageService::PERMISSION)) {
            $lines[] = '- Messages (the inbox of the site\'s forms): ' . $this->router->url('admin.messages');
        }
        $lines[] = '- Profile: ' . $this->router->url('admin.profile');

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<AdminMenuItem> $items
     */
    private function contentTypes(User $user, array $items): string
    {
        $lines = [];
        foreach ($this->routed($items, 'admin.entries', 'collection') as $name) {
            $collection = $this->collections->find($name);
            if ($collection !== null) {
                array_push($lines, ...$this->contentType($user, $collection));
            }
        }

        return $lines === [] ? '' : implode("\n", ['## Content types of this site', '', ...$lines]);
    }

    /**
     * @return list<string>
     */
    private function contentType(User $user, Collection $collection): array
    {
        $list = $this->router->url('admin.entries', ['collection' => $collection->name]);
        $entries = $collection->translatable ? 'one entry per language' : 'one entry, in the default language only';
        $kind = match (true) {
            $collection->plain => 'plain single (' . $entries . ', only its fields, always published)',
            $collection->single => 'single (' . $entries . ')',
            $collection->hierarchical => 'hierarchical list (entries have a parent and an order)',
            default => 'list',
        };
        if ($collection->translatable) {
            $kind .= ', translatable';
        } elseif (!$collection->single) {
            $kind .= ', default language only';
        }
        $lines = [sprintf('### %s (`%s`)', $this->label($collection->label), $collection->name), ''];
        $lines[] = '- Kind: ' . $kind;
        if ($collection->single) {
            $lines[] = '- Open: ' . $list;
        } else {
            $lines[] = '- List: ' . $list;
            if ($this->authorizer->can($user, $collection->permission('create'))) {
                $lines[] = '- New entry: ' . $this->router->url('admin.entries.new', ['collection' => $collection->name]);
            }
        }
        if ($collection->route !== null) {
            $lines[] = '- Public address: ' . $collection->route;
        }
        if ($collection->taxonomies !== []) {
            $lines[] = '- Terms picked in the form: ' . implode(', ', array_map(static fn(string $name): string => '`' . $name . '`', $collection->taxonomies));
        }
        if ($collection->presets !== []) {
            $lines[] = '- Preset pages (never deleted, fixed address): ' . implode(', ', array_map(static fn(string $slug): string => '`' . $slug . '`', array_keys($collection->presets)));
        }
        if ($collection->fields !== []) {
            $lines[] = '- Fields (posted as `fields[{name}]`):';
            foreach ($collection->fields as $field) {
                $lines[] = '  ' . $this->field($field);
            }
        }
        $lines[] = '';

        return $lines;
    }

    private function field(Field $field): string
    {
        $parts = [$field->type->name(), $field->required ? 'required' : 'optional'];
        if ($field->tab !== 'content') {
            $parts[] = 'tab "' . $field->tab . '"';
        }
        $label = $this->label($field->label);
        if ($label !== $field->name) {
            $parts[] = 'label "' . $label . '"';
        }
        // Field::$max is a length, a count or a value depending on the type; text, textarea and slides enforce a
        // default when none is set (a rich text without max only caps the raw HTML, which the agent need not know).
        $unit = match (true) {
            $field->type instanceof TextType, $field->type instanceof TextareaType, $field->type instanceof RichTextType => ' characters',
            $field->type instanceof RelationType, $field->type instanceof GalleryType, $field->type instanceof TagsType, $field->type instanceof SlidesType => ' items',
            default => '',
        };
        $max = $field->max ?? match (true) {
            $field->type instanceof TextType => TextType::DEFAULT_MAX,
            $field->type instanceof TextareaType => TextareaType::DEFAULT_MAX,
            $field->type instanceof SlidesType => SlidesType::DEFAULT_MAX,
            default => null,
        };
        if ($field->min !== null) {
            $parts[] = 'min ' . $field->min . $unit;
        }
        if ($max !== null) {
            $parts[] = 'max ' . $max . $unit;
        }
        if ($field->options !== []) {
            $parts[] = 'options: ' . implode(', ', array_map(static fn(int|string $value): string => '`' . $value . '`', array_keys($field->options)));
        }
        if ($field->target !== null) {
            $parts[] = 'entries of `' . $field->target . '`';
        }
        if ($field->taxonomy !== null) {
            $parts[] = 'terms of `' . $field->taxonomy . '`';
        }
        if ($field->help !== null) {
            $parts[] = 'help "' . $this->label($field->help) . '"';
        }

        return sprintf('- `%s`: %s', $field->name, implode(', ', $parts));
    }

    /**
     * @param list<AdminMenuItem> $items
     */
    private function taxonomies(array $items): string
    {
        $lines = [];
        foreach ($this->collections->taxonomies->all() as $taxonomy) {
            $visible = $taxonomy->isRootTree()
                ? in_array('admin.sections', array_map(static fn(AdminMenuItem $item): string => $item->route, $items), true)
                : in_array($taxonomy->name, $this->routed($items, 'admin.terms', 'taxonomy'), true);
            if ($visible) {
                $lines[] = $this->taxonomy($taxonomy);
            }
        }

        return $lines === [] ? '' : implode("\n", ['## Taxonomies of this site', '', ...$lines]) . "\n";
    }

    private function taxonomy(Taxonomy $taxonomy): string
    {
        $url = $taxonomy->isRootTree()
            ? $this->router->url('admin.sections')
            : $this->router->url('admin.terms', ['taxonomy' => $taxonomy->name]);

        return sprintf('- %s (`%s`%s): %s', $this->label($taxonomy->label), $taxonomy->name, $taxonomy->hierarchical ? ', hierarchical' : '', $url);
    }

    /**
     * @param list<AdminMenuItem> $items
     */
    private function menus(array $items): string
    {
        if (!in_array('admin.menus', array_map(static fn(AdminMenuItem $item): string => $item->route, $items), true)) {
            return '';
        }
        $lines = ['## Menus of this site', ''];
        foreach ($this->mainMenu->menuLocations($this->theme) as $location => $label) {
            $lines[] = sprintf('- %s (`%s`): %s', $this->label($label), $location, $this->router->url('admin.menus.show', ['location' => $location]));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The values of $param of the visible items of $route, in menu order.
     *
     * @param list<AdminMenuItem> $items
     *
     * @return list<string>
     */
    private function routed(array $items, string $route, string $param): array
    {
        $values = [];
        foreach ($items as $item) {
            if ($item->route === $route && isset($item->params[$param])) {
                $values[] = (string) $item->params[$param];
            }
        }

        return $values;
    }

    private function label(string $label): string
    {
        return $this->translator->label(self::LOCALE, $label);
    }
}
