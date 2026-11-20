<?php

declare(strict_types=1);

namespace Intrabase\Core;

/**
 * Routes contributed by plugins (applications built on the framework).
 *
 * The kernel only knows its own routes (login, logout, password recovery,
 * the REST API, the generic module dispatcher...). Everything else is added
 * here by the plugins listed in config('plugins'), so the kernel never has to
 * name an application.
 *
 * Two stages, matching the request flow in Application::handle():
 *   - public: tried BEFORE the authentication gate (tokens in the URL,
 *     webhooks, public pages). The handler authorises the request itself.
 *   - auth:   tried AFTER the authentication and app-access gates, before
 *     the generic module dispatcher.
 *
 * A route is either an exact path ('/chat') or a regular expression
 * ('#^/chat/api/.+$#'). Handlers receive the regex captures (an empty array
 * for exact paths). The first matching route wins, in registration order.
 */
final class RouteRegistry
{
    public const PUBLIC = 'public';
    public const AUTH   = 'auth';

    /** @var array<string, list<array{pattern: string, regex: bool, methods: ?list<string>, handler: callable}>> */
    private array $routes = [self::PUBLIC => [], self::AUTH => []];

    /** @var list<string> First path segments owned by plugins (never resolved as an app key). */
    private array $reservedSegments = [];

    /**
     * @param list<string>|null $methods Allowed HTTP methods (null = any)
     */
    public function public(string $pattern, callable $handler, ?array $methods = null): void
    {
        $this->add(self::PUBLIC, $pattern, $handler, $methods);
    }

    /**
     * @param list<string>|null $methods Allowed HTTP methods (null = any)
     */
    public function auth(string $pattern, callable $handler, ?array $methods = null): void
    {
        $this->add(self::AUTH, $pattern, $handler, $methods);
    }

    /**
     * Declare a first path segment ('/chat', '/drive'...) as owned by a plugin,
     * so the kernel does not try to resolve it as an application key.
     */
    public function reserve(string $segment): void
    {
        $segment = trim($segment, '/');
        if ($segment !== '' && !in_array($segment, $this->reservedSegments, true)) {
            $this->reservedSegments[] = $segment;
        }
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return $this->reservedSegments;
    }

    /**
     * Run the first route of $stage that matches $path.
     *
     * @return bool true if a route handled the request
     */
    public function dispatch(string $stage, string $path, string $method): bool
    {
        foreach ($this->routes[$stage] ?? [] as $route) {
            if ($route['methods'] !== null && !in_array(strtoupper($method), $route['methods'], true)) {
                continue;
            }
            if ($route['regex']) {
                if (preg_match($route['pattern'], $path, $m) !== 1) {
                    continue;
                }
                array_shift($m);
                ($route['handler'])($m);
                return true;
            }
            if ($path === $route['pattern'] || $path === $route['pattern'] . '/') {
                ($route['handler'])([]);
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string>|null $methods
     */
    private function add(string $stage, string $pattern, callable $handler, ?array $methods): void
    {
        $this->routes[$stage][] = [
            'pattern' => $pattern,
            'regex'   => $pattern !== '' && $pattern[0] === '#',
            'methods' => $methods === null ? null : array_map('strtoupper', $methods),
            'handler' => $handler,
        ];
    }
}
