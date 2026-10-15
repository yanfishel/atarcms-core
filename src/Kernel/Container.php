<?php

declare(strict_types=1);

namespace AtarCms\Kernel;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class Container implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
        $this->instances[ContainerInterface::class] = $this;
    }

    public function set(string $id, mixed $value): void
    {
        unset($this->factories[$id]);
        $this->instances[$id] = $value;
    }

    /**
     * @param Closure(self): mixed $factory
     */
    public function factory(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = $factory;
    }

    public function bind(string $id, string $concrete): void
    {
        $this->factory($id, static fn(self $container): mixed => $container->get($concrete));
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->instances) || isset($this->factories[$id])) {
            return true;
        }

        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException(sprintf(
                'Circular dependency: %s -> %s',
                implode(' -> ', array_keys($this->resolving)),
                $id,
            ));
        }

        $this->resolving[$id] = true;
        try {
            $value = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->instances[$id] = $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function service(string $class): object
    {
        $service = $this->get($class);
        if (!$service instanceof $class) {
            throw new ContainerException(sprintf(
                'Service "%s" resolved to %s',
                $class,
                get_debug_type($service),
            ));
        }

        return $service;
    }

    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new NotFoundException(sprintf('Service "%s" is not registered and is not a class', $id));
        }
        $reflection = new ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Cannot instantiate %s; register a factory or binding', $id));
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $id);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter, string $owner): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
            return $this->get($type->getName());
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s of %s (type %s)',
            $parameter->getName(),
            $owner,
            $type === null ? 'none' : (string) $type,
        ));
    }
}
