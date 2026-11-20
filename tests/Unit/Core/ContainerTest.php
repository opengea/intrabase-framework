<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Core;

use Intrabase\Core\Container;
use Intrabase\Core\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the PSR-11 DI Container.
 *
 * @covers \Intrabase\Core\Container
 * @covers \Intrabase\Core\NotFoundException
 */
class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
    }

    /** @test Container implements PSR-11 ContainerInterface */
    public function implementsPsr11Interface(): void
    {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
    }

    /** @test instance() binds a pre-built object; get() returns it */
    public function instanceBindingReturnsExactObject(): void
    {
        $obj = new \stdClass();
        $obj->value = 'test';
        $this->container->instance('my.service', $obj);
        self::assertSame($obj, $this->container->get('my.service'));
    }

    /** @test has() returns true for a bound instance */
    public function hasReturnsTrueForBoundInstance(): void
    {
        $this->container->instance('foo', new \stdClass());
        self::assertTrue($this->container->has('foo'));
    }

    /** @test bind() shared=true returns the same instance every time */
    public function bindSharedReturnsSingleton(): void
    {
        $this->container->bind('counter', fn() => new \stdClass());
        $first  = $this->container->get('counter');
        $second = $this->container->get('counter');
        self::assertSame($first, $second);
    }

    /** @test factory callable is invoked on first get() */
    public function bindCallsFactory(): void
    {
        $callCount = 0;
        $this->container->bind('factory', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });
        $this->container->get('factory');
        self::assertSame(1, $callCount);
    }

    /** @test The factory receives the container as its first argument */
    public function factoryReceivesContainer(): void
    {
        $this->container->bind('dep', fn($c) => $c);
        $received = $this->container->get('dep');
        self::assertSame($this->container, $received);
    }

    /** @test Factory can resolve sub-dependencies via the container */
    public function factoryCanResolveDependencies(): void
    {
        $inner = new \stdClass();
        $inner->name = 'inner';
        $this->container->instance('inner', $inner);
        $this->container->bind('outer', function (Container $c) {
            $obj = new \stdClass();
            $obj->inner = $c->get('inner');
            return $obj;
        });
        $outer = $this->container->get('outer');
        self::assertSame($inner, $outer->inner);
    }

    /** @test has() returns false for an unregistered identifier */
    public function hasReturnsFalseForUnknownId(): void
    {
        self::assertFalse($this->container->has('nonexistent.service'));
    }

    /** @test has() returns true for a bound factory */
    public function hasReturnsTrueForBoundFactory(): void
    {
        $this->container->bind('my.factory', fn() => new \stdClass());
        self::assertTrue($this->container->has('my.factory'));
    }

    /** @test has() returns true for an existing class name */
    public function hasReturnsTrueForExistingClass(): void
    {
        self::assertTrue($this->container->has(\stdClass::class));
    }

    /** @test get() auto-wires an unregistered concrete class */
    public function getAutoWiresConcreteClass(): void
    {
        $obj = $this->container->get(\stdClass::class);
        self::assertInstanceOf(\stdClass::class, $obj);
    }

    /** @test get() throws NotFoundException for an unresolvable identifier */
    public function getThrowsNotFoundExceptionForUnknownId(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->get('service.that.does.not.exist');
    }

    /** @test NotFoundException implements PSR-11 NotFoundExceptionInterface */
    public function notFoundExceptionImplementsPsr11(): void
    {
        $e = new NotFoundException('test');
        self::assertInstanceOf(\Psr\Container\NotFoundExceptionInterface::class, $e);
    }

    /** @test build() instantiates a class with no constructor */
    public function buildInstantiatesClassWithNoConstructor(): void
    {
        $obj = $this->container->build(\stdClass::class);
        self::assertInstanceOf(\stdClass::class, $obj);
    }

    /** @test build() throws RuntimeException for a non-instantiable interface */
    public function buildThrowsForNonInstantiableClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->container->build(ContainerInterface::class);
    }
}
