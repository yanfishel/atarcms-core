<?php

declare(strict_types=1);

namespace AtarCms\Content;

use AtarCms\Auth\Authorizer;
use AtarCms\Auth\User;
use AtarCms\Http\Exception\Forbidden;

/**
 * Who may do what with entries. Collection-level permissions are "entries.{collection}.{action}";
 * editing or deleting someone else's entry also needs "entries.{collection}.edit_others", and saving
 * or deleting a published or scheduled entry needs "entries.{collection}.publish".
 */
final class EntryAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function canView(?User $user, Collection $collection): bool
    {
        return $this->authorizer->can($user, $collection->permission('view'));
    }

    public function canCreate(?User $user, Collection $collection): bool
    {
        return $this->authorizer->can($user, $collection->permission('create'));
    }

    public function canPublish(?User $user, Collection $collection): bool
    {
        return $this->authorizer->can($user, $collection->permission('publish'));
    }

    /**
     * Editing the collection's published entries: a plain single's (always published), or what belongs to the page of
     * a single() as a whole, such as the site's map on the contact page (ContactPage).
     */
    public function canEditPublished(?User $user, Collection $collection): bool
    {
        return $this->authorizer->can($user, $collection->permission('edit')) && $this->canPublish($user, $collection);
    }

    /**
     * Opening the order screen of a hierarchical collection; every entry that actually moves must also pass canEdit().
     */
    public function canReorder(?User $user, Collection $collection): bool
    {
        return $this->canView($user, $collection) && $this->authorizer->can($user, $collection->permission('edit'));
    }

    public function canEdit(?User $user, Entry $entry): bool
    {
        $collection = $entry->collection;

        return $this->authorizer->can($user, $collection->permission('edit'))
            && $this->ownsOrMayEditOthers($user, $entry)
            && ($entry->status === EntryStatus::Draft || $this->canPublish($user, $collection));
    }

    /**
     * Deleting a published or scheduled entry takes it offline, so it needs the publish permission too. The entry of a
     * single() is a fixed page of the site (the home page, the contacts), and so is a preset page (Collection::presets()):
     * never deleted.
     */
    public function canDelete(?User $user, Entry $entry): bool
    {
        return !$entry->collection->single
            && !$entry->isPreset()
            && $this->authorizer->can($user, $entry->collection->permission('delete'))
            && $this->ownsOrMayEditOthers($user, $entry)
            && ($entry->status === EntryStatus::Draft || $this->canPublish($user, $entry->collection));
    }

    /**
     * Creating a translation of $entry (EntryService::createTranslation()): the rights to create in its collection and
     * to edit it; a plain single's or a preset page's translation is published at once, so it also takes the publish
     * permission.
     */
    public function canTranslate(?User $user, Entry $entry): bool
    {
        $collection = $entry->collection;

        return $this->canCreate($user, $collection)
            && $this->canEdit($user, $entry)
            && (!($collection->plain || $entry->isPreset()) || $this->canPublish($user, $collection));
    }

    /**
     * @throws Forbidden
     */
    public function authorize(bool $allowed, string $what): void
    {
        if (!$allowed) {
            throw new Forbidden(sprintf('Not allowed: %s', $what));
        }
    }

    /**
     * @return list<Collection> the collections $user can view, in config order
     */
    public function viewable(?User $user, Collections $collections): array
    {
        return array_values(array_filter($collections->all(), fn(Collection $c): bool => $this->canView($user, $c)));
    }

    private function ownsOrMayEditOthers(?User $user, Entry $entry): bool
    {
        return ($user !== null && $entry->authorId === $user->id)
            || $this->authorizer->can($user, $entry->collection->permission('edit_others'));
    }
}
