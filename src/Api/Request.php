<?php

declare(strict_types=1);

namespace Intrabase\Api;

/**
 * API Request wrapper
 *
 * Parses the incoming HTTP request for API consumption.
 * Handles JSON body parsing, parameter extraction, and header access.
 *
 * @package Intrabase\Api
 */
class Request
{
    private string $method;
    private string $path;
    private string $rawPath;

    /** @var array<string, string> */
    private array $segments = [];

    /** @var array<string, mixed> */
    private array $queryParams = [];

    /** @var array<string, mixed> */
    private array $bodyParams = [];

    /** @var array<string, string> */
    private array $headers = [];

    private bool $bodyParsed = false;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->path = $this->normalizePath($this->rawPath);
        $this->parseSegments();
        $this->parseQueryParams();
    }

    // -------------------------------------------------------------------------
    // Request method
    // -------------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isGet(): bool    { return $this->method === 'GET'; }
    public function isPost(): bool   { return $this->method === 'POST'; }
    public function isPut(): bool    { return $this->method === 'PUT'; }
    public function isPatch(): bool  { return $this->method === 'PATCH'; }
    public function isDelete(): bool { return $this->method === 'DELETE'; }

    // -------------------------------------------------------------------------
    // Path / routing
    // -------------------------------------------------------------------------

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get path segment by index (0-based, after /api/v1/)
     */
    public function segment(int $index): ?string
    {
        return $this->segments[$index] ?? null;
    }

    /** @return string[] */
    public function segments(): array
    {
        return $this->segments;
    }

    // -------------------------------------------------------------------------
    // Query parameters
    // -------------------------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $v = $this->queryParams[$key] ?? null;
        return $v !== null && ctype_digit((string)$v) ? (int)$v : $default;
    }

    // -------------------------------------------------------------------------
    // Body parameters (JSON or form)
    // -------------------------------------------------------------------------

    public function body(string $key, mixed $default = null): mixed
    {
        $this->ensureBodyParsed();
        return $this->bodyParams[$key] ?? $default;
    }

    public function bodyInt(string $key, int $default = 0): int
    {
        $v = $this->body($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function bodyString(string $key, string $default = ''): string
    {
        $v = $this->body($key);
        return is_string($v) ? trim($v) : $default;
    }

    /** @return array<string, mixed> */
    public function allBody(): array
    {
        $this->ensureBodyParsed();
        return $this->bodyParams;
    }

    // -------------------------------------------------------------------------
    // Headers
    // -------------------------------------------------------------------------

    public function header(string $name): ?string
    {
        $key = strtolower(str_replace('-', '_', $name));
        return $_SERVER['HTTP_' . strtoupper($key)] ?? null;
    }

    /**
     * Extract Bearer token from Authorization header
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
            // Basic sanity check: only alphanumeric + dash/underscore, 32–128 chars
            if (preg_match('/^[a-zA-Z0-9\-_]{32,128}$/', $token)) {
                return $token;
            }
        }
        return null;
    }

    /**
     * Get client IP, respecting trusted proxies
     */
    public function ip(): string
    {
        // Only trust X-Forwarded-For if explicitly configured (simplified version)
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function normalizePath(string $path): string
    {
        // Remove trailing slash except for root
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    private function parseSegments(): void
    {
        // Strip /api/v{n}/ prefix and split remainder
        $path = preg_replace('#^/api/v\d+/?#', '', $this->path) ?? '';
        $this->segments = array_values(array_filter(explode('/', $path)));
    }

    private function parseQueryParams(): void
    {
        $raw = $_GET ?? [];
        foreach ($raw as $k => $v) {
            $safeKey = preg_replace('/[^a-zA-Z0-9_\-\[\]]/', '', (string)$k);
            if ($safeKey) {
                $this->queryParams[$safeKey] = $v;
            }
        }
    }

    private function ensureBodyParsed(): void
    {
        if ($this->bodyParsed) {
            return;
        }
        $this->bodyParsed = true;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->bodyParams = $decoded;
                }
            }
        } else {
            // Form data (POST)
            $this->bodyParams = $_POST ?? [];
        }
    }
}
