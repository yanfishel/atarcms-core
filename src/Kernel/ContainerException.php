<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface {}
