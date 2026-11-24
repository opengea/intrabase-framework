<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\AssetResolver;
use Intrabase\Support\LoginLogger;
use Intrabase\Support\Mailer;
use Intrabase\Support\RateLimiter;
use PDO;

/**
 * LoginController
 *
 * Handles user authentication: displays the login form (GET) and
 * validates credentials (POST) against ib_users, with brute-force
 * protection via RateLimiter (ib_rate_limits table).
 *
 * If credentials are valid and the user has a second factor enabled (TOTP
 * or e-mail code), the user goes to /login/verify unless this browser is a
 * trusted device (Support\TrustedDevice: passed the second factor in the
 * last 90 days). Neither the IP nor a first login skips it.
 */
final class LoginController
{
    private const MAX_ATTEMPTS      = 5;
    private const LOCKOUT_MINUTES   = 15;
    private const LOCKOUT_SECONDS   = self::LOCKOUT_MINUTES * 60;
    private const CODE_EXPIRY_MIN   = 15;

    public function __construct(
        private readonly Connection $db,
        private readonly Session    $session,
        private readonly CSRF       $csrf,
        private readonly array      $tenantConfig = [],
    ) {}

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    public function handle(): void
    {
        // Handle cross-tenant login token
        $token = Input::get('token');
        if ($token !== '' && !empty(\Intrabase\Core\Application::getInstance()->getConfig()['cross_tenant_login'])) {
            $this->handleTokenLogin($token);
            return;
        }

        if ($this->session->isAuthenticated()) {
            $this->redirect('/');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        match ($method) {
            'POST'  => $this->handlePost(),
            default => $this->showForm(),
        };
    }

    // -------------------------------------------------------------------------
    // GET – show login form
    // -------------------------------------------------------------------------

    private function showForm(string $error = ''): void
    {
        $csrfField = $this->csrf->field();
        $error     = Output::e($error);

        $locale     = \Intrabase\Lang\Lang::locale();
        $loginTitle = __('login_title');
        // One account for every service of the organization running this instance.
        $accountHint = Output::e(__('login_account_hint'));
        // Amb marca de client, l'adreça ja surt a la franja d'Intrabase de la capçalera (logo + host):
        // aquesta línia sobra (12/11/2026).
        $hintHtml = \Intrabase\Support\Branding::hasTenantMark() ? '' : '<p style="margin:-6px 0 18px;text-align:center;font-size:13px;color:#9ca3af">' . $accountHint . '</p>';
        $loginDesc  = Output::e(__('login_meta_description'));
        $emailLabel = __('email');
        $passLabel  = __('password');
        $loginBtn   = __('login_btn');
        $forgotLink = __('forgot_password');

        $intrabaseCssUrl = AssetResolver::url('/assets/css/intrabase.css');
        $intrabaseJsUrl  = AssetResolver::url('/assets/js/intrabase.js');
        $brandName  = Output::e($this->tenantConfig['client_name'] ?? 'Intrabase');
        // Evita repetir la marca quan el títol de login ja la conté (tenant per defecte)
        $pageTitle  = ($brandName === 'Intrabase') ? $loginTitle : "{$brandName} – {$loginTitle}";
        // Logo per tenant (ib_tenants.logo, ruta web); si no n'hi ha, el d'Intrabase.
        // La targeta del login és fosca i aplica filter:invert(1): el fitxer del
        // tenant ha de ser la marca en NEGRE, com el logo.svg per defecte.
        $brandLogo  = Output::e(\Intrabase\Support\Branding::loginLogo());
        $brandLogoClass = Output::e(\Intrabase\Support\Branding::logoImgClass());
        $loginMarkHtml = \Intrabase\Support\Branding::loginMarkHtml();
        $brandFavicon = Output::e(\Intrabase\Support\Branding::faviconLogo());
        $touchIcon = Output::e(\Intrabase\Support\Branding::appleTouchIcon() ?? '/apple-touch-icon.png');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <meta name="robots" content="noindex,nofollow">
            <meta name="description" content="{$loginDesc}">
            <meta http-equiv="Content-Security-Policy"
                  content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'">
            <script>
            (function(){var m=/IntrabaseMobile/i.test(navigator.userAgent);var t=m?'dark':(localStorage.getItem('ib-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'));document.documentElement.setAttribute('data-theme',t);var f=localStorage.getItem('ib-font-size');if(f==='small'||f==='normal'||f==='large')document.documentElement.setAttribute('data-font-size',f);
            // Boot splash, same as the app layout: with no address bar there is
            // no other sign that the page is loading. Class set here in the
            // head so the overlay paints with the first frame of <body>.
            var sa=window.navigator.standalone||window.matchMedia('(display-mode:standalone)').matches;
            if(sa||m)document.documentElement.classList.add('ib-booting')})();
            </script>
            <link rel="manifest" href="/manifest.webmanifest">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <meta name="mobile-web-app-capable" content="yes">
            <link rel="icon" href="/favicon.ico?v=3" sizes="32x32">
            <link rel="icon" type="image/svg+xml" href="{$brandFavicon}">
            <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png?v=3">
            <link rel="icon" type="image/png" sizes="192x192" href="/assets/img/logo-192.png?v=3">
            <link rel="apple-touch-icon" href="{$touchIcon}?v=3">
            <title>{$pageTitle}</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
            <style>
                #ib-boot{position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;
                    display:none;align-items:center;justify-content:center;flex-direction:column;
                    gap:22px;background:#0d1117;transition:opacity .25s ease}
                html[data-theme="light"] #ib-boot{background:#fff}
                html.ib-booting #ib-boot{display:flex}
                #ib-boot img{width:64px;height:64px}
                #ib-boot span{width:28px;height:28px;border:3px solid rgba(255,255,255,.15);
                    border-top-color:#388bfd;border-radius:50%;animation:ib-boot-spin .8s linear infinite}
                html[data-theme="light"] #ib-boot span{border-color:rgba(0,0,0,.12);border-top-color:#388bfd}
                @keyframes ib-boot-spin{to{transform:rotate(360deg)}}
                #ib-boot.ib-boot--gone{opacity:0;pointer-events:none}
            </style>
        </head>
        <body class="ib-login-page">
            <div id="ib-boot" aria-hidden="true"><img src="{$brandLogo}" alt=""><span></span></div>
            <script>
                (function(){
                    var el=document.getElementById('ib-boot');
                    if(!el) return;
                    // Native shell: drop its launch splash as soon as ours is on
                    // screen, so the two don't queue up.
                    try{
                        var cap=window.Capacitor;
                        if(cap&&cap.Plugins&&cap.Plugins.SplashScreen){
                            requestAnimationFrame(function(){ cap.Plugins.SplashScreen.hide(); });
                        }
                    }catch(e){}
                    var done=false;
                    function hide(){
                        if(done) return;
                        done=true;
                        el.classList.add('ib-boot--gone');
                        setTimeout(function(){
                            if(el.parentNode) el.parentNode.removeChild(el);
                            document.documentElement.classList.remove('ib-booting');
                        },300);
                    }
                    window.addEventListener('load',function(){ setTimeout(hide,150); });
                    setTimeout(hide,10000);
                })();
            </script>
            <div class="ib-login-box ib-login-box--animate">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>
                {$hintHtml}

                {$this->flashBlock()}

        HTML;

        if ($error !== '') {
            echo '<div class="ib-alert ib-alert--danger" role="alert">'
                . $error
                . '</div>';
        }

        echo <<<HTML
                <form class="ib-form" method="post" action="/login" novalidate>
                    {$csrfField}

                    <div class="ib-form__row">
                        <label class="ib-form__label" for="email">{$emailLabel}</label>
                        <div class="ib-input-icon">
                            <svg class="ib-input-icon__svg" viewBox="0 0 512 512" aria-hidden="true"><path fill="currentColor" d="M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4c12.1-9.1 19.2-23.3 19.2-38.4c0-26.5-21.5-48-48-48H48zM0 176V384c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V176L294.4 339.2c-22.8 17.1-54 17.1-76.8 0L0 176z"/></svg>
                            <input
                                class="ib-input"
                                type="email"
                                id="email"
                                name="email"
                                autocomplete="username"
                                required
                                autofocus>
                        </div>
                    </div>

                    <div class="ib-form__row">
                        <label class="ib-form__label" for="password">{$passLabel}</label>
                        <div class="ib-input-icon">
                            <svg class="ib-input-icon__svg" viewBox="0 0 448 512" aria-hidden="true"><path fill="currentColor" d="M144 144v48H304V144c0-44.2-35.8-80-80-80s-80 35.8-80 80zM80 192V144C80 64.5 144.5 0 224 0s144 64.5 144 144v48h16c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V256c0-35.3 28.7-64 64-64H80z"/></svg>
                            <input
                                class="ib-input"
                                type="password"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                required>
                        </div>
                    </div>

                    <div class="ib-form__row">
                        <button class="ib-btn ib-btn--primary ib-btn--block" type="submit">
                            {$loginBtn}
                        </button>
                    </div>
                </form>
                <div style="margin-top:1.25rem;text-align:center">
                    <a href="/forgot-password" class="ib-login-forgot-link">
                        {$forgotLink}
                    </a>
                </div>
            </div>
            <footer class="ib-login-footer">
                <div class="ib-login-footer__lang">
                    {$this->langSwitcher($locale)}
                </div>
                <div class="ib-login-footer__info">
                    <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener">Built with Intrabase</a>
                </div>
            </footer>
            <script src="{$intrabaseJsUrl}"></script>
            <script>
            (function(){
                if (window.innerWidth > 768) return;
                var form = document.querySelector('form');
                if (!form) return;
                form.addEventListener('submit', function(){
                    var el = document.documentElement;
                    if (el.requestFullscreen) el.requestFullscreen().catch(function(){});
                    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
                });
            })();
            </script>
        </body>
        </html>
        HTML;
    }

    /**
     * Build the language switcher HTML for the login footer.
     */
    private function langSwitcher(string $currentLocale): string
    {
        // A discreet native <select> showing the selected language by its own
        // name (03/09/2026; before: "CA · ES · EN · EU" links). Works without
        // JS too: the <noscript> button submits the same ?lang= parameter.
        $languages = ['ca' => 'Català', 'es' => 'Español', 'en' => 'English', 'eu' => 'Euskara'];
        if (!isset($languages[$currentLocale])) {
            $currentLocale = 'ca';
        }

        $options = '';
        foreach ($languages as $code => $name) {
            $options .= '<option value="' . $code . '"' . ($code === $currentLocale ? ' selected' : '') . '>'
                . Output::e($name) . '</option>';
        }

        return '<form method="get" action="/login" class="ib-login-lang">'
            . '<select name="lang" class="ib-login-lang__select" aria-label="' . Output::e(__('language')) . '"'
            . ' onchange="this.form.submit()">' . $options . '</select>'
            // The chevron is a sibling, not a background-image of the <select>:
            // Firefox 115 painted the select without it (03/09/2026).
            . '<svg class="ib-login-lang__chevron" aria-hidden="true" width="12" height="8" viewBox="0 0 12 8">'
            . '<path d="M1.5 1.5l4.5 4.5 4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '<noscript><button type="submit" class="ib-login-lang__go">OK</button></noscript>'
            . '</form>';
    }

    // -------------------------------------------------------------------------
    // POST – validate credentials
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        // 1. CSRF validation
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            $this->showForm(__('invalid_request'));
            return;
        }

        // Normalised: the rate-limit key must not change with case or spaces
        // while the account lookup ignores them (one budget per account).
        $email = mb_strtolower(trim((string) Input::post('email')));
        $pass  = trim($_POST['password'] ?? '');

        if ($email === '' || $pass === '') {
            $this->showForm(__('invalid_credentials'));
            return;
        }

        $ip      = $this->clientIp();
        $limiter = new RateLimiter($this->db);

        // 2. Brute-force check
        if ($limiter->isBlocked('login:ip:' . $ip, self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS)
            || $limiter->isBlocked('login:email:' . $email, self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS)) {
            $this->showForm(__('too_many_attempts', self::LOCKOUT_MINUTES));
            return;
        }

        // 3. Look up user
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.username, u.password_hash, u.active, u.name, u.email, u.locale, u.theme, u.font_size,
                    u.avatar, u.last_login_ip, u.last_login,
                    u.totp_enabled, u.email_2fa_enabled,
                    GROUP_CONCAT(ug.group_id) AS group_ids
               FROM ib_users u
               LEFT JOIN ib_user_groups ug ON ug.user_id = u.id
              WHERE u.email = :email
              GROUP BY u.id
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 4. Verify password
        $valid = $user !== false
            && (bool) $user['active']
            && password_verify($pass, (string) $user['password_hash']);

        if (!$valid) {
            $limiter->record('login:ip:' . $ip);
            $limiter->record('login:email:' . $email);
            LoginLogger::log($this->db, 'failed', null, $ip, $email);
            $this->showForm(__('invalid_credentials'));
            return;
        }

        // 5. Clear rate limits
        $limiter->clear('login:ip:' . $ip);
        $limiter->clear('login:email:' . $email);

        $userId = (int) $user['id'];

        // Hashes imported from other systems (crypt $6$/$5$/$1$) are upgraded
        // to bcrypt the first time the plain password is known.
        if (preg_match('/^\$(6|5|1)\$/', (string) $user['password_hash'])) {
            $pdo->prepare('UPDATE ib_users SET password_hash = :h, updated_at = NOW() WHERE id = :id')
                ->execute([':h' => password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), ':id' => $userId]);
        }
        \Intrabase\Domain\Events\UserLoggedInWithPassword::publish($userId, (string) $user['email'], $pass);

        // 6. Second factor (20/11/2026): required when the user enabled it (TOTP or
        //    e-mail code). The only way to skip it is a trusted device: this browser
        //    passed the second factor in the last TrustedDevice::DAYS days. No more
        //    skipping for "same IP as last time" (shared networks) or on the first
        //    login (exactly when a leaked password is tried).
        $totpEnabled     = (bool) ($user['totp_enabled'] ?? false);
        $email2faEnabled = (bool) ($user['email_2fa_enabled'] ?? false);
        $needs2fa        = $email2faEnabled || $totpEnabled;

        if (!$needs2fa || \Intrabase\Support\TrustedDevice::isTrusted($this->db, $userId)) {
            // Direct login — update last_login_ip and session
            $pdo->prepare('UPDATE ib_users SET last_login_ip = :ip, last_login = NOW() WHERE id = :id')
                ->execute([':ip' => $ip, ':id' => $userId]);

            $userAccount = [
                'id'        => $userId,
                'username'  => $user['username'] ?? '',
                // The stored address, not the typed one: the lookup ignores case,
                // so "Jane@…" (phone auto-capitalisation) logged in fine and
                // then pointed per-user storage at an empty "Jane/" folder.
                'email'     => (string) $user['email'],
                'name'      => $user['name'] ?? '',
                'avatar'    => $user['avatar'] ?? '',
                'locale'    => $user['locale'] ?? 'en',
                'theme'     => $user['theme'] ?? 'dark',
                'group_ids' => array_map('intval', array_filter(explode(',', $user['group_ids'] ?? ''))),
            ];
            $this->session->login($userAccount);
            LoginLogger::log($this->db, 'login', $userId, $ip, $email);

            // Load default entity for this user
            $defaultEntity = $this->db->fetchOne(
                'SELECT ue.entity_id, e.name, e.logo, e.color, e.type
                 FROM ib_user_entities ue
                 JOIN ib_obj_entities e ON e.id = ue.entity_id
                 WHERE ue.user_id = :uid AND e.status = "active"
                 ORDER BY ue.is_default DESC, e.name ASC
                 LIMIT 1',
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

            // Check if user's entity has its own tenant — redirect there
            $tenantRedirect = $this->checkTenantRedirect($userId, $email, $ip);
            if ($tenantRedirect !== null) {
                // For native apps (mobile/desktop): issue a persistent remember
                // token so the next launch skips the login form entirely
                $this->maybeIssueRememberToken($email, $tenantRedirect);
                // Destroy the local session — user will authenticate at target tenant
                $this->session->logout();
                $this->redirect($tenantRedirect);
            }

            // Check for other active sessions from a different IP
            $otherSessions = $this->findOtherSessions($userId, $ip);
            if ($otherSessions !== []) {
                $this->session->set('_ib_session_conflicts', $otherSessions);
                $this->redirect('/login/sessions');
            }

            $this->redirect('/');
        }

        // 7. New IP — trigger 2FA
        $this->triggerVerification($userId, $email, $user['name'] ?? '', $ip);
    }

    // -------------------------------------------------------------------------
    // 2FA trigger
    // -------------------------------------------------------------------------

    private function triggerVerification(
        int    $userId,
        string $email,
        string $name,
        string $ip
    ): void {
        // Generate random 6-digit code
        $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        $expires  = date('Y-m-d H:i:s', time() + self::CODE_EXPIRY_MIN * 60);

        // Store code (clean up old unused ones first)
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM ib_login_verification_codes
              WHERE user_id = :uid AND (used_at IS NOT NULL OR expires_at < NOW())'
        )->execute([':uid' => $userId]);

        $pdo->prepare(
            'INSERT INTO ib_login_verification_codes (user_id, code_hash, ip, expires_at)
             VALUES (:uid, :hash, :ip, :exp)'
        )->execute([
            ':uid'  => $userId,
            ':hash' => $codeHash,
            ':ip'   => $ip,
            ':exp'  => $expires,
        ]);

        // Send email
        $this->sendVerificationEmail($email, $name, $code);

        // Store pending verify in session (user_id only, no sensitive data)
        $this->session->set('pending_verify', ['user_id' => $userId]);

        // Log 2FA sent event
        LoginLogger::log($this->db, '2fa_sent', $userId, $ip);

        // Redirect to verify page
        $this->redirect('/login/verify');
    }

    /**
     * Send the verification code by email.
     */
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
            $mailer = new Mailer($this->db);
            $mailer->send($email, $displayName, $subject, $htmlBody);
        } catch (\Throwable $e) {
            error_log('[LoginController] sendVerificationEmail failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Trusted device persistence
    // -------------------------------------------------------------------------


    // -------------------------------------------------------------------------
    // Session conflict detection
    // -------------------------------------------------------------------------

    /**
     * Find other active sessions for the same user from a different IP.
     *
     * @return array<int, array<string, mixed>>
     */
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
                  WHERE user_id = :uid
                    AND session_id != :sid
                    AND last_activity > DATE_SUB(NOW(), INTERVAL 7 DAY)
               ORDER BY last_activity DESC'
            );
            $stmt->execute([':uid' => $userId, ':sid' => $currentSid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Only return sessions from a different IP. Trusted-IP sessions
            // (now recorded in ib_sessions so chat presence works) are never
            // treated as conflicts, preserving the pre-recording behaviour.
            $conflicts = [];
            foreach ($rows as $row) {
                if ($row['ip_address'] === $currentIp) {
                    continue;
                }
                if (\Intrabase\Core\Application::isTrustedIp((string) $row['ip_address'])) {
                    continue;
                }
                $conflicts[] = $row;
            }
            return $conflicts;
        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /** El servidor IMAP accepta aquest usuari i contrasenya? (LOGIN per SSL, 5 s com a màxim) */
    private static function imapAccepts(string $host, int $port, string $user, string $pass): bool
    {
        if ($host === '') return false;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $host !== '127.0.0.1', 'verify_peer_name' => $host !== '127.0.0.1']]);
        $s = @stream_socket_client('ssl://' . $host . ':' . ($port ?: 993), $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) return false;
        stream_set_timeout($s, 5);
        $q = static function (string $v): string { return '"' . addcslashes($v, "\"\\") . '"'; };
        fgets($s, 1024);   // salutació
        fwrite($s, 'a1 LOGIN ' . $q($user) . ' ' . $q($pass) . "\r\n");
        $ok = false;
        while (($l = fgets($s, 2048)) !== false) {
            if (strncmp($l, 'a1 ', 3) === 0) { $ok = strncmp($l, 'a1 OK', 5) === 0; break; }
        }
        @fwrite($s, "a2 LOGOUT\r\n");
        fclose($s);
        return $ok;
    }

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function flashBlock(): string
    {
        $messages = $this->session->flashes();
        if ($messages === []) {
            return '';
        }

        $html = '';
        foreach ($messages as $type => $text) {
            $safeType = in_array($type, ['success', 'error', 'warning', 'info'], true)
                ? $type
                : 'info';
            $alertMod = $safeType === 'error' ? 'danger' : $safeType;
            $html .= '<div class="ib-alert ib-alert--' . $alertMod . '">'
                . Output::e($text)
                . '</div>';
        }

        return $html;
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    // -------------------------------------------------------------------------
    // Cross-tenant redirect
    // -------------------------------------------------------------------------

    private const TOKEN_EXPIRY_SECONDS = 60;

    /**
     * After successful login, check if the user's entity has a dedicated tenant.
     * If so, generate a one-time login token and return the redirect URL.
     *
     * @return string|null  Redirect URL or null if no redirect needed
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
            $masterPdo = $this->getMasterPdo($app);
            if ($masterPdo === null) {
                return null;
            }

            // Find user's default entity name in current tenant DB
            $entityName = $this->getUserEntityName($userId);
            if ($entityName === null) {
                return null;
            }

            // Look up a tenant whose client_name matches the entity name
            $stmt = $masterPdo->prepare(
                "SELECT id, domain, subdomain, status
                   FROM ib_tenants
                  WHERE client_name = :name AND status = 'active'
                  LIMIT 1"
            );
            $stmt->execute([':name' => $entityName]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                return null;
            }

            $targetTenantId = (int) $tenant['id'];

            // Don't redirect if already on the target tenant
            if ($targetTenantId === $currentTenantId) {
                return null;
            }

            // Build target URL
            $targetHost = !empty($tenant['subdomain'])
                ? $tenant['subdomain'] . '.' . $tenant['domain']
                : $tenant['domain'];

            // Generate one-time login token (stored in master DB)
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_SECONDS);
            $ua      = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

            $masterPdo->prepare(
                'INSERT INTO ib_login_tokens (token, email, source_tenant_id, target_tenant_id, ip, user_agent, expires_at)
                 VALUES (:token, :email, :src, :tgt, :ip, :ua, :exp)'
            )->execute([
                ':token' => $token,
                ':email' => $email,
                ':src'   => $currentTenantId,
                ':tgt'   => $targetTenantId,
                ':ip'    => $ip,
                ':ua'    => $ua,
                ':exp'   => $expires,
            ]);

            return 'https://' . $targetHost . '/login?token=' . $token;

        } catch (\Throwable $e) {
            error_log('[LoginController] checkTenantRedirect failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * For native apps (mobile/desktop): issue a long-lived remember token
     * tied to the user's email + target tenant, stored in master DB.
     * The token travels back to the device as an HttpOnly cookie and is
     * used on subsequent launches to auto-generate a short-lived login
     * token for the target tenant.
     */
    private function maybeIssueRememberToken(string $email, string $tenantRedirect): void
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!preg_match('/IntrabaseMobile|IntrabaseDesktop/i', $ua)) {
            return;
        }
        try {
            $parsed = parse_url($tenantRedirect);
            if (!isset($parsed['host'])) {
                return;
            }
            $targetHost = (string) $parsed['host'];

            $app = \Intrabase\Core\Application::getInstance();
            $masterPdo = $this->getMasterPdo($app);
            if ($masterPdo === null) {
                return;
            }

            $stmt = $masterPdo->prepare(
                "SELECT id FROM ib_tenants
                  WHERE CONCAT_WS('.', NULLIF(subdomain, ''), domain) = :host
                  LIMIT 1"
            );
            $stmt->execute([':host' => $targetHost]);
            $targetId = $stmt->fetchColumn();
            if (!$targetId) {
                return;
            }

            $token = bin2hex(random_bytes(32));
            $uaTrim = mb_substr($ua, 0, 500);

            // Replace any previous remember token for this email+UA pair
            $masterPdo->prepare(
                'DELETE FROM ib_remember_tokens
                  WHERE email = :email AND user_agent = :ua'
            )->execute([':email' => $email, ':ua' => $uaTrim]);

            $masterPdo->prepare(
                'INSERT INTO ib_remember_tokens
                   (token, email, target_tenant_id, user_agent, expires_at)
                 VALUES
                   (:token, :email, :tgt, :ua, DATE_ADD(NOW(), INTERVAL 30 DAY))'
            )->execute([
                ':token' => $token,
                ':email' => $email,
                ':tgt'   => (int) $targetId,
                ':ua'    => $uaTrim,
            ]);

            setcookie('ib_remember', $token, [
                'expires'  => time() + 2592000,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } catch (\Throwable $e) {
            error_log('[LoginController] maybeIssueRememberToken failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the user's default entity name from current tenant DB.
     */
    private function getUserEntityName(int $userId): ?string
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT e.name
                   FROM ib_user_entities ue
                   JOIN ib_obj_entities e ON e.id = ue.entity_id
                  WHERE ue.user_id = :uid
                  ORDER BY ue.is_default DESC, e.name ASC
                  LIMIT 1',
                ['uid' => $userId]
            );
            return $row ? (string) $row['name'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Handle login via one-time cross-tenant token.
     */
    private function handleTokenLogin(string $token): void
    {
        // Validate token format (64 hex chars)
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            $this->showForm(__('invalid_request'));
            return;
        }

        $ip = $this->clientIp();

        try {
            $app = \Intrabase\Core\Application::getInstance();
            $masterPdo = $this->getMasterPdo($app);
            if ($masterPdo === null) {
                $this->showForm(__('invalid_request'));
                return;
            }

            $currentTenantId = (int) ($this->tenantConfig['id'] ?? 0);

            // Fetch and validate token
            $stmt = $masterPdo->prepare(
                'SELECT id, email, source_tenant_id, target_tenant_id, ip, expires_at, used_at
                   FROM ib_login_tokens
                  WHERE token = :token
                  LIMIT 1'
            );
            $stmt->execute([':token' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->showForm(__('invalid_request'));
                return;
            }

            // Validate: not used, not expired, correct tenant, same IP
            if ($row['used_at'] !== null
                || strtotime($row['expires_at']) < time()
                || (int) $row['target_tenant_id'] !== $currentTenantId
                || $row['ip'] !== $ip
            ) {
                // Mark as used to prevent replay
                $masterPdo->prepare('UPDATE ib_login_tokens SET used_at = NOW() WHERE id = :id')
                    ->execute([':id' => $row['id']]);
                $this->showForm(__('invalid_request'));
                return;
            }

            // Mark token as used
            $masterPdo->prepare('UPDATE ib_login_tokens SET used_at = NOW() WHERE id = :id')
                ->execute([':id' => $row['id']]);

            $email = $row['email'];

            // Look up user in THIS tenant's database
            $pdo  = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT u.id, u.username, u.active, u.name, u.email, u.locale, u.theme, u.font_size,
                        u.avatar, u.last_login_ip,
                        GROUP_CONCAT(ug.group_id) AS group_ids
                   FROM ib_users u
                   LEFT JOIN ib_user_groups ug ON ug.user_id = u.id
                  WHERE u.email = :email AND u.active = 1
                  GROUP BY u.id
                  LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->showForm(__('invalid_credentials'));
                return;
            }

            $userId = (int) $user['id'];

            // From ANOTHER tenant: the token only says that somebody signed in there
            // with an account that has the same address, not that they own this
            // account (another tenant may create a user with any address), and it
            // skipped this tenant's own second factor. So this tenant asks for it:
            // the TOTP if the account has one, otherwise a code to the account's
            // address, unless this browser is already a trusted device here.
            $sourceTenantId = (int) ($row['source_tenant_id'] ?? 0);
            if ($sourceTenantId !== $currentTenantId
                && !\Intrabase\Support\TrustedDevice::isTrusted($this->db, $userId)) {
                $this->triggerVerification($userId, (string) $user['email'], (string) ($user['name'] ?? ''), $ip);
            }

            // Update last login
            $pdo->prepare('UPDATE ib_users SET last_login_ip = :ip, last_login = NOW() WHERE id = :id')
                ->execute([':ip' => $ip, ':id' => $userId]);

            // Create session
            $userAccount = [
                'id'        => $userId,
                'username'  => $user['username'] ?? '',
                'email'     => $email,
                'name'      => $user['name'] ?? '',
                'avatar'    => $user['avatar'] ?? '',
                'locale'    => $user['locale'] ?? 'en',
                'theme'     => $user['theme'] ?? 'dark',
                'group_ids' => array_map('intval', array_filter(explode(',', $user['group_ids'] ?? ''))),
            ];
            $this->session->login($userAccount);
            LoginLogger::log($this->db, 'login', $userId, $ip, $email);

            // Load default entity
            $defaultEntity = $this->db->fetchOne(
                'SELECT ue.entity_id, e.name, e.logo, e.color, e.type
                 FROM ib_user_entities ue
                 JOIN ib_obj_entities e ON e.id = ue.entity_id
                 WHERE ue.user_id = :uid AND e.status = "active"
                 ORDER BY ue.is_default DESC, e.name ASC
                 LIMIT 1',
                ['uid' => $userId]
            );
            if ($defaultEntity) {
                $this->session->set('_ib_active_entity', [
                    'id'    => (int) $defaultEntity['entity_id'],
                    'name'  => $defaultEntity['name'],
                    'logo'  => $defaultEntity['logo'] ?? '',
                    'color' => $defaultEntity['color'] ?? '',
                    'type'  => $defaultEntity['type'] ?? '',
                ]);
            }

            $this->redirect('/');

        } catch (\Throwable $e) {
            error_log('[LoginController] handleTokenLogin failed: ' . $e->getMessage());
            $this->showForm(__('invalid_request'));
        }
    }

    /**
     * Get a PDO connection to the master database (intrabase).
     */
    private function getMasterPdo(\Intrabase\Core\Application $app): ?\PDO
    {
        try {
            return $app->getMasterConnection();
        } catch (\Throwable) {
            return null;
        }
    }
}
