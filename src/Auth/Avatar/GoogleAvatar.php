<?php

declare(strict_types=1);

namespace AtarCms\Auth\Avatar;

use AtarCms\Auth\User;
use AtarCms\Auth\UserRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a user's admin avatar in step with their Google profile photo on each Google sign-in. Best effort: any
 * failure (a foreign address, the network, the size, a file that is no image) is logged and the previous photo, or the
 * initials, stays; the sign-in never fails because of it.
 */
final class GoogleAvatar
{
    public function __construct(
        private readonly AvatarDownloader $downloader,
        private readonly Avatars $avatars,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {}

    public function update(User $user, ?string $picture): void
    {
        if ($picture === null) {
            return;
        }
        try {
            $version = $this->avatars->save($user->id, $this->downloader->download($picture));
            $this->users->setAvatarVersion($user->id, $version);
        } catch (Throwable $e) {
            $this->logger->notice('The Google profile photo of user #{id} was not stored: {message}', ['id' => $user->id, 'message' => $e->getMessage()]);
        }
    }
}
