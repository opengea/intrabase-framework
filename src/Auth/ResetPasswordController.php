<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\AssetResolver;
use Intrabase\Support\Branding;
use Intrabase\Support\LoginLogger;
use Intrabase\Support\RateLimiter;
use PDO;

/**
 * ResetPasswordController
 *
 * Handles the password reset step:
 *  GET  /reset-password?token=xxx → show new password form
 *  POST /reset-password           → validate token, update password, redirect
 *
 * Security:
 * - Token validated against SHA-256 hash stored in DB
 * - Token must not be expired and not already used
 * - Password must be >= 8 chars
 * - Password hashed with bcrypt cost 12
 * - Rate limited: 5 attempts per IP per 15 min
 * - On success, token marked as used + all other tokens for user deleted
 */
final class ResetPasswordController
{
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS = 900; // 15 min
    private const MIN_PASS_LENGTH = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly Session    $session,
        private readonly CSRF       $csrf,
    ) {}

    public function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        match ($method) {
            'POST'  => $this->handlePost(),
            default => $this->handleGet(),
        };
    }

    // -------------------------------------------------------------------------
    // GET — show form
    // -------------------------------------------------------------------------

    private function handleGet(): void
    {
        $token = trim($_GET['token'] ?? '');
        if (!$this->isValidTokenFormat($token)) {
            $this->showError('L\'enllaç de restabliment no és vàlid.');
            return;
        }

        $reset = $this->findToken($token);
        if ($reset === null) {
            $this->showError('L\'enllaç ha caducat o ja ha estat utilitzat. Sol·licita un de nou.');
            return;
        }

        $this->showForm($token);
    }

    // -------------------------------------------------------------------------
    // POST — process form
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            $this->showError('Sol·licitud invàlida. Torna-ho a intentar.');
            return;
        }

        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $limiter = new RateLimiter($this->db);

        if ($limiter->isBlocked('reset:ip:' . $ip, self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS)) {
            $this->showError('Massa intents. Espera 15 minuts i torna-ho a intentar.');
            return;
        }

        $token = Input::post('token');
        $pass1 = trim($_POST['password'] ?? '');
        $pass2 = trim($_POST['password_confirm'] ?? '');

        if (!$this->isValidTokenFormat($token)) {
            $this->showError('Sol·licitud invàlida.');
            return;
        }

        $reset = $this->findToken($token);
        if ($reset === null) {
            $limiter->record('reset:ip:' . $ip);
            $this->showError('L\'enllaç ha caducat o ja ha estat utilitzat. Sol·licita un de nou.');
            return;
        }

        if (strlen($pass1) < self::MIN_PASS_LENGTH) {
            $this->showForm($token, 'La contrasenya ha de tenir com a mínim ' . self::MIN_PASS_LENGTH . ' caràcters.');
            return;
        }

        if ($pass1 !== $pass2) {
            $this->showForm($token, 'Les contrasenyes no coincideixen.');
            return;
        }

        // Update password
        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo  = $this->db->pdo();

        $pdo->prepare(
            'UPDATE ib_users SET password_hash = :hash, updated_at = NOW() WHERE id = :id'
        )->execute([':hash' => $hash, ':id' => (int)$reset['user_id']]);

        // Sync password to KMS mailbox (CRM + mail server)
        try {
            $st = $pdo->prepare('SELECT email FROM ib_users WHERE id = :id LIMIT 1');
            $st->execute([':id' => (int)$reset['user_id']]);
            $userRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($userRow && !empty($userRow['email'])) {
                \Intrabase\Domain\Events\UserPasswordChanged::publish((int) $reset['user_id'], (string) $userRow['email'], $pass1);
            }
        } catch (\Throwable $e) {
            // Silent fail
        }

        // Mark token as used and delete all others for this user
        $pdo->prepare(
            'UPDATE ib_password_resets SET used_at = NOW() WHERE id = :id'
        )->execute([':id' => (int)$reset['id']]);

        $pdo->prepare(
            'DELETE FROM ib_password_resets WHERE user_id = :uid AND id != :id'
        )->execute([':uid' => (int)$reset['user_id'], ':id' => (int)$reset['id']]);

        // Clear rate limits
        $limiter->clear('reset:ip:' . $ip);

        $userId = (int)$reset['user_id'];

        // A new password ends every other way in: other sessions and the
        // devices marked as trusted (someone who knew the old password may
        // be using them).
        try {
            $pdo->prepare('DELETE FROM ib_user_trusted_devices WHERE user_id = :uid')->execute([':uid' => $userId]);
            $pdo->prepare('DELETE FROM ib_sessions WHERE user_id = :uid')->execute([':uid' => $userId]);
        } catch (\Throwable $e) {
            error_log('[ResetPasswordController] revoke sessions/devices failed: ' . $e->getMessage());
        }

        $this->autoLogin($userId, $ip);
    }

    /**
     * Log the user in after a successful password reset.
     *
     * The reset link only proves access to the mailbox. A user with a second
     * factor (TOTP or e-mail code) goes back to the login form and through the
     * normal 2FA step; otherwise the link would be a way around it. The
     * device is never marked as trusted here.
     */
    private function autoLogin(int $userId, string $ip): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.name, u.avatar, u.locale, u.theme,
                    u.totp_enabled, u.email_2fa_enabled,
                    GROUP_CONCAT(ug.group_id) AS group_ids
               FROM ib_users u
               LEFT JOIN ib_user_groups ug ON ug.user_id = u.id
              WHERE u.id = :id AND u.active = 1
              GROUP BY u.id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !empty($user['totp_enabled']) || !empty($user['email_2fa_enabled'])) {
            $this->session->flash('success', 'Contrasenya actualitzada correctament. Ara pots accedir.');
            header('Location: /login', true, 302);
            exit;
        }

        // Update last login IP
        $pdo->prepare('UPDATE ib_users SET last_login_ip = :ip, last_login = NOW() WHERE id = :id')
            ->execute([':ip' => $ip, ':id' => $userId]);

        // Build session
        $userAccount = [
            'id'        => (int)$user['id'],
            'username'  => (string)($user['username'] ?? ''),
            'email'     => (string)$user['email'],
            'name'      => (string)($user['name'] ?? ''),
            'avatar'    => (string)($user['avatar'] ?? ''),
            'locale'    => (string)($user['locale'] ?? 'en'),
            'theme'     => (string)($user['theme'] ?? 'dark'),
            'group_ids' => array_map('intval', array_filter(explode(',', $user['group_ids'] ?? ''))),
        ];

        $this->session->login($userAccount);
        LoginLogger::log($this->db, 'login', $userId, $ip, (string)$user['email']);

        // Load default entity
        try {
            $defaultEntity = $this->db->fetchOne(
                'SELECT ue.entity_id, e.name, e.logo, e.color, e.type
                 FROM ib_user_entities ue
                 JOIN ib_obj_entities e ON e.id = ue.entity_id
                 WHERE ue.user_id = :uid AND e.status = "active"
                 ORDER BY ue.is_default DESC, e.name ASC LIMIT 1',
                ['uid' => $userId]
            );
            if ($defaultEntity) {
                $this->session->set('_ib_active_entity', [
                    'id'    => (int)$defaultEntity['entity_id'],
                    'name'  => $defaultEntity['name'],
                    'logo'  => $defaultEntity['logo'] ?? '',
                    'color' => $defaultEntity['color'] ?? '',
                    'type'  => $defaultEntity['type'] ?? '',
                ]);
            }
        } catch (\Throwable) {}

        $this->session->flash('success', 'Contrasenya actualitzada correctament.');
        header('Location: /', true, 302);
        exit;
    }


    // -------------------------------------------------------------------------
    // Token validation
    // -------------------------------------------------------------------------

    private function isValidTokenFormat(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    private function findToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $pdo       = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT id, user_id, expires_at, used_at
               FROM ib_password_resets
              WHERE token_hash = :hash
                AND expires_at > NOW()
                AND used_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Views
    // -------------------------------------------------------------------------

    private function showForm(string $token, string $error = ''): void
    {
        $csrfField  = $this->csrf->field();
        $safeToken  = Output::e($token);
        $errorHtml  = $error !== ''
            ? '<div class="ib-alert ib-alert--danger" role="alert">' . Output::e($error) . '</div>'
            : '';
        $intrabaseCssUrl = AssetResolver::url('/assets/css/intrabase.css');
        $intrabaseJsUrl  = AssetResolver::url('/assets/js/intrabase.js');
        $brandName       = Output::e(Branding::name());
        $brandLogo       = Output::e(Branding::loginLogo());
        $brandLogoClass  = Output::e(Branding::logoImgClass());
        $loginMarkHtml = \Intrabase\Support\Branding::loginMarkHtml();

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="ca">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <meta http-equiv="Content-Security-Policy"
                  content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'">
            <script>
            (function(){var t=localStorage.getItem('ib-theme');if(!t)t=window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t)})();
            </script>
            <title>{$brandName} — Nova contrasenya</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
        </head>
        <body class="ib-login-page">
            <div class="ib-login-box">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>
                <h2 style="font-size:1.1rem;margin-bottom:1rem;color:var(--ib-text)">Nova contrasenya</h2>
                {$errorHtml}
                <form class="ib-form" method="post" action="/reset-password" novalidate>
                    {$csrfField}
                    <input type="hidden" name="token" value="{$safeToken}">
                    <div class="ib-form__row">
                        <label class="ib-form__label" for="password">Nova contrasenya</label>
                        <input class="ib-input" type="password" id="password" name="password"
                               autocomplete="new-password" required autofocus
                               minlength="8" placeholder="Mínim 8 caràcters">
                    </div>
                    <div class="ib-form__row">
                        <label class="ib-form__label" for="password_confirm">Confirma la contrasenya</label>
                        <input class="ib-input" type="password" id="password_confirm" name="password_confirm"
                               autocomplete="new-password" required>
                    </div>
                    <div class="ib-form__row">
                        <button class="ib-btn ib-btn--primary" type="submit">Guardar nova contrasenya</button>
                    </div>
                </form>
                <div style="margin-top:1.5rem;text-align:center">
                    <a href="/login" style="color:var(--ib-primary);font-size:.9rem">← Tornar a l'accés</a>
                </div>
            </div>
            <script src="{$intrabaseJsUrl}"></script>
        </body>
        </html>
        HTML;
    }

    private function showError(string $message): void
    {
        $safeMsg = Output::e($message);
        $intrabaseCssUrl = AssetResolver::url('/assets/css/intrabase.css');
        $intrabaseJsUrl  = AssetResolver::url('/assets/js/intrabase.js');
        $brandName       = Output::e(Branding::name());
        $brandLogo       = Output::e(Branding::loginLogo());
        $brandLogoClass  = Output::e(Branding::logoImgClass());
        $loginMarkHtml = \Intrabase\Support\Branding::loginMarkHtml();
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="ca">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <meta http-equiv="Content-Security-Policy"
                  content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'">
            <script>
            (function(){var t=localStorage.getItem('ib-theme');if(!t)t=window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t)})();
            </script>
            <title>{$brandName} — Enllaç invàlid</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
        </head>
        <body class="ib-login-page">
            <div class="ib-login-box">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>
                <div class="ib-alert ib-alert--danger" role="alert">{$safeMsg}</div>
                <div style="margin-top:1.5rem;text-align:center">
                    <a href="/forgot-password" style="color:var(--ib-primary);font-size:.9rem">Sol·licitar nou enllaç</a>
                    &nbsp;·&nbsp;
                    <a href="/login" style="color:var(--ib-primary);font-size:.9rem">Tornar a l'accés</a>
                </div>
            </div>
            <script src="{$intrabaseJsUrl}"></script>
        </body>
        </html>
        HTML;
    }
}
