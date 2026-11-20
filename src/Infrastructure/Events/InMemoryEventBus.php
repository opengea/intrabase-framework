<?php

declare(strict_types=1);

namespace Intrabase\Infrastructure\Events;

use Intrabase\Domain\Contracts\DomainEvent;
use Intrabase\Domain\Contracts\EventBusInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * InMemoryEventBus — synchronous, in-process pub/sub.
 *
 * Listeners are called in the order they were registered, grouped by
 * the event class (or any parent class/interface) they target.
 *
 * Exception policy: a listener that throws is isolated — the failure
 * is logged (via PSR-3) and the next listener runs. The originating
 * dispatcher is never disturbed by listener faults, because a domain
 * event is a FACT: once emitted, it cannot be un-done.
 *
 * @package Intrabase\Infrastructure\Events
 */
final class InMemoryEventBus implements EventBusInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function subscribe(string $eventClass, callable $listener): void
    {
        if (!interface_exists($eventClass) && !class_exists($eventClass)) {
            throw new \InvalidArgumentException(
                "Unknown event class/interface: {$eventClass}"
            );
        }
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->listenersFor($event) as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Event listener threw; isolating failure',
                    [
                        'event'     => $event::class,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]
                );
            }
        }
    }

    /**
     * Return every listener registered for the event's class, any parent
     * class, or any implemented interface. Order: registration order,
     * most-specific class first.
     *
     * @return list<callable>
     */
    private function listenersFor(DomainEvent $event): array
    {
        $out = [];
        $class = $event::class;

        // Exact class and its parents
        do {
            if (isset($this->listeners[$class])) {
                foreach ($this->listeners[$class] as $l) {
                    $out[] = $l;
                }
            }
            $class = get_parent_class($class);
        } while ($class !== false);

        // Interfaces
        foreach (class_implements($event) ?: [] as $iface) {
            if (isset($this->listeners[$iface])) {
                foreach ($this->listeners[$iface] as $l) {
                    $out[] = $l;
                }
            }
        }

        return $out;
    }
}
