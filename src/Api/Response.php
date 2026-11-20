<?php

declare(strict_types=1);

namespace Intrabase\Api;

/**
 * JSON API Response helper
 *
 * Produces consistent envelope:
 * {
 *   "success": true|false,
 *   "data": ...,
 *   "meta": { ... },        // pagination, timing, etc.
 *   "error": { ... }        // only on failure
 * }
 *
 * @package Intrabase\Api
 */
class Response
{
    private function __construct() {}

    /**
     * Send a successful JSON response
     *
     * @param mixed $data
     * @param array<string, mixed> $meta
     */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): never
    {
        self::send($status, [
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
        ]);
    }

    /**
     * Send a paginated list response
     *
     * @param array<int, mixed> $data
     * @param array<string, mixed> $pagination
     */
    public static function paginated(array $data, array $pagination): never
    {
        self::send(200, [
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'pagination' => $pagination,
            ],
        ]);
    }

    /**
     * Send an error response
     */
    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        $body = [
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

        if ($details) {
            $body['error']['details'] = $details;
        }

        self::send($status, $body);
    }

    public static function badRequest(string $message = 'Bad request', array $details = []): never
    {
        self::error(400, 'BAD_REQUEST', $message, $details);
    }

    public static function unauthorized(string $message = 'Authentication required'): never
    {
        self::error(401, 'UNAUTHORIZED', $message);
    }

    public static function forbidden(string $message = 'Access denied'): never
    {
        self::error(403, 'FORBIDDEN', $message);
    }

    public static function notFound(string $resource = 'Resource'): never
    {
        self::error(404, 'NOT_FOUND', "{$resource} not found");
    }

    public static function methodNotAllowed(string $method): never
    {
        self::error(405, 'METHOD_NOT_ALLOWED', "Method {$method} not allowed");
    }

    public static function unprocessable(array $errors): never
    {
        self::error(422, 'VALIDATION_ERROR', 'Validation failed', $errors);
    }

    public static function tooManyRequests(int $retryAfter = 60): never
    {
        header("Retry-After: {$retryAfter}");
        self::error(429, 'TOO_MANY_REQUESTS', 'Rate limit exceeded. Try again later.');
    }

    public static function serverError(string $message = 'Internal server error'): never
    {
        self::error(500, 'SERVER_ERROR', $message);
    }

    /**
     * Emit HTTP response and exit
     *
     * @param array<string, mixed> $body
     */
    private static function send(int $status, array $body): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        // Add request ID if available
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        if ($requestId && preg_match('/^[a-zA-Z0-9\-_]{1,64}$/', $requestId)) {
            $body['request_id'] = $requestId;
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
