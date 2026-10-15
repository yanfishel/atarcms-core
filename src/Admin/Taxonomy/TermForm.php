<?php

declare(strict_types=1);

namespace AtarCms\Admin\Taxonomy;

use AtarCms\Admin\PositionParentFields;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\Term;
use AtarCms\Content\TermInput;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin term form: parses a submission into a TermInput and gives the values to (re-)render it. The texts for
 * search engines are posted by the sections' form only; TermService drops them for other taxonomies.
 *
 * @phpstan-import-type TermFormValues from TermFormData
 */
final class TermForm
{
    /**
     * The error keys of the inputs the form shows: name and address, the parent of a hierarchical taxonomy and the
     * texts for search engines of the sections. Any other error (a stale form, a language, the order, a crafted
     * parent) has no field and is an error toast (FormErrors).
     *
     * @return list<string>
     */
    public static function fields(Taxonomy $taxonomy): array
    {
        return [
            'name',
            'slug',
            ...($taxonomy->hierarchical ? ['parent_id'] : []),
            ...($taxonomy->isRootTree() ? ['meta_title', 'meta_description'] : []),
        ];
    }

    public static function parse(ServerRequestInterface $request): TermFormData
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $string = static function (string $key) use ($body): string {
            $value = $body[$key] ?? '';

            return is_string($value) ? $value : '';
        };
        $values = [
            'name' => $string('name'),
            'slug' => $string('slug'),
            'parent_id' => $string('parent_id'),
            'meta_title' => $string('meta_title'),
            'meta_description' => $string('meta_description'),
        ];

        $tree = PositionParentFields::parseParent($values['parent_id'], 'validation.term.parent');

        return new TermFormData(
            new TermInput($values['name'], $values['slug'], $tree['parentId'], metaTitle: $values['meta_title'], metaDescription: $values['meta_description']),
            $values,
            $string('version'),
            $string('locale'),
            $tree['errors'],
        );
    }

    /**
     * @return TermFormValues
     */
    public static function values(Term $term): array
    {
        return [
            'name' => $term->name,
            'slug' => $term->slug,
            'parent_id' => $term->parentId === null ? '' : (string) $term->parentId,
            'meta_title' => $term->seo->metaTitle ?? '',
            'meta_description' => $term->seo->metaDescription ?? '',
        ];
    }

    /**
     * @return TermFormValues
     */
    public static function defaults(): array
    {
        return ['name' => '', 'slug' => '', 'parent_id' => '', 'meta_title' => '', 'meta_description' => ''];
    }
}
