<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Security;

use Intrabase\Security\TOTP;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Intrabase\Security\TOTP
 */
class TOTPTest extends TestCase
{
    /** RFC 6238 appendix B, SHA-1 secret "12345678901234567890", last 6 digits. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testRfcVectors(): void
    {
        $this->assertSame('287082', TOTP::getCode(self::SECRET, 59));
        $this->assertSame('081804', TOTP::getCode(self::SECRET, 1111111109));
        $this->assertSame('005924', TOTP::getCode(self::SECRET, 1234567890));
    }

    public function testMatchStepReturnsTheCounterOfTheCode(): void
    {
        $t    = 1234567890;
        $step = intdiv($t, 30);
        $this->assertSame($step, TOTP::matchStep(self::SECRET, TOTP::getCode(self::SECRET, $t), $t));
        // One period of drift either way is accepted, and says which step it was.
        $this->assertSame($step - 1, TOTP::matchStep(self::SECRET, TOTP::getCode(self::SECRET, $t - 30), $t));
        $this->assertSame($step + 1, TOTP::matchStep(self::SECRET, TOTP::getCode(self::SECRET, $t + 30), $t));
    }

    public function testCodesOutsideTheWindowOrMalformedAreRefused(): void
    {
        $t = 1234567890;
        $this->assertNull(TOTP::matchStep(self::SECRET, TOTP::getCode(self::SECRET, $t - 90), $t));
        $this->assertNull(TOTP::matchStep(self::SECRET, '12345', $t));
        $this->assertNull(TOTP::matchStep(self::SECRET, '12a456', $t));
        $this->assertFalse(TOTP::verify(self::SECRET, TOTP::getCode(self::SECRET, $t + 120), $t));
    }

    public function testReplayWindowCoversTheAcceptanceWindow(): void
    {
        // A code is accepted from step-1 to step+1: 90 s. The used step must be
        // remembered at least that long.
        $this->assertGreaterThanOrEqual(90, TOTP::replayWindowSeconds());
    }
}
