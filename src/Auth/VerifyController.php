<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Security\TOTP;
use Intrabase\Security\TotpSecretStore;
use Intrabase\Support\AssetResolver;
use Intrabase\Support\LoginLogger;
use Intrabase\Support\RateLimiter;
use PDO;

/**
 * VerifyController
 *
 * Handles the second step of 2FA verification.
 * Supports two methods:
 *   - Email: 6-digit code sent by email (default)
 *   - TOTP:  App d'autenticació (Google Authenticator, Authy, etc.)
 *
 * TOTP enrollment is NOT done here: it needs a completed login
 * (System > Two-factor authentication). Doing it before the second factor
 * would let a stolen password register its own authenticator.
 */
final class VerifyController
{
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS = 15 * 60;

    public function __construct(
        private readonly Connection $db,
        private readonly Session    $session,
        private readonly CSRF       $csrf,
        private readonly array      $tenantConfig = [],
    ) {}

    public function handle(): void
    {
        if ($this->session->isAuthenticated()) {
            $this->redirect('/');
        }

        $pending = $this->session->get('pending_verify');
        if (!is_array($pending) || empty($pending['user_id'])) {
            $this->redirect('/login');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        match ($method) {
            'POST'  => $this->handlePost($pending),
            default => $this->showForm($pending),
        };
    }

    // -------------------------------------------------------------------------
    // Resend verification code
    // -------------------------------------------------------------------------

    public function handleResend(): void
    {
        if ($this->session->isAuthenticated()) {
            $this->redirect('/');
        }

        $pending = $this->session->get('pending_verify');
        if (!is_array($pending) || empty($pending['user_id'])) {
            $this->redirect('/login');
        }

        // Only a POST with the CSRF token sends a new code: a GET from another
        // site used to send mail and void the code the user was typing.
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
            || !$this->csrf->validateToken()) {
            $this->redirect('/login/verify');
        }

        $userId  = (int) $pending['user_id'];
        $ip      = $this->clientIp();
        $limiter = new RateLimiter($this->db);

        // Rate limit resends: max 3 per 5 minutes
        if ($limiter->isBlocked('resend:uid:' . $userId, 3, 300)) {
            $this->session->flash('error', 'Has sol·licitat massa codis. Espera uns minuts.');
            $this->redirect('/login/verify');
        }

        $limiter->record('resend:uid:' . $userId);

        // Clear old unused codes
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM ib_login_verification_codes
              WHERE user_id = :uid AND (used_at IS NOT NULL OR expires_at < NOW())'
        )->execute([':uid' => $userId]);

        // Also invalidate any pending unused codes so only the new one works
        $pdo->prepare(
            'UPDATE ib_login_verification_codes SET used_at = NOW()
              WHERE user_id = :uid AND used_at IS NULL'
        )->execute([':uid' => $userId]);

        // Generate new code
        $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        $expires  = date('Y-m-d H:i:s', time() + 15 * 60);

        $pdo->prepare(
            'INSERT INTO ib_login_verification_codes (user_id, code_hash, ip, expires_at)
             VALUES (:uid, :hash, :ip, :exp)'
        )->execute([
            ':uid'  => $userId,
            ':hash' => $codeHash,
            ':ip'   => $ip,
            ':exp'  => $expires,
        ]);

        // Get user info to send email
        $stmt = $pdo->prepare('SELECT email, name FROM ib_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $this->sendVerificationEmail((string)$user['email'], (string)($user['name'] ?? ''), $code);
        }

        // The attempt budget (verify:uid) is NOT reset here: a new code must not
        // buy new guesses, or resending would allow unlimited brute force.

        LoginLogger::log($this->db, '2fa_sent', $userId, $ip);

        $this->session->flash('success', 'S\'ha enviat un nou codi de verificació.');
        $this->redirect('/login/verify');
    }

    // -------------------------------------------------------------------------
    // GET – show verification form (email or TOTP)
    // -------------------------------------------------------------------------

    private function showForm(array $pending, string $error = ''): void
    {
        $csrfField  = $this->csrf->field();
        $error      = $error !== '' ? Output::e($error) : '';
        $intrabaseCssUrl = AssetResolver::url('/assets/css/intrabase.css');
        $intrabaseJsUrl  = AssetResolver::url('/assets/js/intrabase.js');
        $brandName  = Output::e($this->tenantConfig['client_name'] ?? 'Intrabase');
        $brandLogo  = htmlspecialchars(\Intrabase\Support\Branding::loginLogo(), ENT_QUOTES);
        $brandLogoClass = htmlspecialchars(\Intrabase\Support\Branding::logoImgClass(), ENT_QUOTES);
        $loginMarkHtml = \Intrabase\Support\Branding::loginMarkHtml();
        $locale     = \Intrabase\Lang\Lang::locale();

        // Check if user has TOTP enabled
        $userId  = (int) $pending['user_id'];
        $hasTOTP = $this->userHasTotp($userId);

        // Determine active method (default: email, or totp if user switched)
        $activeMethod = Input::get('method') ?: ($hasTOTP ? 'totp' : 'email');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <meta http-equiv="Content-Security-Policy"
                  content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:">
            <script>
            (function(){var m=/IntrabaseMobile/i.test(navigator.userAgent);var t=m?'dark':(localStorage.getItem('ib-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'));document.documentElement.setAttribute('data-theme',t)})();
            </script>
            <title>{$brandName} – Verificació</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
            <style>
                .ib-verify-code { text-align:center; margin:0.5rem 0 1rem; }
                .ib-verify-code .ib-input--code {
                    text-align:center; font-size:1.75rem; letter-spacing:0.5em;
                    max-width:240px; margin:0 auto; display:block; padding:0.6rem;
                }
                .ib-verify-actions {
                    display:flex; gap:0.5rem; justify-content:center; margin-top:1rem;
                }
                .ib-verify-actions .ib-btn { flex:1; max-width:160px; text-align:center; }
                .ib-verify-tabs {
                    display:flex; justify-content:center; gap:0; margin-bottom:1.25rem;
                    border-bottom:2px solid var(--ib-border, #e0e0e0);
                }
                .ib-verify-tab {
                    padding:0.6rem 1.2rem; font-size:.9rem; cursor:pointer;
                    border:none; background:none; color:var(--ib-text-muted, #666);
                    border-bottom:2px solid transparent; margin-bottom:-2px;
                    text-decoration:none; display:inline-flex; align-items:center; gap:0.4rem;
                }
                .ib-verify-tab--active {
                    color:var(--ib-text, #333); border-bottom-color:var(--ib-primary, #000);
                    font-weight:600;
                }
                .ib-verify-tab:hover { color:var(--ib-text, #333); }
                .ib-verify-setup-link {
                    display:block; text-align:center; margin-top:0.75rem;
                    font-size:.85rem; color:var(--ib-primary, #000);
                }
            </style>
        </head>
        <body class="ib-login-page">
            <div class="ib-login-box">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>

                {$this->flashBlock()}
        HTML;

        if ($error !== '') {
            echo '<div class="ib-alert ib-alert--danger" role="alert">' . $error . '</div>';
        }

        // Method tabs (only show if TOTP is available or as setup option)
        $emailActive = ($activeMethod === 'email') ? ' ib-verify-tab--active' : '';
        $totpActive  = ($activeMethod === 'totp') ? ' ib-verify-tab--active' : '';

        echo '<div class="ib-verify-tabs">';
        echo '<a href="/login/verify?method=email" class="ib-verify-tab' . $emailActive . '"><i class="fa-solid fa-envelope"></i> Email</a>';
        if ($hasTOTP) {
            echo '<a href="/login/verify?method=totp" class="ib-verify-tab' . $totpActive . '"><i class="fa-solid fa-mobile-screen"></i> Authenticator</a>';
        }
        echo '</div>';

        echo '<form class="ib-form" method="post" action="/login/verify" novalidate>';
        echo $csrfField;
        echo '<input type="hidden" name="verify_method" value="' . Output::attr($activeMethod) . '">';

        if ($activeMethod === 'totp') {
            echo <<<HTML
                <div class="ib-login-box__subtitle" style="text-align:center;margin-bottom:0.5rem">
                    <p>Introdueix el codi de 6 dígits de la teva app d'autenticació.</p>
                </div>
                <div class="ib-verify-code">
                    <label class="ib-form__label" for="code" style="text-align:center;display:block">Codi Authenticator</label>
                    <input class="ib-input ib-input--code" type="text" id="code" name="code"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           autocomplete="one-time-code" required autofocus placeholder="000000">
                </div>
            HTML;
        } else {
            echo <<<HTML
                <div class="ib-login-box__subtitle" style="text-align:center;margin-bottom:0.5rem">
                    <p>S'ha enviat un codi de 6 dígits al teu correu electrònic.</p>
                </div>
                <div class="ib-verify-code">
                    <label class="ib-form__label" for="code" style="text-align:center;display:block">Codi de verificació</label>
                    <input class="ib-input ib-input--code" type="text" id="code" name="code"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           autocomplete="one-time-code" required autofocus placeholder="000000">
                </div>
            HTML;
        }

        echo <<<HTML
                <div class="ib-verify-actions">
                    <button class="ib-btn ib-btn--primary" type="submit">Verificar</button>
                    <a href="/login" class="ib-btn ib-btn--secondary">Tornar</a>
                </div>
            </form>
        HTML;

        if ($activeMethod === 'email') {
            echo '<form method="post" action="/login/verify/resend" style="text-align:center;margin-top:1rem">'
               . $csrfField
               . '<button type="submit" style="background:none;border:0;padding:0;cursor:pointer;'
               . 'color:var(--ib-primary,#000);font-size:.875rem;font-family:inherit">'
               . '<i class="fa-solid fa-rotate-right"></i> Tornar a enviar el codi</button></form>';
        }

        // TOTP setup moved to /preferences

        echo <<<HTML
            </div>
            <footer class="ib-login-footer">
                <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener">Built with Intrabase</a>
            </footer>
            <script src="{$intrabaseJsUrl}"></script>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // POST – validate code (email or TOTP)
    // -------------------------------------------------------------------------

    private function handlePost(array $pending): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            $this->showForm($pending, 'Sol·licitud invàlida. Torna-ho a intentar.');
            return;
        }

        $code        = preg_replace('/\D/', '', Input::post('code'));
        $ip          = $this->clientIp();
        $userId      = (int) $pending['user_id'];
        $verifyMethod = Input::post('verify_method') ?: 'email';
        $limiter     = new RateLimiter($this->db);

        if ($limiter->isBlocked('verify:uid:' . $userId, self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS)
            || $limiter->isBlocked('verify:ip:' . $ip, self::MAX_ATTEMPTS * 4, self::LOCKOUT_SECONDS)) {
            $this->showForm($pending, 'Massa intents fallits. Espera 15 minuts i torna-ho a intentar.');
            return;
        }

        if (strlen($code) !== 6) {
            $limiter->record('verify:uid:' . $userId);
            $limiter->record('verify:ip:' . $ip);
            $this->showForm($pending, 'El codi ha de tenir 6 dígits.');
            return;
        }

        $valid = false;

        if ($verifyMethod === 'totp') {
            $valid = $this->verifyTotp($userId, $code);
        } else {
            $valid = $this->verifyEmailCode($userId, $ip, $code);
        }

        if (!$valid) {
            $limiter->record('verify:uid:' . $userId);
            $limiter->record('verify:ip:' . $ip);
            $this->showForm($pending, 'Codi incorrecte o expirat. Torna-ho a intentar.');
            return;
        }

        $limiter->clear('verify:uid:' . $userId);

        // Always save device token for persistent login across IP changes
        \Intrabase\Support\TrustedDevice::remember($this->db, $userId, $ip);

        $this->completeLogin($userId, $ip, $pending);
    }

    private function verifyEmailCode(int $userId, string $ip, string $code): bool
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash
               FROM ib_login_verification_codes
              WHERE user_id = :uid AND ip = :ip AND used_at IS NULL AND expires_at > NOW()
           ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':ip' => $ip]);
        $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeRow || !password_verify($code, (string)$codeRow['code_hash'])) {
            return false;
        }

        $pdo->prepare('UPDATE ib_login_verification_codes SET used_at = NOW() WHERE id = :id')
            ->execute([':id' => (int)$codeRow['id']]);

        return true;
    }

    private function verifyTotp(int $userId, string $code): bool
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT totp_secret FROM ib_users WHERE id = :id AND totp_enabled = 1 LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $secret = $stmt->fetchColumn();

        if (!$secret) {
            return false;
        }

        $stored = (string) $secret;
        $secret = TotpSecretStore::open($stored);
        $step   = $secret === '' ? null : TOTP::matchStep($secret, $code);
        if ($step === null) {
            return false;
        }

        // A secret from before it was encrypted is sealed now that it has been used.
        if (!TotpSecretStore::isSealed($stored)) {
            $sealed = TotpSecretStore::seal($secret);
            if ($sealed !== $stored) {
                $pdo->prepare('UPDATE ib_users SET totp_secret = :s WHERE id = :id AND totp_secret = :old')
                    ->execute([':s' => $sealed, ':id' => $userId, ':old' => $stored]);
            }
        }

        // A code opens ONE session: refuse its step, or an older one, once a
        // session was opened with it or with a later step. The step is recorded
        // first and counted after, so two simultaneous requests with the same
        // code cannot both get through.
        $limiter = new RateLimiter($this->db);
        $window  = TOTP::replayWindowSeconds();
        $key     = 'totp_step:' . $userId . ':' . $step;
        $limiter->record($key);
        if ($limiter->countHits($key, $window) > 1
            || $limiter->countHits('totp_step:' . $userId . ':' . ($step + 1), $window) > 0
            || $limiter->countHits('totp_step:' . $userId . ':' . ($step + 2), $window) > 0) {
            error_log('[VerifyController] TOTP code reused, user ' . $userId);
            return false;
        }

        return true;
    }

    private function userHasTotp(int $userId): bool
    {
        try {
            $pdo  = $this->db->pdo();
            $stmt = $pdo->prepare('SELECT totp_enabled FROM ib_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Complete login flow
    // -------------------------------------------------------------------------

    private function completeLogin(int $userId, string $ip, array $pending): void
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.email, u.name, u.avatar, u.locale, u.theme,
                    GROUP_CONCAT(ug.group_id) AS group_ids
               FROM ib_users u
               LEFT JOIN ib_user_groups ug ON ug.user_id = u.id
              WHERE u.id = :id AND u.active = 1
              GROUP BY u.id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->session->forget('pending_verify');
            $this->redirect('/login');
        }

        $pdo->prepare('UPDATE ib_users SET last_login_ip = :ip, last_login = NOW() WHERE id = :id')
            ->execute([':ip' => $ip, ':id' => $userId]);

        $userAccount = [
            'id'        => (int) $user['id'],
            'username'  => (string) ($user['username'] ?? ''),
            'email'     => (string) $user['email'],
            'name'      => (string) ($user['name'] ?? ''),
            'avatar'    => (string) ($user['avatar'] ?? ''),
            'locale'    => (string) ($user['locale'] ?? 'en'),
            'theme'     => (string) ($user['theme'] ?? 'dark'),
            'group_ids' => array_map('intval', array_filter(explode(',', $user['group_ids'] ?? ''))),
        ];

        $this->session->forget('pending_verify');
        $this->session->login($userAccount);
        LoginLogger::log($this->db, '2fa_verified', (int)$user['id'], $ip, (string)$user['email']);

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

        // Check if user's entity has its own tenant — redirect there
        $tenantRedirect = $this->checkTenantRedirect($userId, (string) $user['email'], $ip);
        if ($tenantRedirect !== null) {
            $this->session->logout();
            $this->redirect($tenantRedirect);
        }

        $otherSessions = $this->findOtherSessions((int) $user['id'], $ip);
        if ($otherSessions !== []) {
            $this->session->set('_ib_session_conflicts', $otherSessions);
            $this->redirect('/login/sessions');
        }

        $this->redirect('/');
    }

    // -------------------------------------------------------------------------
    // Session conflict detection
    // -------------------------------------------------------------------------

    private function findOtherSessions(int $userId, string $currentIp): array
    {
        if (\Intrabase\Core\Application::isTrustedIp($currentIp)) {
            return [];
        }
        try {
            $currentSid = session_id();
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT session_id, ip_address, user_agent, last_activity, last_login
                   FROM ib_sessions
                  WHERE user_id = :uid AND session_id != :sid
                    AND last_activity > DATE_SUB(NOW(), INTERVAL 7 DAY)
               ORDER BY last_activity DESC'
            );
            $stmt->execute([':uid' => $userId, ':sid' => $currentSid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Exclude same-IP sessions and trusted-IP sessions (now recorded in
            // ib_sessions for chat presence) so they are never flagged as a
            // session conflict, preserving the pre-recording behaviour.
            return array_values(array_filter(
                $rows,
                fn($r) => $r['ip_address'] !== $currentIp
                    && !\Intrabase\Core\Application::isTrustedIp((string) $r['ip_address'])
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sendVerificationEmail(string $email, string $name, string $code): void
    {
        $displayName = $name !== '' ? $name : $email;
        $brandName   = $this->tenantConfig['client_name'] ?? 'Intrabase';
        $subject     = __('email_2fa_subject', $brandName, $code);
        $title       = __('email_2fa_title', $brandName);
        $hi          = __('email_2fa_hi', $displayName);
        $newIp       = __('email_2fa_new_ip');
        $codeLabel   = __('email_2fa_code');
        $valid       = __('email_2fa_valid');
        $ignore      = __('email_2fa_ignore');

        $htmlBody = <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px">
            <h2 style="color:#31b8b2">{$title}</h2>
            <p>{$hi},</p>
            <p>{$newIp}</p>
            <p>{$codeLabel}</p>
            <div style="text-align:center;margin:24px 0">
                <span style="font-size:36px;font-weight:bold;letter-spacing:8px;color:#31b8b2">{$code}</span>
            </div>
            <p style="color:#666">{$valid}</p>
            <p style="color:#666">{$ignore}</p>
        </div>
        HTML;

        try {
            $mailer = new \Intrabase\Support\Mailer($this->db);
            $mailer->send($email, $displayName, $subject, $htmlBody);
        } catch (\Throwable $e) {
            error_log('[VerifyController] sendVerificationEmail failed: ' . $e->getMessage());
        }
    }

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function flashBlock(): string
    {
        $messages = $this->session->flashes();
        if ($messages === []) return '';
        $html = '';
        foreach ($messages as $type => $text) {
            $alertMod = ($type === 'error') ? 'danger' : $type;
            $html .= '<div class="ib-alert ib-alert--' . Output::e($alertMod) . '">'
                . Output::e($text) . '</div>';
        }
        return $html;
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    // -------------------------------------------------------------------------
    // Cross-tenant redirect (after 2FA verification)
    // -------------------------------------------------------------------------

    private const TOKEN_EXPIRY_SECONDS = 60;

    /**
     * Check if user's entity has a dedicated tenant. If so, generate a
     * one-time login token and return the redirect URL.
     */
    private function checkTenantRedirect(int $userId, string $email, string $ip): ?string
    {
        // Cross-tenant login by organization name: off unless config('cross_tenant_login').
        // The target tenant logs the user in by e-mail alone, so it must be a
        // deliberate choice of the operator, not a default.
        if (empty(\Intrabase\Core\Application::getInstance()->getConfig()['cross_tenant_login'])) {
            return null;
        }
        $currentTenantId = (int) ($this->tenantConfig['id'] ?? 0);

        try {
            $app = \Intrabase\Core\Application::getInstance();
            $masterPdo = $app->getMasterConnection();

            // Find user's default entity name
            $entityRow = $this->db->fetchOne(
                'SELECT e.name FROM ib_user_entities ue
                 JOIN ib_obj_entities e ON e.id = ue.entity_id
                 WHERE ue.user_id = :uid
                 ORDER BY ue.is_default DESC, e.name ASC LIMIT 1',
                ['uid' => $userId]
            );
            if (!$entityRow) {
                return null;
            }
            $entityName = (string) $entityRow['name'];

            // Look up matching tenant in master DB
            $stmt = $masterPdo->prepare(
                "SELECT id, domain, subdomain FROM ib_tenants
                 WHERE client_name = :name AND status = 'active' LIMIT 1"
            );
            $stmt->execute([':name' => $entityName]);
            $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tenant || (int) $tenant['id'] === $currentTenantId) {
                return null;
            }

            // Build target URL and generate token
            $targetHost = !empty($tenant['subdomain'])
                ? $tenant['subdomain'] . '.' . $tenant['domain']
                : $tenant['domain'];

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_SECONDS);
            $ua      = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $masterPdo->prepare(
                'INSERT INTO ib_login_tokens (token, email, source_tenant_id, target_tenant_id, ip, user_agent, expires_at)
                 VALUES (:token, :email, :src, :tgt, :ip, :ua, :exp)'
            )->execute([
                ':token' => $token, ':email' => $email,
                ':src' => $currentTenantId, ':tgt' => (int) $tenant['id'],
                ':ip' => $ip, ':ua' => $ua, ':exp' => $expires,
            ]);

            return 'https://' . $targetHost . '/login?token=' . $token;

        } catch (\Throwable $e) {
            error_log('[VerifyController] checkTenantRedirect failed: ' . $e->getMessage());
            return null;
        }
    }
}
