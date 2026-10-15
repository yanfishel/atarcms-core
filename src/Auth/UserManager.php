<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Auth\Avatar\Avatars;
use AtarCms\Database\Connection;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\I18n\InterfaceLanguages;
use AtarCms\I18n\Locales;
use AtarCms\Settings\AdminAccent;
use InvalidArgumentException;
use LogicException;

/**
 * Validated, audited user administration shared by the CLI and the admin screens.
 */
final class UserManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Roles $roles,
        private readonly AuditLog $audit,
        private readonly Locales $locales,
        private readonly Avatars $avatars,
        private readonly Connection $db,
    ) {}

    /**
     * Whether $actor may manage $user: themselves, a user whose role $actor's role could grant, or a user whose role no
     * longer exists (so the stale role can be fixed).
     */
    public function canManage(User $actor, User $user): bool
    {
        return $user->id === $actor->id || !$this->roles->exists($user->role) || $this->roles->grants($actor->role, $user->role);
    }

    /**
     * Whether $actor may delete $user: a user they may manage, never themselves.
     */
    public function canDelete(User $actor, User $user): bool
    {
        return $user->id !== $actor->id && $this->canManage($actor, $user);
    }

    /**
     * Deletes the user for good (UserRepository::delete(): their content stays without an author) and their profile
     * photo, and records "user.deleted" with the e-mail, name and role, so the history still names them.
     *
     * @throws ValidationFailed for oneself or a user whose role the actor cannot grant (nothing is deleted)
     */
    public function delete(User $user, User $actor, ?string $ip): void
    {
        if ($user->id === $actor->id) {
            throw new ValidationFailed(['user' => 'validation.user.self_delete']);
        }
        if (!$this->canDelete($actor, $user)) {
            throw new ValidationFailed(['user' => 'validation.role.not_allowed']);
        }
        // The row and its audit entry together; the photo only once both are in (a file cannot roll back).
        $this->db->transaction(function () use ($user, $actor, $ip): void {
            $this->users->delete($user->id);
            $this->audit->record('user.deleted', $actor->id, 'user', $user->id, ['email' => $user->email, 'name' => $user->name, 'role' => $user->role], $ip);
        });
        $this->avatars->delete($user->id);
    }

    /**
     * Sets the user's interface language: one of the admin catalogs (InterfaceLanguages, independent of the site
     * locales), or null for none chosen (Admin\InterfaceLanguage then asks the browser). It is a personal preference
     * that grants nothing, so unlike the other changes here it writes no audit row.
     *
     * @throws InvalidArgumentException for a language the admin does not speak (nothing is stored)
     */
    public function setLocale(int $userId, ?string $locale): void
    {
        if ($locale !== null && !InterfaceLanguages::isSupported($locale)) {
            throw new InvalidArgumentException(sprintf('The admin has no interface language "%s"', $locale));
        }
        $this->users->setLocale($userId, $locale);
    }

    /**
     * Saves the Profile's preferences together: the interface language (one of InterfaceLanguages::ALL; null leaves the
     * stored one, as the linked mode's Profile has none) and the admin colour (an AdminAccent value, '' to follow the
     * site colour). Both are checked before either is stored; like the interface language alone they grant nothing and
     * write no audit row.
     *
     * @throws ValidationFailed with every refused field (nothing is stored)
     */
    public function savePreferences(int $userId, ?string $locale, string $accent): void
    {
        $errors = [];
        if ($locale !== null && !InterfaceLanguages::isSupported($locale)) {
            $errors['locale'] = 'validation.locale.interface';
        }
        $chosen = $accent === '' ? null : AdminAccent::tryFrom($accent);
        if ($accent !== '' && $chosen === null) {
            $errors['accent'] = 'validation.profile.accent';
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
        $this->db->transaction(function () use ($userId, $locale, $chosen): void {
            if ($locale !== null) {
                $this->users->setLocale($userId, $locale);
            }
            $this->users->setAccent($userId, $chosen);
        });
    }

    /**
     * Remembers the content language the user switched to in the admin (one of the site locales). Like the interface
     * language it is a personal preference and writes no audit row.
     *
     * @throws InvalidArgumentException for a locale the site does not have (nothing is stored)
     */
    public function setContentLocale(int $userId, string $locale): void
    {
        if (!$this->locales->isSupported($locale)) {
            throw new InvalidArgumentException(sprintf('The site has no locale "%s"', $locale));
        }
        $this->users->setContentLocale($userId, $locale);
    }

    /**
     * @throws ValidationFailed
     */
    public function create(string $email, string $role, string $name, ?User $actor, ?string $ip): User
    {
        $email = UserRepository::normalizeEmail($email);
        $name = trim($name);
        $errors = $this->validate($name, $role);
        if ($actor !== null && !isset($errors['role']) && !$this->roles->grants($actor->role, $role)) {
            $errors['role'] = 'validation.role.not_allowed';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'validation.email.invalid';
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors['email'] = 'validation.email.taken';
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        $user = $this->users->create($email, $role, $name);
        $this->audit->record('user.created', $actor?->id, 'user', $user->id, ['email' => $email, 'role' => $role], $ip);

        return $user;
    }

    public function disable(User $user, ?User $actor, ?string $ip): void
    {
        if ($user->status === UserStatus::Disabled) {
            return;
        }
        $this->users->setStatus($user->id, UserStatus::Disabled);
        $this->audit->record('user.disabled', $actor?->id, 'user', $user->id, ['email' => $user->email], $ip);
    }

    /**
     * @param UserStatus|null $status null when the submitted status was not a valid status
     * @param UserStatus|null $expectedStatus the status the form was opened with; a mismatch means a stale form
     *
     * @throws ValidationFailed
     */
    public function update(User $user, string $name, string $role, ?UserStatus $status, User $actor, ?string $ip, ?UserStatus $expectedStatus = null): User
    {
        if ($expectedStatus !== null && $expectedStatus !== $user->status) {
            throw new ValidationFailed(['status' => 'validation.user.stale']);
        }
        $name = trim($name);
        $errors = $this->validate($name, $role);
        if ($status === null) {
            $errors['status'] = 'validation.status.invalid';
        }
        if ($user->id === $actor->id) {
            if ($role !== $user->role) {
                $errors['role'] = 'validation.self_change';
            }
            if ($status !== null && $status !== $user->status) {
                $errors['status'] = 'validation.self_change';
            }
        } elseif (
            !isset($errors['role'])
            && (($this->roles->exists($user->role) && !$this->roles->grants($actor->role, $user->role)) || !$this->roles->grants($actor->role, $role))
        ) {
            $errors['role'] = 'validation.role.not_allowed';
        }
        if ($errors !== [] || $status === null) {
            throw new ValidationFailed($errors);
        }

        $changes = [];
        foreach (['name' => [$user->name, $name], 'role' => [$user->role, $role], 'status' => [$user->status->value, $status->value]] as $field => [$from, $to]) {
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }
        if ($changes === []) {
            return $user;
        }

        $this->users->update($user->id, $name, $role, $status);
        $action = match (true) {
            $status !== $user->status && $status === UserStatus::Disabled => 'user.disabled',
            $status !== $user->status => 'user.enabled',
            default => 'user.updated',
        };
        $this->audit->record($action, $actor->id, 'user', $user->id, ['email' => $user->email, 'changes' => $changes], $ip);

        return $this->users->find($user->id) ?? throw new LogicException(sprintf('User #%d vanished after update', $user->id));
    }

    /**
     * @return array<string, string>
     */
    private function validate(string $name, string $role): array
    {
        $errors = [];
        if (mb_strlen($name) > 190) {
            $errors['name'] = 'validation.name.too_long';
        }
        if (!$this->roles->exists($role)) {
            $errors['role'] = 'validation.role.unknown';
        }

        return $errors;
    }
}
