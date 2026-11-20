<?php

declare(strict_types=1);

namespace Intrabase\Api;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use Intrabase\Support\Logger;
use Intrabase\Support\RateLimiter;

/**
 * Base API Controller
 *
 * Handles:
 * - API token authentication via Bearer header
 * - Token scope validation
 * - Rate limiting per token
 * - CORS headers
 * - Common request lifecycle
 *
 * Token scopes:
 *   read   — GET requests
 *   write  — POST, PUT, PATCH
 *   delete — DELETE
 *   admin  — all operations
 *
 * @package Intrabase\Api
 */
abstract class ApiController
{
    protected Connection $db;
    protected Logger $logger;
    protected Request $request;

    /** @var array<string, mixed> */
    protected array $tokenData = [];

    public function __construct(Connection $db, Logger $logger)
    {
        $this->db      = $db;
        $this->logger  = $logger;
        $this->request = new Request();
    }

    /**
     * Authenticate the request.
     *
     * Two paths (ADR 001 convergence target):
     *   1. `Authorization: Bearer <token>` — for external integrators.
     *   2. A valid dashboard session cookie + CSRF header — for the
     *      SPA calling /api/v1/* as its own backend.
     *
     * On success sets $this->tokenData to either a real ib_api_tokens
     * row (path 1) or a synthetic record representing the session user
     * (path 2). Downstream controllers see both the same way, so scope
     * / ACL logic does not branch.
     *
     * On failure calls Response::unauthorized() / forbidden() and exits.
     */
    protected function authenticate(): void
    {
        $raw = $this->request->bearerToken();

        if ($raw !== null) {
            $this->authenticateViaBearer($raw);
            return;
        }

        $this->authenticateViaSession();
    }

    /**
     * Bearer-token authentication — the "public integrator" path.
     */
    private function authenticateViaBearer(string $raw): void
    {
        // Hash before DB lookup (tokens stored as SHA-256)
        $hashed = hash('sha256', $raw);

        $token = $this->db->fetchOne(
            "SELECT t.*, ten.db_name AS tenant_db
               FROM ib_api_tokens t
               LEFT JOIN ib_tenants ten ON ten.id = t.tenant_id
              WHERE t.token_hash = ?
                AND t.status = 'active'
                AND (t.expires_at IS NULL OR t.expires_at > NOW())
              LIMIT 1",
            [$hashed]
        );

        if (!$token) {
            $this->logger->warning('API auth failed: invalid or expired token', [
                'ip' => $this->request->ip(),
            ]);
            Response::unauthorized('Invalid or expired API token.');
        }

        // WebDAV app passwords (scope `dav`) are minted only for the /dav/
        // door (spec §6) and must never open the REST API, even alongside
        // other scopes.
        // Same for the Claude connector's tokens (scope `mcp`, ADR 003).
        if (TokenScopes::isDavOnly((string) $token['scopes']) || TokenScopes::isMcp((string) $token['scopes'])) {
            $this->logger->warning('API auth refused: WebDAV app password or connector token', [
                'token_id' => $token['id'],
                'ip'       => $this->request->ip(),
            ]);
            Response::unauthorized('Invalid or expired API token.');
        }

        // Check rate limit per token
        $limiter = new RateLimiter($this->db);
        $key     = 'api_token_' . $token['id'];
        $limit   = (int)($token['rate_limit'] ?? 100);
        $window  = 60; // 1 minute window

        if ($limiter->isBlocked($key, $limit, $window)) {
            $this->logger->warning('API rate limit exceeded', [
                'token_id' => $token['id'],
                'ip'       => $this->request->ip(),
            ]);
            Response::tooManyRequests($window);
        }

        $limiter->record($key);

        // Update last_used_at + origin metadata (best effort)
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        try {
            $this->db->execute(
                "UPDATE ib_api_tokens
                    SET last_used_at    = NOW(),
                        last_used_ip    = ?,
                        last_used_agent = ?
                  WHERE id = ?",
                [$this->request->ip(), $userAgent, $token['id']]
            );
        } catch (\Throwable) {
            // Non-critical, don't fail the request
        }

        $token['auth_method'] = 'bearer';
        $this->tokenData = $token;
    }

    /**
     * Session-cookie authentication — the "dashboard SPA" path.
     *
     * For non-GET methods, requires a valid CSRF token in the
     * `X-CSRF-TOKEN` header (or `_token` POST field, same as the
     * rest of the dashboard).
     *
     * Synthesises a $tokenData record that looks like a user token
     * with full scopes — real restriction happens at the ACL layer,
     * same as for a normal browser request.
     */
    private function authenticateViaSession(): void
    {
        $session = new Session();
        $user    = $session->user();

        if (!is_array($user) || empty($user['id'])) {
            // No Bearer token was sent, so this is a browser call whose session
            // died: mark it like the rest of the app does, so the client-side
            // handler logs the user out instead of showing a generic error.
            header('X-Ib-Session: expired');
            Response::unauthorized(
                'Authentication required: send Authorization: Bearer <token> '
                . 'or call from a logged-in dashboard session.'
            );
        }

        // CSRF only for state-changing methods. GET is safe-by-design.
        $method = $this->request->method();
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $csrf = new CSRF($session);
            if (!$csrf->validateToken()) {
                Response::forbidden(
                    'Invalid or missing CSRF token. Session-authenticated '
                    . 'writes must include X-CSRF-TOKEN header.'
                );
            }
        }

        // Resolve the active entity from session (null = personal context).
        $active   = $session->get('_ib_active_entity');
        $entityId = is_array($active) && !empty($active['id']) ? (int) $active['id'] : null;

        // Synthetic token record — shape matches ib_api_tokens + auth_method.
        // Scopes are wide open; real permission is enforced by the user's
        // ACL (same as when the user navigates the dashboard via HTML).
        $this->tokenData = [
            'id'              => 0,
            'tenant_id'       => null,
            'entity_id'       => $entityId,
            'token_type'      => 'user',
            'name'            => 'session:' . ($user['email'] ?? 'user'),
            'token_hash'      => '',
            'scopes'          => 'read,write,delete,admin',
            'modules'         => null,
            'allowed_origins' => '*',
            'rate_limit'      => 0,
            'status'          => 'active',
            'expires_at'      => null,
            'last_used_at'    => null,
            'created_by'      => (int) $user['id'],
            'tenant_db'       => null,
            'auth_method'     => 'session',
        ];
    }

    /**
     * Check that the token has the required scope.
     *
     * Scopes are stored as comma-separated values or JSON array in ib_api_tokens.scopes.
     * 'admin' scope always passes.
     */
    protected function requireScope(string $scope): bool
    {
        $scopes = TokenScopes::parse((string) ($this->tokenData['scopes'] ?? ''));

        if (in_array('admin', $scopes, true)) {
            return true;
        }

        if (!in_array($scope, $scopes, true)) {
            Response::forbidden("Token lacks required scope: {$scope}");
        }
        return true;
    }

    /**
     * Emit CORS headers
     *
     * For API tokens, credentials are not needed (token in header).
     * Origin is validated against the token's allowed_origins after authentication.
     * Wildcard '*' is only allowed if explicitly set in the token configuration.
     */
    protected function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // For OPTIONS preflight, we must respond before auth.
        // Use a restrictive default; after authenticate() the real origin is validated.
        if ($this->request->method() === 'OPTIONS') {
            // Preflight: allow the request origin provisionally.
            // The actual data request will be validated after authentication.
            if ($origin !== '' && $this->isValidOrigin($origin)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Vary: Origin');
            }
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID');
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            exit;
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-ID');
    }

    /**
     * Set CORS origin header after authentication, using the token's allowed_origins.
     * Must be called after authenticate() so tokenData is populated.
     */
    protected function setCorsOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return; // No Origin header = same-origin or non-browser request
        }

        $allowedOrigins = trim((string)($this->tokenData['allowed_origins'] ?? ''));

        // If token explicitly allows '*', permit any origin
        if ($allowedOrigins === '*') {
            header('Access-Control-Allow-Origin: *');
            return;
        }

        // Check origin against comma-separated whitelist
        if ($allowedOrigins !== '') {
            $list = array_map('trim', explode(',', $allowedOrigins));
            if (in_array($origin, $list, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Vary: Origin');
                return;
            }
        }

        // Origin not allowed — do not set Access-Control-Allow-Origin header
        // Browser will block the response automatically
    }

    /**
     * Basic origin format validation (must be a valid URL scheme + host)
     */
    private function isValidOrigin(string $origin): bool
    {
        return (bool) preg_match('#^https?://[a-zA-Z0-9.\-]+(:\d+)?$#', $origin);
    }

}
