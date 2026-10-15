<?php

declare(strict_types=1);

namespace AtarCms\Auth;

use AtarCms\Settings\AdminAccent;
use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public string $role,
        public ?string $googleSub,
        public UserStatus $status,
        /** The interface language the user chose (one of the site locales); null means the site default. */
        public ?string $locale,
        /** The content language the user last switched to in the admin; null until the first switch. */
        public ?string $contentLocale,
        public ?DateTimeImmutable $lastLoginAt,
        public DateTimeImmutable $createdAt,
        /** The version of the profile photo in storage/avatars (Avatars), null without one. */
        public ?string $avatarVersion = null,
        /** The admin colour the user chose on the Profile; null follows the site colour (Settings::accent()). */
        public ?AdminAccent $accent = null,
    ) {}

    /**
     * The avatar's letters without a photo: the first letters of the first two words of the display name.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->displayName()), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(static fn(string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    /**
     * The same user with another remembered content language (after AdminLocale stored it for this request).
     */
    public function withContentLocale(string $contentLocale): self
    {
        return new self(
            $this->id,
            $this->email,
            $this->name,
            $this->role,
            $this->googleSub,
            $this->status,
            $this->locale,
            $contentLocale,
            $this->lastLoginAt,
            $this->createdAt,
            $this->avatarVersion,
            $this->accent,
        );
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
