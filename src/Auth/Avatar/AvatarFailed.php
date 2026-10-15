<?php

declare(strict_types=1);

namespace AtarCms\Auth\Avatar;

use RuntimeException;

/**
 * A profile photo could not be downloaded or stored; GoogleAvatar logs it and keeps the previous photo.
 */
final class AvatarFailed extends RuntimeException {}
