<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
