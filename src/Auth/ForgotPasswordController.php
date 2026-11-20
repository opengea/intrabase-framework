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
use Intrabase\Support\Mailer;
use Intrabase\Support\RateLimiter;
use PDO;

/**
 * ForgotPasswordController
 *
 * Handles the "Forgot Password" flow:
 *  GET  /forgot-password  → show email form
 *  POST /forgot-password  → validate email, generate secure token, send email
 *
 * Security:
 * - Rate limited: 3 requests per email per hour
 * - Token is 64-char hex (32 random bytes), stored as SHA-256 hash
 * - Always shows the same success message regardless of whether email exists
 *   (prevents user enumeration)
 * - Token expires in 1 hour
 */
final class ForgotPasswordController
{
    private const MAX_ATTEMPTS    = 3;
    private const LOCKOUT_SECONDS = 3600; // 1 hour
    private const TOKEN_EXPIRY    = 1800; // 30 minutes

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
            default => $this->showForm(),
        };
    }

    // -------------------------------------------------------------------------
    // GET — show form
    // -------------------------------------------------------------------------

    private function showForm(string $error = '', bool $sent = false): void
    {
        $csrfField = $this->csrf->field();
        $error     = Output::e($error);

        $content = $sent
            ? '<div class="ib-alert ib-alert--success">Si l\'adreça existeix al sistema, rebràs un correu amb les instruccions per restablir la contrasenya. Comprova també la carpeta de correu no desitjat.</div>'
            : '';

        $errorHtml = $error !== '' ? '<div class="ib-alert ib-alert--danger" role="alert">' . $error . '</div>' : '';

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
            <title>{$brandName} — Recuperar contrasenya</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
        </head>
        <body class="ib-login-page">
            <div class="ib-login-box">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>
                <h2 style="font-size:1.1rem;margin-bottom:1rem;color:var(--ib-text)">Recuperar contrasenya</h2>
                {$content}
                {$errorHtml}
        HTML;

        if (!$sent) {
            echo <<<HTML
                <p style="color:var(--ib-text-muted);margin-bottom:1.25rem;font-size:.9rem">Introdueix el teu correu electrònic i t'enviarem un enllaç per restablir la contrasenya.</p>
                <form class="ib-form" method="post" action="/forgot-password" novalidate>
                    {$csrfField}
                    <div class="ib-form__row">
                        <label class="ib-form__label" for="email">Correu electrònic</label>
                        <input class="ib-input" type="email" id="email" name="email"
                               autocomplete="email" required autofocus>
                    </div>
                    <div class="ib-form__row">
                        <button class="ib-btn ib-btn--primary" type="submit">Enviar enllaç</button>
                    </div>
                </form>
            HTML;
        }

        echo <<<HTML
                <div style="margin-top:1.5rem;text-align:center">
                    <a href="/login" style="color:var(--ib-primary);font-size:.9rem">← Tornar a l'accés</a>
                </div>
            </div>
            <script src="{$intrabaseJsUrl}"></script>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // POST — process form
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            $this->showForm('Sol·licitud invàlida. Torna-ho a intentar.');
            return;
        }

        // Normalised: the rate-limit key must not change with case or spaces
        // while the account lookup ignores them (one budget per account).
        $email = mb_strtolower(trim((string) Input::post('email')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->showForm('Introdueix una adreça de correu vàlida.');
            return;
        }

        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $limiter = new RateLimiter($this->db);
        $key     = 'forgot:email:' . $email;

        if ($limiter->isBlocked($key, self::MAX_ATTEMPTS, self::LOCKOUT_SECONDS)) {
            // Still show success (no enumeration)
            $this->showForm(sent: true);
            return;
        }

        $limiter->record($key);

        // Look up user — always show success to prevent enumeration
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, name, email FROM ib_users WHERE email = :email AND active = 1 LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user !== false) {
            $this->issueToken((int)$user['id'], (string)$user['email'], (string)$user['name'], $ip);
        }

        $this->showForm(sent: true);
    }

    // -------------------------------------------------------------------------
    // Token issuance
    // -------------------------------------------------------------------------

    private function issueToken(int $userId, string $email, string $name, string $ip): void
    {
        $pdo = $this->db->pdo();

        // Invalidate any old unused tokens for this user
        $pdo->prepare(
            'DELETE FROM ib_password_resets WHERE user_id = :uid'
        )->execute([':uid' => $userId]);

        // Generate cryptographically secure token
        $token     = bin2hex(random_bytes(32)); // 64 hex chars
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY);

        $pdo->prepare(
            'INSERT INTO ib_password_resets (user_id, token_hash, ip, expires_at)
             VALUES (:uid, :hash, :ip, :exp)'
        )->execute([
            ':uid'  => $userId,
            ':hash' => $tokenHash,
            ':ip'   => $ip,
            ':exp'  => $expiresAt,
        ]);

        $this->sendResetEmail($email, $name, $token);
    }

    // -------------------------------------------------------------------------
    // Email
    // -------------------------------------------------------------------------

    private function sendResetEmail(string $email, string $name, string $token): void
    {
        $displayName = $name !== '' ? $name : $email;
        $resetUrl    = $this->buildResetUrl($token);
        $brandName   = Branding::name();
        $subject     = $brandName . ' — Restablir contrasenya';
        $brandHtml   = $this->emailBrandHeader($brandName);

        $htmlBody = <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px">
            {$brandHtml}
            <p>Hola, <strong>{$displayName}</strong>,</p>
            <p>Has rebut aquest correu perquè s'ha sol·licitat un restabliment de contrasenya per al teu compte.</p>
            <p>Fes clic al botó següent per crear una nova contrasenya:</p>
            <div style="text-align:center;margin:28px 0">
                <a href="{$resetUrl}"
                   style="background:#31b8b2;color:#fff;padding:12px 28px;border-radius:6px;
                          text-decoration:none;font-weight:bold;display:inline-block">
                    Restablir contrasenya
                </a>
            </div>
            <p style="color:#666;font-size:.9rem">
                O copia i enganxa aquest enllaç al teu navegador:<br>
                <a href="{$resetUrl}" style="color:#31b8b2">{$resetUrl}</a>
            </p>
            <p style="color:#666;font-size:.9rem">
                Aquest enllaç és vàlid durant <strong>30 minuts</strong> i és d'un sol ús.<br>
                Si no has sol·licitat cap canvi, ignora aquest missatge.
            </p>
        </div>
        HTML;

        try {
            $mailer = new Mailer($this->db);
            $mailer->send($email, $displayName, $subject, $htmlBody);
        } catch (\Throwable $e) {
            error_log('[ForgotPasswordController] sendResetEmail failed: ' . $e->getMessage());
        }
    }

    private function buildResetUrl(string $token): string
    {
        return $this->baseUrl() . '/reset-password?token=' . urlencode($token);
    }

    /** Canonical base URL, never the Host header (see Application::publicHost()). */
    private function baseUrl(): string
    {
        return \Intrabase\Core\Application::getInstance()->publicBaseUrl();
    }

    /**
     * Brand header of the reset email: the tenant's email logo (PNG, sized
     * with HTML width/height attributes — email clients ignore CSS sizing and
     * Gmail blocks SVG) followed by the brand name as heading; name alone when
     * the tenant has no email logo.
     */
    private function emailBrandHeader(string $brandName): string
    {
        $safeBrand = Output::e($brandName);
        $logoImg   = '';

        $logoPath = Branding::emailLogo();
        if ($logoPath !== null) {
            $file = __DIR__ . '/../../public' . (parse_url($logoPath, PHP_URL_PATH) ?: $logoPath);
            $size = @getimagesize($file);
            $h    = 28;
            $w    = ($size && $size[1] > 0) ? (int)round($h * $size[0] / $size[1]) : 0;
            $attr = $w > 0 ? ' width="' . $w . '" height="' . $h . '"' : '';
            $logoImg = '<img src="' . Output::e($this->baseUrl() . $logoPath) . '"'
                     . ' alt="' . $safeBrand . '"' . $attr
                     . ' style="display:block;margin-bottom:12px">' . "\n            ";
        }

        return $logoImg
            . '<h2 style="color:#31b8b2">' . $safeBrand . ' — Restablir contrasenya</h2>';
    }
}
