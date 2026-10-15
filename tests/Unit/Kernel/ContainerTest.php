<?php

declare(strict_types=1);

namespace AtarCms\Tests\Unit\Kernel;

use AtarCms\Kernel\Container;
use AtarCms\Kernel\ContainerException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

interface ClockFixture {}

final class SystemClockFixture implements ClockFixture {}

final class LeafFixture {}

final class NodeFixture
{
    public function __construct(public readonly LeafFixture $leaf, public readonly int $limit = 10) {}
}

final class NeedsClockFixture
{
    public function __construct(public readonly ClockFixture $clock) {}
}

final class OptionalClockFixture
{
    public function __construct(public readonly ?ClockFixture $clock = null) {}
}

final class NeedsScalarFixture
{
    public function __construct(public readonly string $name) {}
}

final class NullableScalarFixture
{
    public function __construct(public readonly ?string $name) {}
}

final class ObjectDefaultFixture
{
    public function __construct(public readonly ClockFixture $clock = new SystemClockFixture()) {}
}

final class CircularAFixture
{
    public function __construct(public readonly CircularBFixture $b) {}
}

final class CircularBFixture
{
    public function __construct(public readonly CircularAFixture $a) {}
}

final class ContainerTest extends TestCase
{
    public function testAutowiresAndSharesInstances(): void
    {
        $container = new Container();

        $node = $container->get(NodeFixture::class);

        self::assertInstanceOf(NodeFixture::class, $node);
        self::assertSame(10, $node->limit);
        self::assertSame($node, $container->get(NodeFixture::class));
        self::assertSame($node->leaf, $container->get(LeafFixture::class));
    }

    public function testFactoryIsLazyAndCalledOnce(): void
    {
        $container = new Container();
        $calls = 0;
        $container->factory('answer', static function () use (&$calls): int {
            $calls++;

            return 42;
        });

        self::assertSame(0, $calls);
        $first = $container->get('answer');
        $second = $container->get('answer');
        self::assertSame(42, $first);
        self::assertSame(42, $second);
        self::assertSame(1, $calls);
    }

    public function testBindsInterfaceToImplementation(): void
    {
        $container = new Container();
        $container->bind(ClockFixture::class, SystemClockFixture::class);

        self::assertInstanceOf(SystemClockFixture::class, $container->service(NeedsClockFixture::class)->clock);
    }

    public function testOptionalUnresolvableDependencyUsesDefault(): void
    {
        self::assertNull((new Container())->service(OptionalClockFixture::class)->clock);
    }

    public function testNullableParameterWithoutDefaultResolvesToNull(): void
    {
        self::assertNull((new Container())->service(NullableScalarFixture::class)->name);
    }

    public function testNewInInitializerDefaultIsUsed(): void
    {
        self::assertInstanceOf(SystemClockFixture::class, (new Container())->service(ObjectDefaultFixture::class)->clock);
    }

    public function testUnresolvableInterfaceParameterFailsWithContext(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve parameter $clock of ' . NeedsClockFixture::class);

        (new Container())->get(NeedsClockFixture::class);
    }

    public function testScalarParameterWithoutDefaultFails(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');

        (new Container())->get(NeedsScalarFixture::class);
    }

    public function testDetectsCircularDependencies(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        (new Container())->get(CircularAFixture::class);
    }

    public function testUnknownIdThrowsNotFound(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        (new Container())->get('no.such.service');
    }

    public function testHas(): void
    {
        $container = new Container();
        $container->set('config.value', null);

        self::assertTrue($container->has('config.value'));
        self::assertTrue($container->has(LeafFixture::class));
        self::assertFalse($container->has(ClockFixture::class));
        self::assertFalse($container->has('no.such.service'));
    }

    public function testRegistersItselfAndSetOverrides(): void
    {
        $container = new Container();
        $leaf = new LeafFixture();
        $container->set(LeafFixture::class, $leaf);

        self::assertSame($container, $container->get(ContainerInterface::class));
        self::assertSame($container, $container->get(Container::class));
        self::assertSame($leaf, $container->service(NodeFixture::class)->leaf);
    }
}
