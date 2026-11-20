<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * "Trusted device" for the second factor: a browser that passed the 2FA step
 * may skip it for a while.
 *
 * - The cookie (ib_device) holds a random token; the database keeps only its
 *   SHA-256, so a copy of the database cannot be replayed as a device.
 * - It lasts DAYS days and is NOT extended on every login: after DAYS days
 *   the second factor is asked again.
 * - It is only created after a successful second factor (VerifyController),
 *   never on a plain password login.
 * - A password reset deletes all the user's devices.
 */
final class TrustedDevice
{
    public const COOKIE = 'ib_device';
    public const DAYS   = 90;

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Whether this browser carries a valid, unexpired device for the user. */
    public static function isTrusted(Connection $db, int $userId): bool
    {
        $token = self::cookieToken();
        if ($token === null) {
            return false;
        }
        try {
            return $db->fetchOne(
                'SELECT id FROM ib_user_trusted_devices
                  WHERE user_id = :uid AND device_token = :hash AND expires_at > NOW()
                  LIMIT 1',
                ['uid' => $userId, 'hash' => self::hash($token)]
            ) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Remember this browser for the user (call only after a passed second factor). */
    public static function remember(Connection $db, int $userId, string $ip): void
    {
        try {
            $token   = bin2hex(random_bytes(32));
            $expires = time() + self::DAYS * 86400;
            $db->execute(
                'INSERT INTO ib_user_trusted_devices (user_id, device_token, ip, user_agent, expires_at)
                 VALUES (:uid, :hash, :ip, :ua, :exp)',
                [
                    'uid'  => $userId,
                    'hash' => self::hash($token),
                    'ip'   => $ip,
                    'ua'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    'exp'  => date('Y-m-d H:i:s', $expires),
                ]
            );
            setcookie(self::COOKIE, $token, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        } catch (\Throwable $e) {
            error_log('[TrustedDevice] remember failed: ' . $e->getMessage());
        }
    }

    /** Forget this browser (logout). */
    public static function forget(Connection $db, int $userId): void
    {
        $token = self::cookieToken();
        if ($token !== null) {
            try {
                $db->execute(
                    'DELETE FROM ib_user_trusted_devices WHERE user_id = :uid AND device_token = :hash',
                    ['uid' => $userId, 'hash' => self::hash($token)]
                );
            } catch (\Throwable) {
                // Non-critical
            }
        }
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function cookieToken(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        return is_string($token) && strlen($token) === 64 && ctype_xdigit($token) ? $token : null;
    }
}
