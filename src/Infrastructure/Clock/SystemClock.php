<?php

declare(strict_types=1);

namespace Intrabase\Infrastructure\Clock;

use Intrabase\Domain\Contracts\Clock;

/**
 * SystemClock — returns the real wall-clock time.
 *
 * Use this in production wiring. Tests should use FrozenClock instead
 * so assertions on timestamps are deterministic.
 *
 * @package Intrabase\Infrastructure\Clock
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
