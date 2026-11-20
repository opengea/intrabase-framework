<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * LoginLogger
 *
 * Writes authentication events to ib_sys_login_log.
 * Called from LoginController, VerifyController, and Application.
 */
final class LoginLogger
{
    /**
     * Log an auth event.
     *
     * @param Connection $db
     * @param string     $eventType  login|logout|failed|2fa_sent|2fa_verified
     * @param int|null   $userId     NULL for unknown users (failed logins)
     * @param string     $ip
     * @param string     $email      Optional — useful for failed attempts
     */
    public static function log(
        Connection $db,
        string     $eventType,
        ?int       $userId,
        string     $ip,
        string     $email = ''
    ): void {
        try {
            $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $db->execute(
                'INSERT INTO ib_sys_login_log
                    (user_id, email, event_type, ip, user_agent, created_at)
                 VALUES
                    (:uid, :email, :event, :ip, :ua, NOW())',
                [
                    'uid'   => $userId,
                    'email' => $email !== '' ? $email : null,
                    'event' => $eventType,
                    'ip'    => $ip,
                    'ua'    => $ua,
                ]
            );
        } catch (\Throwable $e) {
            // Never let logging break the auth flow
            error_log('[LoginLogger] Failed to write login log: ' . $e->getMessage());
        }
    }
}
