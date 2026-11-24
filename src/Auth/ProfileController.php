<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Security\TOTP;
use Intrabase\Support\TrustedDevice;
use Intrabase\View\Layout;

/**
 * /profile — the signed-in user's own account:
 *   - name and language (the e-mail is shown, not edited here);
 *   - password change (asks for the current one; ends other sessions and
 *     forgets trusted devices);
 *   - two-step verification with an authenticator app (TOTP): enrol with a
 *     QR code drawn in the browser, disable with the password — except for
 *     members of config('require_totp_groups'), who must keep it;
 *   - trusted devices: list and remove.
 */
final class ProfileController
{
    private const MIN_PASSWORD = 12;
    /** Seconds a pending TOTP enrolment (the secret shown in the QR) stays valid. */
    private const SETUP_TTL = 900;
    private const LOCALES = ['ca' => 'Català', 'es' => 'Español', 'en' => 'English', 'eu' => 'Euskara'];

    public function __construct(
        private Connection $db,
        private Session $session,
        private CSRF $csrf,
        private array $config = [],
    ) {
    }

    public function handle(): void
    {
        $userId = (int) ($this->session->userId() ?? 0);
        if ($userId <= 0) {
            header('Location: /login', true, 302);
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->csrf->validateToken()) {
                $this->session->flash('error', __('csrf_invalid'));
                $this->back();
            }
            match (Input::post('action')) {
                'info'         => $this->saveInfo($userId),
                'password'     => $this->changePassword($userId),
                'totp_enable'  => $this->enableTotp($userId),
                'totp_disable' => $this->disableTotp($userId),
                'device_forget'=> $this->forgetDevice($userId),
                default        => $this->back(),
            };
        }

        $this->render($userId);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    private function saveInfo(int $userId): never
    {
        $name   = trim(mb_substr(Input::post('name'), 0, 255));
        $locale = Input::post('locale');
        if (!isset(self::LOCALES[$locale])) {
            $locale = 'en';
        }
        $this->db->execute(
            'UPDATE ib_users SET name = :name, locale = :locale WHERE id = :id',
            ['name' => $name, 'locale' => $locale, 'id' => $userId]
        );
        $user = $this->session->user() ?? [];
        $user['name']   = $name;
        $user['locale'] = $locale;
        $this->session->set('_ib_user', $user);
        $this->session->flash('success', __('profile_saved'));
        $this->back();
    }

    private function changePassword(int $userId): never
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (!$this->checkPassword($userId, $current)) {
            $this->fail(__('password_wrong_current'), 'password');
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            $this->fail(__('password_too_short', self::MIN_PASSWORD), 'password');
        }
        if (!hash_equals($new, $confirm)) {
            $this->fail(__('password_mismatch'), 'password');
        }

        $this->db->execute(
            'UPDATE ib_users SET password_hash = :h WHERE id = :id',
            ['h' => password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $userId]
        );

        // A new password ends every other way in: other sessions and trusted devices.
        $this->db->execute('DELETE FROM ib_user_trusted_devices WHERE user_id = :id', ['id' => $userId]);
        $this->db->execute(
            'DELETE FROM ib_sessions WHERE user_id = :id AND session_id <> :sid',
            ['id' => $userId, 'sid' => session_id()]
        );

        $email = (string) (($this->session->user() ?? [])['email'] ?? '');
        \Intrabase\Domain\Events\UserPasswordChanged::publish($userId, $email, $new);

        $this->session->flash('success', __('password_changed'));
        $this->back('password');
    }

    private function enableTotp(int $userId): never
    {
        // Enrolling replaces nothing: if TOTP is already on, it has to be turned
        // off first (which asks for the password).
        $on = $this->db->fetchOne('SELECT totp_enabled FROM ib_users WHERE id = :id', ['id' => $userId]);
        if ((int) ($on['totp_enabled'] ?? 0) === 1) {
            $this->back('security');
        }
        // A stolen session must not be able to enrol the thief's phone.
        if (!$this->checkPassword($userId, (string) ($_POST['current_password'] ?? ''))) {
            $this->fail(__('password_wrong_current'), 'security');
        }
        $secret = $this->setupSecret($userId);
        $code   = preg_replace('/\D/', '', Input::post('totp_code'));
        if ($secret === null || strlen($code) !== 6 || !TOTP::verify($secret, $code)) {
            $this->fail(__('totp_wrong_code'), 'security');
        }
        $this->db->execute(
            'UPDATE ib_users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id',
            ['s' => \Intrabase\Security\TotpSecretStore::seal($secret), 'id' => $userId]
        );
        $this->session->forget('_ib_totp_setup');
        $this->session->forget('_ib_totp_ok');
        $this->session->flash('success', __('totp_activated'));
        $this->back('security');
    }

    private function disableTotp(int $userId): never
    {
        if ($this->totpRequired($userId)) {
            $this->fail(__('totp_required_cannot_disable'), 'security');
        }
        if (!$this->checkPassword($userId, (string) ($_POST['current_password'] ?? ''))) {
            $this->fail(__('password_wrong_current'), 'security');
        }
        $this->db->execute(
            'UPDATE ib_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id',
            ['id' => $userId]
        );
        $this->db->execute('DELETE FROM ib_user_trusted_devices WHERE user_id = :id', ['id' => $userId]);
        $this->session->flash('success', __('totp_disabled'));
        $this->back('security');
    }

    private function forgetDevice(int $userId): never
    {
        $this->db->execute(
            'DELETE FROM ib_user_trusted_devices WHERE id = :d AND user_id = :u',
            ['d' => Input::postInt('device_id'), 'u' => $userId]
        );
        $this->session->flash('success', __('device_forgotten'));
        $this->back('devices');
    }

    // -------------------------------------------------------------------------
    // Page
    // -------------------------------------------------------------------------

    private function render(int $userId): void
    {
        $user = $this->db->fetchOne(
            'SELECT id, email, name, locale, totp_enabled FROM ib_users WHERE id = :id',
            ['id' => $userId]
        ) ?? [];
        $e     = static fn($v): string => Output::e((string) $v);
        $field = $this->csrf->field();

        $locales = '';
        foreach (self::LOCALES as $code => $label) {
            $sel = ($user['locale'] ?? '') === $code ? ' selected' : '';
            $locales .= '<option value="' . $code . '"' . $sel . '>' . $e($label) . '</option>';
        }

        // Two-step verification
        $required = $this->totpRequired($userId);
        if (!empty($user['totp_enabled'])) {
            $totp = '<p><span class="ib-badge ib-badge--success">' . $e(__('totp_on')) . '</span></p>'
                  . '<p>' . $e(__('totp_on_help')) . '</p>';
            if ($required) {
                $totp .= '<p class="ib-card__subtitle">' . $e(__('totp_required_cannot_disable')) . '</p>';
            } else {
                $totp .= '<form method="post" action="/profile" class="ib-form">' . $field
                       . '<input type="hidden" name="action" value="totp_disable">'
                       . '<div class="ib-form__row"><label class="ib-form__label" for="pf-dis-pass">' . $e(__('password_current')) . '</label>'
                       . '<input class="ib-input" type="password" id="pf-dis-pass" name="current_password" autocomplete="current-password" required></div>'
                       . '<button class="ib-btn ib-btn--danger" type="submit">' . $e(__('totp_disable')) . '</button></form>';
            }
        } else {
            $secret = $this->setupSecret($userId);
            if ($secret === null) {
                $secret = TOTP::generateSecret();
                $this->session->set('_ib_totp_setup', ['user' => $userId, 'secret' => $secret, 'until' => time() + self::SETUP_TTL]);
            }
            $issuer = (string) ($this->config['name'] ?? 'Intrabase');
            $uri    = TOTP::getUri($secret, (string) ($user['email'] ?? ''), $issuer);
            $totp = ($required ? '<div class="ib-alert ib-alert--warning">' . $e(__('totp_required_admin')) . '</div>' : '')
                  . '<p>' . $e(__('totp_off_help')) . '</p>'
                  . '<ol style="list-style:decimal;padding-left:1.4em;margin:8px 0"><li>' . $e(__('totp_step_install')) . '</li><li>' . $e(__('totp_step_scan')) . '</li><li>' . $e(__('totp_step_code')) . '</li></ol>'
                  . '<div style="margin:12px 0">' . TOTP::getQrSvg($uri, 200) . '</div>'
                  . '<p class="ib-card__subtitle">' . $e(__('totp_manual_key')) . ' <code>' . $e(trim(chunk_split($secret, 4, ' '))) . '</code></p>'
                  . '<form method="post" action="/profile" class="ib-form">' . $field
                  . '<input type="hidden" name="action" value="totp_enable">'
                  . '<div class="ib-form__row"><label class="ib-form__label" for="pf-en-pass">' . $e(__('password_current')) . '</label>'
                  . '<input class="ib-input" type="password" id="pf-en-pass" name="current_password" autocomplete="current-password" required></div>'
                  . '<div class="ib-form__row"><label class="ib-form__label" for="pf-code">' . $e(__('totp_code')) . '</label>'
                  . '<input class="ib-input" type="text" id="pf-code" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div>'
                  . '<button class="ib-btn ib-btn--primary" type="submit">' . $e(__('totp_enable')) . '</button></form>';
        }

        // Trusted devices
        $rows = $this->db->fetchAll(
            'SELECT id, ip, user_agent, created_at, expires_at FROM ib_user_trusted_devices
              WHERE user_id = :id AND expires_at > NOW() ORDER BY created_at DESC',
            ['id' => $userId]
        );
        if ($rows === []) {
            $devices = '<p>' . $e(__('devices_none')) . '</p>';
        } else {
            $devices = '<table class="ib-table"><thead><tr><th>' . $e(__('device')) . '</th><th>IP</th><th>'
                     . $e(__('device_since')) . '</th><th>' . $e(__('device_until')) . '</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $devices .= '<tr><td>' . $e(mb_strimwidth((string) $r['user_agent'], 0, 60, '…')) . '</td><td>' . $e($r['ip'])
                          . '</td><td>' . $e(substr((string) $r['created_at'], 0, 10)) . '</td><td>' . $e(substr((string) $r['expires_at'], 0, 10))
                          . '</td><td><form method="post" action="/profile" style="margin:0">' . $field
                          . '<input type="hidden" name="action" value="device_forget"><input type="hidden" name="device_id" value="' . (int) $r['id'] . '">'
                          . '<button class="ib-btn ib-btn--sm ib-btn--ghost" type="submit">' . $e(__('device_forget')) . '</button></form></td></tr>';
            }
            $devices .= '</tbody></table>';
        }

        $card = static fn(string $id, string $title, string $body): string =>
            '<section class="ib-card" id="' . $id . '" style="margin-bottom:20px"><div class="ib-card__header"><h2 style="margin:0;font-size:1.1rem">'
            . $title . '</h2></div><div class="ib-card__body">' . $body . '</div></section>';

        $content = '<div style="max-width:760px">'
            . '<h1 style="font-size:1.4rem;margin:0 0 20px">' . $e(__('my_profile')) . '</h1>'
            . $card('info', $e(__('profile_info')),
                '<form method="post" action="/profile" class="ib-form">' . $field . '<input type="hidden" name="action" value="info">'
                . '<div class="ib-form__row"><label class="ib-form__label">' . $e(__('email')) . '</label><div>' . $e($user['email'] ?? '') . '</div></div>'
                . '<div class="ib-form__row"><label class="ib-form__label" for="pf-name">' . $e(__('name')) . '</label>'
                . '<input class="ib-input" id="pf-name" name="name" value="' . $e($user['name'] ?? '') . '" maxlength="255"></div>'
                . '<div class="ib-form__row"><label class="ib-form__label" for="pf-locale">' . $e(__('language')) . '</label>'
                . '<select class="ib-input" id="pf-locale" name="locale">' . $locales . '</select></div>'
                . '<button class="ib-btn ib-btn--primary" type="submit">' . $e(__('save')) . '</button></form>')
            . $card('password', $e(__('password_title')),
                '<form method="post" action="/profile" class="ib-form">' . $field . '<input type="hidden" name="action" value="password">'
                . '<div class="ib-form__row"><label class="ib-form__label" for="pf-cur">' . $e(__('password_current')) . '</label>'
                . '<input class="ib-input" type="password" id="pf-cur" name="current_password" autocomplete="current-password" required></div>'
                . '<div class="ib-form__row"><label class="ib-form__label" for="pf-new">' . $e(__('password_new')) . '</label>'
                . '<input class="ib-input" type="password" id="pf-new" name="new_password" minlength="' . self::MIN_PASSWORD . '" autocomplete="new-password" required></div>'
                . '<div class="ib-form__row"><label class="ib-form__label" for="pf-conf">' . $e(__('password_confirm')) . '</label>'
                . '<input class="ib-input" type="password" id="pf-conf" name="confirm_password" minlength="' . self::MIN_PASSWORD . '" autocomplete="new-password" required></div>'
                . '<p class="ib-card__subtitle">' . $e(__('password_change_help')) . '</p>'
                . '<button class="ib-btn ib-btn--primary" type="submit">' . $e(__('password_change')) . '</button></form>')
            . $card('security', $e(__('totp_title')), $totp)
            . $card('devices', $e(__('devices_title')), '<p class="ib-card__subtitle">' . $e(__('devices_help')) . '</p>' . $devices)
            . '</div>';

        (new Layout($this->session, $this->csrf, $this->db))->render($content, ['title' => __('my_profile')]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Members of config('require_totp_groups') (default: admin) must keep TOTP. */
    public function totpRequired(int $userId): bool
    {
        $groups = array_values(array_filter(array_map('strval', (array) ($this->config['require_totp_groups'] ?? ['admin']))));
        if ($groups === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($groups), '?'));
        return $this->db->fetchOne(
            "SELECT 1 FROM ib_user_groups ug JOIN ib_groups g ON g.id = ug.group_id
              WHERE ug.user_id = ? AND g.name IN ({$ph}) LIMIT 1",
            array_merge([$userId], $groups)
        ) !== null;
    }

    /** The pending enrolment secret, only if it belongs to this user and has not expired. */
    private function setupSecret(int $userId): ?string
    {
        $setup = $this->session->get('_ib_totp_setup');
        if (!is_array($setup) || (int) ($setup['user'] ?? 0) !== $userId || (int) ($setup['until'] ?? 0) < time()
            || !is_string($setup['secret'] ?? null) || $setup['secret'] === '') {
            $this->session->forget('_ib_totp_setup');
            return null;
        }
        return $setup['secret'];
    }

    private function checkPassword(int $userId, string $password): bool
    {
        $row = $this->db->fetchOne('SELECT password_hash FROM ib_users WHERE id = :id', ['id' => $userId]);
        return $row !== null && $password !== '' && password_verify($password, (string) $row['password_hash']);
    }

    private function fail(string $message, string $anchor): never
    {
        $this->session->flash('error', $message);
        $this->back($anchor);
    }

    private function back(string $anchor = ''): never
    {
        header('Location: /profile' . ($anchor !== '' ? '#' . $anchor : ''), true, 303);
        exit;
    }
}
