<?php

declare(strict_types=1);

namespace Intrabase\Tests\Fakes;

use Intrabase\Domain\Contracts\Clock;

/**
 * FrozenClock — returns a fixed instant, settable from the test.
 *
 * @package Intrabase\Tests\Fakes
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string|\DateTimeImmutable $at = '2026-01-01 00:00:00')
    {
        $this->now = $at instanceof \DateTimeImmutable
            ? $at
            : new \DateTimeImmutable($at);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string|\DateTimeImmutable $at): void
    {
        $this->now = $at instanceof \DateTimeImmutable
            ? $at
            : new \DateTimeImmutable($at);
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
