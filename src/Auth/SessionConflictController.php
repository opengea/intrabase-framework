<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\AssetResolver;
use PDO;

/**
 * SessionConflictController
 *
 * Shown after login when other active sessions exist from different IPs.
 * Allows the user to close remote sessions or keep them.
 */
final class SessionConflictController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Session    $session,
        private readonly CSRF       $csrf,
    ) {}

    public function handle(): void
    {
        // Must be authenticated
        if (!$this->session->isAuthenticated()) {
            $this->redirect('/login');
        }

        // If no conflicts stored, go to dashboard
        $conflicts = $this->session->get('_ib_session_conflicts');
        if (!is_array($conflicts) || $conflicts === []) {
            $this->redirect('/');
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        match ($method) {
            'POST'  => $this->handlePost($conflicts),
            default => $this->showPage($conflicts),
        };
    }

    // -------------------------------------------------------------------------
    // POST — close selected sessions or keep all
    // -------------------------------------------------------------------------

    private function handlePost(array $conflicts): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            $this->showPage($conflicts, __('invalid_request'));
            return;
        }

        $action = Input::post('action');

        if ($action === 'close_all') {
            $this->closeAllOtherSessions();
        } elseif ($action === 'close_selected') {
            $selected = $_POST['close'] ?? [];
            if (is_array($selected)) {
                $this->closeSelectedSessions($selected);
            }
        }
        // action === 'keep' — do nothing, just proceed

        $this->session->forget('_ib_session_conflicts');
        $this->redirect('/');
    }

    private function closeAllOtherSessions(): void
    {
        $userId    = $this->session->userId();
        $currentSid = session_id();
        if ($userId === null || $currentSid === '') {
            return;
        }

        try {
            // Get session IDs to destroy
            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT session_id FROM ib_sessions WHERE user_id = :uid AND session_id != :sid'
            );
            $stmt->execute([':uid' => $userId, ':sid' => $currentSid]);
            $sids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Delete from ib_sessions
            $pdo->prepare(
                'DELETE FROM ib_sessions WHERE user_id = :uid AND session_id != :sid'
            )->execute([':uid' => $userId, ':sid' => $currentSid]);

            // Destroy the actual PHP session files
            $this->destroyPhpSessions($sids);
        } catch (\Throwable) {
            // Non-critical
        }
    }

    /**
     * @param string[] $sessionIds
     */
    private function closeSelectedSessions(array $sessionIds): void
    {
        $userId    = $this->session->userId();
        $currentSid = session_id();
        if ($userId === null || $sessionIds === []) {
            return;
        }

        try {
            $pdo = $this->db->pdo();
            // Only delete sessions belonging to this user and not the current one
            $placeholders = [];
            $params = [':uid' => $userId];
            foreach ($sessionIds as $i => $sid) {
                $sid = preg_replace('/[^a-zA-Z0-9,-]/', '', $sid);
                if ($sid !== '' && $sid !== $currentSid) {
                    $key = ':s' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $sid;
                }
            }
            if ($placeholders === []) {
                return;
            }

            $in = implode(',', $placeholders);

            // Only the ids that really are this user's sessions: the list comes
            // from the request, and destroying a PHP session file by id would
            // otherwise log out anybody whose session id is sent here.
            $stmt = $pdo->prepare(
                "SELECT session_id FROM ib_sessions WHERE user_id = :uid AND session_id IN ({$in})"
            );
            $stmt->execute($params);
            $owned = array_values(array_filter(
                array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []),
                fn(string $s): bool => $s !== $currentSid
            ));
            if ($owned === []) {
                return;
            }

            $pdo->prepare(
                "DELETE FROM ib_sessions WHERE user_id = :uid AND session_id IN ({$in})"
            )->execute($params);

            // Destroy the PHP session files (owned sessions only)
            $this->destroyPhpSessions($owned);
        } catch (\Throwable) {
            // Non-critical
        }
    }

    /**
     * Destroy PHP session files for given session IDs.
     *
     * @param string[] $sids
     */
    private function destroyPhpSessions(array $sids): void
    {
        $savePath = session_save_path() ?: sys_get_temp_dir();
        foreach ($sids as $sid) {
            $sid = preg_replace('/[^a-zA-Z0-9,-]/', '', $sid);
            $file = $savePath . '/sess_' . $sid;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // GET — show session conflict page
    // -------------------------------------------------------------------------

    private function showPage(array $conflicts, string $error = ''): void
    {
        $csrfField = $this->csrf->field();
        $error     = $error !== '' ? Output::e($error) : '';
        $locale    = $this->session->user()['locale'] ?? 'en';

        // Enrich conflicts with geolocation data
        $enriched = $this->enrichWithGeo($conflicts);

        $title    = __('session_conflict_title');
        $subtitle = __('session_conflict_subtitle');
        $tLastAct = __('session_last_activity');
        $tLastLog = __('session_last_login');
        $tIp      = __('session_ip');

        $intrabaseCssUrl = AssetResolver::url('/assets/css/intrabase.css');
        $intrabaseJsUrl  = AssetResolver::url('/assets/js/intrabase.js');
        $brandLogo = htmlspecialchars(\Intrabase\Support\Branding::loginLogo(), ENT_QUOTES);
        $brandLogoClass = htmlspecialchars(\Intrabase\Support\Branding::logoImgClass(), ENT_QUOTES);
        $loginMarkHtml = \Intrabase\Support\Branding::loginMarkHtml();
        $brandName = htmlspecialchars(\Intrabase\Support\Branding::name(), ENT_QUOTES);

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$locale}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <meta http-equiv="Content-Security-Policy"
                  content="default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'">
            <script>
            (function(){var t=localStorage.getItem('ib-theme');if(!t)t=window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.setAttribute('data-theme',t)})();
            </script>
            <title>Intrabase – {$title}</title>
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
            <style>
                /* Login box has a dark background always (#1d222e) regardless of data-theme,
                   so all session UI is styled for that dark container. */
                .ib-login-box { max-width: 540px; }
                .ib-session-title { text-align: center; margin: .5rem 0; color: #e2e8f0; }
                .ib-session-subtitle { text-align: center; color: #94a3b8; font-size: .9rem; margin-bottom: 1rem; }
                .ib-sessions-list { list-style: none; padding: 0; margin: 1rem 0; }
                .ib-session-card {
                    border: 1px solid rgba(255,255,255,.12);
                    border-radius: 8px; padding: 1rem;
                    margin-bottom: .75rem;
                    background: rgba(255,255,255,.04);
                    color: #e2e8f0;
                }
                .ib-session-card__header { display: flex; justify-content: space-between; align-items: center; }
                .ib-session-card__device { font-weight: 600; font-size: .95rem; color: #e2e8f0; }
                .ib-session-card__meta { color: #94a3b8; font-size: .85rem; margin-top: .35rem; }
                .ib-session-card__meta span { display: inline-block; margin-right: 1rem; }
                .ib-session-card__meta strong { color: #e2e8f0; }
                .ib-session-card__check { width: 18px; height: 18px; accent-color: #22d3ee; }
                .ib-session-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
                .ib-session-actions .ib-btn { box-sizing: border-box; }
                /* Match primary height to outline (outlines have 1px border; primary has border:none in login-box) */
                .ib-session-actions .ib-btn--primary { border: 1px solid transparent; }
                /* Outline variant styled for dark login-box (overrides both light and dark theme globals) */
                .ib-session-actions .ib-btn--outline,
                [data-theme="dark"] .ib-session-actions .ib-btn--outline {
                    background: transparent;
                    color: #e2e8f0;
                    border: 1px solid rgba(255,255,255,.3);
                    border-width: 1px;
                }
                .ib-session-actions .ib-btn--outline:hover,
                [data-theme="dark"] .ib-session-actions .ib-btn--outline:hover {
                    background: rgba(255,255,255,.08);
                    border-color: rgba(255,255,255,.5);
                    color: #fff;
                }
            </style>
        </head>
        <body class="ib-login-page">
            <div class="ib-login-box">
                <div class="ib-login-box__logo">
                    {$loginMarkHtml}
                </div>

                <h3 class="ib-session-title">{$title}</h3>
                <p class="ib-session-subtitle">{$subtitle}</p>

        HTML;

        if ($error !== '') {
            echo '<div class="ib-alert ib-alert--danger" role="alert">' . $error . '</div>';
        }

        echo '<form method="post" action="/login/sessions">';
        echo $csrfField;
        echo '<ul class="ib-sessions-list">';

        foreach ($enriched as $s) {
            $ip       = Output::e($s['ip_address']);
            $device   = Output::e($this->parseDevice($s['user_agent']));
            $location = Output::e($s['geo_location'] ?? '—');
            $lastAct  = Output::e($s['last_activity']);
            $lastLog  = Output::e($s['last_login'] ?? '—');
            $sid      = Output::attr($s['session_id']);

            echo <<<HTML
                <li class="ib-session-card">
                    <div class="ib-session-card__header">
                        <div class="ib-session-card__device">{$device}</div>
                        <input type="checkbox" name="close[]" value="{$sid}" class="ib-session-card__check" checked>
                    </div>
                    <div class="ib-session-card__meta">
                        <span>{$tIp}: <strong>{$ip}</strong></span>
                        <span>{$location}</span>
                    </div>
                    <div class="ib-session-card__meta">
                        <span>{$tLastAct}: {$lastAct}</span>
                        <span>{$tLastLog}: {$lastLog}</span>
                    </div>
                </li>
            HTML;
        }

        echo '</ul>';

        $closeAllLabel = __('session_close_all');
        $closeSelLabel = __('session_close_selected');
        $keepLabel     = __('session_keep');

        echo <<<HTML
                <div class="ib-session-actions">
                    <button type="submit" name="action" value="keep" class="ib-btn ib-btn--primary">
                        {$keepLabel}
                    </button>
                    <button type="submit" name="action" value="close_selected" class="ib-btn ib-btn--outline">
                        {$closeSelLabel}
                    </button>
                    <button type="submit" name="action" value="close_all" class="ib-btn ib-btn--outline">
                        {$closeAllLabel}
                    </button>
                </div>
            </form>
            </div>
            <footer class="ib-login-footer">
                <div class="ib-login-footer__info">
                    <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener">Built with Intrabase</a>
                </div>
            </footer>
            <script src="{$intrabaseJsUrl}"></script>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Enrich sessions with geolocation from ip-api.com (batch query).
     *
     * @param  array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    private function enrichWithGeo(array $sessions): array
    {
        $ips = array_unique(array_column($sessions, 'ip_address'));

        $geoMap = [];
        foreach ($ips as $ip) {
            $geoMap[$ip] = $this->geolocateIp($ip);
        }

        foreach ($sessions as &$s) {
            $s['geo_location'] = $geoMap[$s['ip_address']] ?? '—';
        }
        unset($s);

        return $sessions;
    }

    /**
     * Geolocate a single IP address using ip-api.com (free, no key).
     */
    private function geolocateIp(string $ip): string
    {
        // Skip private/reserved IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'Local / Privada';
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,regionName,country';
            $json = @file_get_contents($url, false, $ctx);
            if ($json === false) {
                return '—';
            }
            $data = json_decode($json, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return '—';
            }

            $parts = array_filter([
                $data['city'] ?? '',
                $data['regionName'] ?? '',
                $data['country'] ?? '',
            ]);
            return implode(', ', $parts) ?: '—';
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Parse a user agent string into a human-readable device description.
     */
    private function parseDevice(string $ua): string
    {
        if ($ua === '') {
            return __('session_unknown');
        }

        // OS detection
        $os = __('session_os_unknown');
        if (str_contains($ua, 'Windows'))       $os = 'Windows';
        elseif (str_contains($ua, 'Macintosh')) $os = 'macOS';
        elseif (str_contains($ua, 'Linux'))     $os = 'Linux';
        elseif (str_contains($ua, 'Android'))   $os = 'Android';
        elseif (str_contains($ua, 'iPhone'))    $os = 'iPhone';
        elseif (str_contains($ua, 'iPad'))      $os = 'iPad';

        // Browser detection
        $browser = __('session_browser_unknown');
        if (str_contains($ua, 'Edg/'))          $browser = 'Edge';
        elseif (str_contains($ua, 'OPR/'))      $browser = 'Opera';
        elseif (str_contains($ua, 'Chrome/'))   $browser = 'Chrome';
        elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome'))
                                                 $browser = 'Safari';
        elseif (str_contains($ua, 'Firefox/'))  $browser = 'Firefox';

        return "{$browser} / {$os}";
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
