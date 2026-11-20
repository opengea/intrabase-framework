<?php

declare(strict_types=1);

namespace Intrabase\Security;

/**
 * CSRF Protection
 *
 * Generates and validates per-session CSRF tokens using
 * cryptographically secure random bytes (random_bytes).
 *
 * Usage in a form:
 *   <?= \Intrabase\Security\CSRF::field() ?>
 *
 * Usage in middleware/controller:
 *   if (!\Intrabase\Security\CSRF::validate()) { abort(403); }
 *
 * @package Intrabase\Security
 */
class CSRF
{
    private const SESSION_KEY = '_ib_csrf_token';
    private const FIELD_NAME  = '_token';
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly Session $session) {}

    /**
     * Get (or generate) the current CSRF token.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (empty($token)) {
            $token = $this->generateToken();
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Validate the CSRF token from POST data or a custom header.
     *
     * Checks $_POST['_token'] and HTTP_X_CSRF_TOKEN header.
     * Uses hash_equals() to prevent timing attacks.
     */
    public function validateToken(): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        if (empty($expected)) {
            return false;
        }

        // Check POST field first
        $submitted = $_POST[self::FIELD_NAME]
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';

        // A token sent as an array (_token[]=...) is simply invalid, not a 500.
        if (!is_string($submitted) || !is_string($expected)) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Rotate the token (call after successful form submission).
     */
    public function regenerate(): void
    {
        $this->session->set(self::SESSION_KEY, $this->generateToken());
    }

    /**
     * Render a hidden HTML input field with the CSRF token.
     *
     * @return string Safe HTML string (token is hex-encoded)
     */
    public function field(): string
    {
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        $name  = htmlspecialchars(self::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        return "<input type=\"hidden\" name=\"{$name}\" value=\"{$token}\">";
    }

    /**
     * Render a <meta> tag for use by JavaScript (AJAX requests).
     *
     * @return string
     */
    public function meta(): string
    {
        $token = htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8');
        return "<meta name=\"csrf-token\" content=\"{$token}\">";
    }

    // -------------------------------------------------------------------------

    private function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }
}
