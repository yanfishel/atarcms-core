<?php

declare(strict_types=1);

namespace AtarCms\Media;

use RuntimeException;

/**
 * An image could not be decoded or encoded.
 */
final class ImageFailed extends RuntimeException {}
