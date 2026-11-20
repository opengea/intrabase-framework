<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * EventBusInterface — synchronous in-process pub/sub boundary.
 *
 * Listeners are registered by event class name and receive the event
 * instance at dispatch time. Dispatch is synchronous: when dispatch()
 * returns, all listeners have executed (or thrown-and-been-swallowed).
 *
 * Async delivery (queues, workers) is out of scope for the first
 * iteration. If we later need it, an AsyncEventBus adapter can
 * implement this same contract by persisting the event first.
 *
 * @package Intrabase\Domain\Contracts
 */
interface EventBusInterface
{
    /**
     * Register $listener to be called every time an event of class
     * $eventClass (or subclass) is dispatched.
     *
     * @template T of DomainEvent
     * @param  class-string<T>         $eventClass
     * @param  callable(T): void       $listener
     */
    public function subscribe(string $eventClass, callable $listener): void;

    /**
     * Fire $event to all listeners registered for its class or its
     * parent classes/interfaces.
     *
     * Listener exceptions are isolated: they are logged and then
     * swallowed so that the caller's core flow is not disturbed.
     */
    public function dispatch(DomainEvent $event): void;
}
