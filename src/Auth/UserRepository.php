<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Database\Connection;
use AtarCms\Database\Row;
use AtarCms\Kernel\Clock;
use AtarCms\Settings\AdminAccent;
use LogicException;

final class UserRepository
{
    private const string COLUMNS = 'id, email, name, role, google_sub, status, locale, content_locale, last_login_at, created_at, avatar_version, accent';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {}

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function find(int $id): ?User
    {
        return $this->one('id = ?', [$id]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->one('email = ?', [self::normalizeEmail($email)]);
    }

    public function findByGoogleSub(string $sub): ?User
    {
        return $this->one('google_sub = ?', [$sub]);
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return array_map(self::hydrate(...), $this->db->select('SELECT ' . self::COLUMNS . ' FROM users ORDER BY email'));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> id => name (or email when the name is empty); unknown ids are left out
     */
    public function displayNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->db->select(
            'SELECT id, name, email FROM users WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            $ids,
        ) as $row) {
            $name = Row::string($row, 'name');
            $names[Row::int($row, 'id')] = $name !== '' ? $name : Row::string($row, 'email');
        }

        return $names;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, User> id => user, by id; unknown ids are left out
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $users = [];
        foreach ($this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            $ids,
        ) as $row) {
            $user = self::hydrate($row);
            $users[$user->id] = $user;
        }

        return $users;
    }

    public function create(string $email, string $role, string $name = ''): User
    {
        $id = $this->db->insert('users', [
            'email' => self::normalizeEmail($email),
            'name' => trim($name),
            'role' => $role,
            'status' => UserStatus::Active->value,
            'created_at' => $this->clock->now(),
        ]);

        return $this->find($id) ?? throw new LogicException(sprintf('User #%d vanished after insert', $id));
    }

    public function update(int $id, string $name, string $role, UserStatus $status): void
    {
        $this->db->execute(
            'UPDATE users SET name = ?, role = ?, status = ? WHERE id = ?',
            [trim($name), $role, $status->value, $id],
        );
    }

    /**
     * Removes the user. The schema takes care of the rest: their login links go with them (CASCADE); the authors of
     * entries, revisions and files and the actors of audit rows become NULL (SET NULL).
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, UserStatus $status): void
    {
        $this->db->execute('UPDATE users SET status = ? WHERE id = ?', [$status->value, $id]);
    }

    /**
     * Stores the interface language as given; UserManager::setLocale() checks it against the site locales.
     */
    public function setLocale(int $id, ?string $locale): void
    {
        $this->db->execute('UPDATE users SET locale = ? WHERE id = ?', [$locale, $id]);
    }

    /**
     * Stores the user's admin colour; null follows the site colour.
     */
    public function setAccent(int $id, ?AdminAccent $accent): void
    {
        $this->db->execute('UPDATE users SET accent = ? WHERE id = ?', [$accent?->value, $id]);
    }

    /**
     * Stores the content language as given; UserManager::setContentLocale() checks it against the site locales.
     */
    public function setContentLocale(int $id, string $locale): void
    {
        $this->db->execute('UPDATE users SET content_locale = ? WHERE id = ?', [$locale, $id]);
    }

    /**
     * Binds a Google account to a user that has none yet; returns false if one is already bound.
     */
    public function bindGoogleSub(int $id, string $sub): bool
    {
        return $this->db->execute('UPDATE users SET google_sub = ? WHERE id = ? AND google_sub IS NULL', [$sub, $id]) === 1;
    }

    /**
     * The version of the user's stored profile photo (Avatars::save()), the ?v= of its URL.
     */
    public function setAvatarVersion(int $id, string $version): void
    {
        $this->db->execute('UPDATE users SET avatar_version = ? WHERE id = ?', [$version, $id]);
    }

    public function recordLogin(int $id): void
    {
        $this->db->execute('UPDATE users SET last_login_at = ? WHERE id = ?', [$this->clock->now(), $id]);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function one(string $where, array $bindings): ?User
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM users WHERE ' . $where . ' LIMIT 1', $bindings);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): User
    {
        return new User(
            id: Row::int($row, 'id'),
            email: Row::string($row, 'email'),
            name: Row::string($row, 'name'),
            role: Row::string($row, 'role'),
            googleSub: Row::nullableString($row, 'google_sub'),
            status: UserStatus::from(Row::string($row, 'status')),
            locale: Row::nullableString($row, 'locale'),
            contentLocale: Row::nullableString($row, 'content_locale'),
            lastLoginAt: Row::nullableDateTime($row, 'last_login_at'),
            createdAt: Row::dateTime($row, 'created_at'),
            avatarVersion: Row::nullableString($row, 'avatar_version'),
            accent: AdminAccent::tryFrom(Row::nullableString($row, 'accent') ?? ''),
        );
    }
}
