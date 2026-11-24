<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Core\Application;

/**
 * How ib_users.totp_secret is kept: encrypted with config('app_secret')
 * (Crypto, AES-256-GCM), so a database dump or a read-only SQL injection does
 * not hand out everybody's second factor.
 *
 * The column is VARCHAR(64) and installs already exist, so the format has to
 * fit without a migration: the 20 raw bytes of the secret are encrypted
 * (nonce 12 + tag 16 + 20 = 48 bytes), which is exactly 64 Base64 characters
 * with no padding. A legacy plain secret is 32 Base32 characters, so the two
 * never look alike. Plain secrets keep working and are sealed the next time
 * the user signs in with them (VerifyController).
 *
 * Without app_secret (bin/install.php refuses to install without one) the
 * secret is stored as before, in plain text, and that is logged.
 */
final class TotpSecretStore
{
    private const SEALED_LENGTH = 64;

    public static function isSealed(?string $stored): bool
    {
        $stored = (string) $stored;
        return strlen($stored) === self::SEALED_LENGTH
            && preg_match('/^[A-Za-z0-9+\/]{64}$/', $stored) === 1;
    }

    /** Value to store for a Base32 secret. */
    public static function seal(string $secret): string
    {
        if ($secret === '' || self::isSealed($secret)) {
            return $secret;
        }
        $bytes = TOTP::secretBytes($secret);
        if (strlen($bytes) !== 20) {
            return $secret;   // not a secret this class generated: keep it as it is
        }
        try {
            $sealed = self::crypto()->encrypt($bytes);
        } catch (\Throwable $e) {
            error_log('[TotpSecretStore] TOTP secret stored in plain text: ' . $e->getMessage());
            return $secret;
        }
        return strlen($sealed) === self::SEALED_LENGTH ? $sealed : $secret;
    }

    /** Base32 secret from a stored value (sealed or legacy plain). '' when it cannot be read. */
    public static function open(?string $stored): string
    {
        $stored = (string) $stored;
        if (!self::isSealed($stored)) {
            return $stored;
        }
        try {
            return TOTP::secretFromBytes(self::crypto()->decrypt($stored));
        } catch (\Throwable $e) {
            error_log('[TotpSecretStore] cannot decrypt a TOTP secret (app_secret changed?): ' . $e->getMessage());
            return '';
        }
    }

    private static function crypto(): Crypto
    {
        return Crypto::fromConfig(Application::getInstance()->getConfig());
    }
}
