<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * Clock — temporal boundary
 *
 * Application/domain code must read "now" through this interface,
 * never via date()/time()/DateTime::now(). This makes time-dependent
 * logic deterministic in tests (FrozenClock) and keeps future changes
 * like fake timezones centralised.
 *
 * Concrete implementations:
 *   - Intrabase\Infrastructure\Clock\SystemClock  (new \DateTimeImmutable('now'))
 *   - Intrabase\Tests\Fakes\FrozenClock           (fixed instant, freely settable)
 *
 * @package Intrabase\Domain\Contracts
 */
interface Clock
{
    /**
     * Current timestamp as an immutable, timezone-aware value.
     */
    public function now(): \DateTimeImmutable;
}
