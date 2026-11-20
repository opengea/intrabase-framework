<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * AbstractDomainEvent — default implementation of DomainEvent identity.
 *
 * Concrete events should extend this class and add their payload fields.
 * Identity fields ($eventId, $occurredAt) are generated automatically
 * on construction; both may be injected for reconstitution from an
 * event log or across process boundaries.
 *
 * Example:
 *   final class ContactCreated extends AbstractDomainEvent {
 *       public function __construct(
 *           public readonly int    $contactId,
 *           public readonly string $fullName,
 *       ) {
 *           parent::__construct();
 *       }
 *   }
 *
 * @package Intrabase\Domain\Contracts
 */
abstract class AbstractDomainEvent implements DomainEvent
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        ?string $eventId = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventId    = $eventId ?? self::uuidV4();
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    final public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Generate a RFC-4122 UUID v4 (128 random bits + variant + version).
     * No external dependency required.
     */
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);   // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);   // variant RFC 4122
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
