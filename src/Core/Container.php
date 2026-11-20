<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Simple Dependency Injection Container (PSR-11 compliant)
 *
 * Supports:
 * - Singleton bindings
 * - Factory bindings (callable)
 * - Concrete class auto-resolution
 *
 * @package Intrabase\Core
 */
class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Bind a factory callable to an identifier
     *
     * @param string   $id      Service identifier (typically class name or interface)
     * @param callable $factory Factory that creates the service
     * @param bool     $shared  If true (default), the instance is cached as a singleton
     */
    public function bind(string $id, callable $factory, bool $shared = true): void
    {
        $this->bindings[$id] = $factory;
        if ($shared) {
            // Lazy singleton: will be created on first get()
            unset($this->instances[$id]);
        }
    }

    /**
     * Bind a pre-created instance directly
     *
     * @param string $id       Service identifier
     * @param mixed  $instance The already-created instance
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Resolve a service from the container
     *
     * @param string $id Service identifier
     * @return mixed
     * @throws NotFoundException If the identifier cannot be resolved
     */
    public function get(string $id): mixed
    {
        // Return cached singleton
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Build from factory
        if (isset($this->bindings[$id])) {
            $instance = ($this->bindings[$id])($this);
            $this->instances[$id] = $instance;
            return $instance;
        }

        // Auto-resolve concrete class
        if (class_exists($id)) {
            return $this->build($id);
        }

        throw new NotFoundException("Service not found: {$id}");
    }

    /**
     * Check if the container can resolve an identifier
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->instances[$id])
            || class_exists($id);
    }

    /**
     * Auto-resolve a class via reflection (simple autowiring)
     *
     * @throws \RuntimeException If class cannot be resolved
     */
    public function build(string $class): object
    {
        try {
            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable()) {
                throw new \RuntimeException("Class {$class} is not instantiable");
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $class();
            }

            $params = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $params[] = $this->get($type->getName());
                } elseif ($param->isDefaultValueAvailable()) {
                    $params[] = $param->getDefaultValue();
                } else {
                    throw new \RuntimeException(
                        "Cannot resolve parameter '{$param->getName()}' for {$class}"
                    );
                }
            }

            return $reflection->newInstanceArgs($params);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException("Failed to build {$class}: " . $e->getMessage());
        }
    }
}

/**
 * Exception thrown when a service is not found in the container
 */
class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
