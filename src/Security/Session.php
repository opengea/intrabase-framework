<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * Secure Session Manager
 *
 * Wraps PHP's native session handling with:
 * - Configurable session name (per tenant)
 * - Secure cookie defaults (HttpOnly, SameSite=Lax, Secure when HTTPS)
 * - Session fixation protection (regenerate on login)
 * - Idle timeout enforcement
 *
 * @package Intrabase\Security
 */
class Session
{
    /** How many seconds of inactivity before the session expires */
    private int $idleTimeout;

    /** Session name (e.g. 'IBSESSID' or tenant-specific) */
    private string $name;

    /** Cookie domain */
    private string $domain;

    /** @var array<string, mixed> Cookie options */
    private array $cookieOptions;

    private bool $started = false;

    /** Primera instància construïda (compat per a Session::getInstance()) */
    private static ?self $instance = null;

    public function __construct(
        string $name = 'IBSESSID',
        string $domain = '',
        array  $cookieOptions = [],
        int    $idleTimeout = 604800 // 7 days
    ) {
        $this->name        = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'IBSESSID';
        $this->domain      = $domain;
        $this->idleTimeout = $idleTimeout;

        $this->cookieOptions = array_merge([
            'lifetime' => 2592000, // 30 days — persistent cookie
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $cookieOptions);

        self::$instance ??= $this;
    }

    /**
     * Compat: molts mòduls generats criden Session::getInstance() en lloc de
     * resoldre-la pel container. Retorna la sessió de l'aplicació (la primera
     * construïda) o en crea una amb valors per defecte (CLI/tests).
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Compat: àlies de userId() usat per mòduls generats. */
    public function getUserId(): ?int
    {
        return $this->userId();
    }

    /**
     * Start the session.
     *
     * Idempotent — safe to call multiple times.
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        session_name($this->name);
        session_set_cookie_params($this->cookieOptions);

        // Ensure PHP doesn't garbage-collect sessions before our idle timeout
        ini_set('session.gc_maxlifetime', (string) max($this->idleTimeout, 604800));

        // Require cookies — session ID must come from a browser cookie
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();
        $this->started = true;

        $this->enforceIdleTimeout();
    }

    // -------------------------------------------------------------------------
    // Authentication helpers
    // -------------------------------------------------------------------------

    /**
     * Log in a user.
     *
     * Regenerates the session ID to prevent session fixation attacks.
     *
     * @param array<string, mixed> $userAccount User data to store in session
     */
    public function login(array $userAccount): void
    {
        session_regenerate_id(true);

        $_SESSION['_ib_user']      = $userAccount;
        if ($this->scope !== null) {
            $_SESSION['_ib_scope'] = $this->scope;
        }
        $_SESSION['_ib_auth_time'] = time();
        $_SESSION['_ib_last_act']  = time();
        // Moment de l'autenticació interactiva (SSO SAML: ForceAuthn demana una entrada recent).
        $_SESSION['_ib_auth_at']   = time();
    }

    /** Tenant this session belongs to (see bindScope()). */
    private ?string $scope = null;

    /**
     * Tie the session to a tenant.
     *
     * Every tenant on a host shares the same session storage; only the cookie
     * NAME differs, and a client chooses the names of the cookies it sends.
     * Without this, a session opened on one tenant was accepted on another by
     * renaming the cookie, as the user with the same numeric id there.
     *
     * - A session already bound to this tenant goes on.
     * - A session bound to ANOTHER tenant is left untouched (its owner keeps it)
     *   and this request gets a new, empty one.
     * - A session from before binding existed (no mark) is accepted only if
     *   $isSameUser confirms the user exists here (same id and e-mail); then it
     *   is bound. Otherwise it is treated as foreign.
     *
     * @param callable(array<string, mixed>): bool $isSameUser
     */
    public function bindScope(string $scope, callable $isSameUser): void
    {
        $this->scope = $scope;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $bound = $_SESSION['_ib_scope'] ?? null;
        if ($bound === $scope) {
            return;
        }
        // Anonymous session (login form, pending second factor...) with no mark
        // yet: it starts here, so it is this tenant's.
        if ($bound === null && !$this->isAuthenticated()) {
            if ($_SESSION !== []) {
                $_SESSION['_ib_scope'] = $scope;
            }
            return;
        }
        if ($bound === null) {
            try {
                if ($isSameUser((array) $_SESSION['_ib_user'])) {
                    $_SESSION['_ib_scope'] = $scope;
                    return;
                }
            } catch (\Throwable) {
                // Cannot confirm the user: treat the session as foreign.
            }
        }
        // Foreign session: drop it for this request without writing to it.
        session_abort();
        session_id(session_create_id());
        session_start();
        $_SESSION = ['_ib_scope' => $scope];
    }

    /**
     * Log out the current user and destroy the session.
     */
    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * Whether a user is currently authenticated.
     */
    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['_ib_user']['id']);
    }

    /**
     * Return the authenticated user's data, or null.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $_SESSION['_ib_user'] ?? null;
    }

    /**
     * Return the authenticated user's ID, or null.
     */
    public function userId(): ?int
    {
        $id = $_SESSION['_ib_user']['id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    // -------------------------------------------------------------------------
    // Generic get/set
    // -------------------------------------------------------------------------

    /**
     * Get a session value by key.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Remove a session key.
     */
    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Check whether a key exists in the session.
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Store a one-time flash message.
     *
     * Stored as [$type => $message] so that Layout::buildFlash() can iterate
     * with foreach ($flashes as $type => $text).
     *
     * @param string $type    e.g. 'success', 'error', 'info', 'warning'
     * @param string $message
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION['_ib_flash'][$type] = $message;
    }

    /**
     * Retrieve and clear all flash messages.
     *
     * @return array<string, string>  e.g. ['error' => 'Something went wrong']
     */
    public function flashes(): array
    {
        $flashes = $_SESSION['_ib_flash'] ?? [];
        unset($_SESSION['_ib_flash']);
        return $flashes;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Expire the session after a period of inactivity.
     */
    private function enforceIdleTimeout(): void
    {
        $lastActivity = $_SESSION['_ib_last_act'] ?? null;

        if ($lastActivity !== null) {
            if ((time() - $lastActivity) > $this->idleTimeout) {
                $this->logout();
                return;
            }
        }

        if ($this->isAuthenticated()) {
            $_SESSION['_ib_last_act'] = time();
        }
    }
}
