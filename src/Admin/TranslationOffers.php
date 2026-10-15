<?php

declare(strict_types=1);

namespace AtarCms\Admin;

use AtarCms\Admin\Taxonomy\TermRoutes;
use AtarCms\Auth\User;
use AtarCms\Content\Collection;
use AtarCms\Content\Collections;
use AtarCms\Content\Entry;
use AtarCms\Content\EntryAccess;
use AtarCms\Content\EntryRepository;
use AtarCms\Content\Taxonomy;
use AtarCms\Content\TermRepository;
use AtarCms\Http\Routing\Router;
use AtarCms\I18n\LanguageNames;
use AtarCms\I18n\Locales;

/**
 * The page toast a screen shows after the content language switch found no translation of the entry or term the user
 * was editing (ContentLocaleSwitch: ?translate=): "There is no {language} version of “{title}” yet", with a
 * "Copy for translation" form to the existing translate action when the user may use it (the same checks as that
 * action), with its hidden machine translation button (Plan 38). Anything that is not a real missing translation shows nothing.
 */
final readonly class TranslationOffers
{
    public function __construct(
        private Router $router,
        private EntryRepository $entries,
        private EntryAccess $access,
        private TermRepository $terms,
        private Locales $locales,
        private Collections $collections,
    ) {}

    /**
     * The offer for the entry of $id (the list's ?translate=) in $locale.
     *
     * @return list<FlashMessage>
     */
    public function entryById(User $user, Collection $collection, mixed $id, string $locale): array
    {
        $entry = is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? $this->entries->find((int) $id) : null;

        return $entry === null || $entry->collection->name !== $collection->name ? [] : $this->entry($user, $entry, $locale);
    }

    /**
     * @return list<FlashMessage>
     */
    public function entry(User $user, Entry $entry, mixed $locale): array
    {
        if (!is_string($locale) || !$this->locales->isSupported($locale) || !$entry->collection->translatable || $entry->locale === $locale || !$this->access->canView($user, $entry->collection)) {
            return [];
        }
        $locales = array_map(static fn(Entry $translation): string => $translation->locale, $this->entries->translations($entry->translationGroup));
        if (in_array($locale, $locales, true)) {
            return [];
        }
        $form = $this->access->canTranslate($user, $entry)
            ? new ToastForm($this->router->url('admin.entries.translate', ['collection' => $entry->collection->name, 'id' => $entry->id]), ['locale' => $locale], 'admin.content_locale.create', machineFrom: $entry->locale, shortLabel: 'admin.content_locale.create_short')
            : null;

        return [new FlashMessage('info', 'admin.content_locale.missing', ['language' => LanguageNames::endonym($locale), 'title' => $entry->title], $form)];
    }

    /**
     * The offer for the term of $id (the list's ?translate=) in $locale; the term screens require the permission the
     * translate action requires.
     *
     * @return list<FlashMessage>
     */
    public function termById(Taxonomy $taxonomy, mixed $id, string $locale): array
    {
        $term = is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? $this->terms->find((int) $id) : null;
        // Tags are kept separately per language (Plan 35): no offer to translate one.
        if ($term === null || !$this->locales->isSupported($locale) || $term->taxonomy !== $taxonomy->name || $term->locale === $locale || $this->collections->onlyTagged($taxonomy->name)) {
            return [];
        }
        foreach ($this->terms->translations($term->translationGroup) as $translation) {
            if ($translation->locale === $locale) {
                return [];
            }
        }
        $form = new ToastForm($this->router->url(...TermRoutes::of($taxonomy, '.translate', ['id' => $term->id])), ['locale' => $locale], 'admin.content_locale.create', machineFrom: $term->locale, shortLabel: 'admin.content_locale.create_short');

        return [new FlashMessage('info', 'admin.content_locale.missing', ['language' => LanguageNames::endonym($locale), 'title' => $term->name], $form)];
    }
}
