<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * DomainEvent — marker for a fact that has already happened.
 *
 * Events are:
 *   - past tense (MailSent, ContactCreated) — they describe something
 *     that OCCURRED, not a command to execute.
 *   - immutable value objects — all fields readonly.
 *   - carry enough data for any listener to work without extra queries
 *     (but not so much that the event becomes a god-object).
 *
 * Dispatching an event MUST NOT be necessary for the originating action
 * to succeed. If a listener fails, the core flow proceeds — the event
 * bus absorbs the exception and logs it.
 *
 * @package Intrabase\Domain\Contracts
 */
interface DomainEvent
{
    /**
     * Unique identifier for this event instance — UUID v4.
     *
     * Enables:
     *   - Deduplication when the bus (or a retry mechanism) may deliver
     *     the same event more than once.
     *   - Correlation across logs and persisted audit trails.
     *
     * Generated at construction (see AbstractDomainEvent). Opaque to
     * listeners; use equality only.
     */
    public function eventId(): string;

    /**
     * When the fact happened. Set at construction, never mutated.
     */
    public function occurredOn(): \DateTimeImmutable;
}
