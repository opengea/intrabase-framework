<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;

/**
 * TenantRoutes — short, tenant-defined URLs for app modules (25/09/2026).
 *
 * A tenant may declare <tenant data dir>/routes.php returning
 *   ['map' => 'mapa/places_map', 'market' => 'mercat/shop_market', …]
 * key   = first URL segment (lowercase letters, digits, _ and -),
 * value = the real "app/module" it stands for (full module name).
 *
 * Inbound: Application::handle() rewrites /map[/rest] to /mapa/places_map[/rest]
 * before any routing, so every existing route handler and Router::parse() see
 * the canonical path. Outbound: Router::url() (and everything built on it),
 * the app bar and the sidebar emit the short form, and a GET to the long form
 * is redirected 301 to the short one, so the short URL is the canonical one.
 * Installs without a routes.php are untouched.
 */
final class TenantRoutes
{
    private static bool $loaded = false;

    /** @var array<string, string> short => 'app/mod' */
    private static array $map = [];

    /** @var array<string, string> 'app/mod' => short */
    private static array $reverse = [];

    /** @return array<string, string> */
    public static function map(): array
    {
        if (self::$loaded) {
            return self::$map;
        }
        self::$loaded = true;
        try {
            $dir = Application::getInstance()->getTenantDataPath();
        } catch (\Throwable) {
            return self::$map;
        }
        if ($dir === null || !is_file($dir . '/routes.php')) {
            return self::$map;
        }
        try {
            $raw = require $dir . '/routes.php';
        } catch (\Throwable) {
            return self::$map;
        }
        if (!is_array($raw)) {
            return self::$map;
        }
        foreach ($raw as $short => $target) {
            if (!is_string($short) || !is_string($target)
                || !preg_match('/^[a-z][a-z0-9_-]*$/', $short)
                || !preg_match('#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#', $target)) {
                continue;
            }
            self::$map[$short]      = $target;
            self::$reverse[$target] = $short;
        }
        return self::$map;
    }

    /**
     * Inbound: "/map/5/edit?x" (path only) → "/mapa/places_map/5/edit".
     * Unchanged when the first segment is not an alias.
     */
    public static function rewrite(string $path): string
    {
        $map = self::map();
        if ($map === []) {
            return $path;
        }
        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return $path;
        }
        $slash = strpos($trimmed, '/');
        $first = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
        if (!isset($map[$first])) {
            return $path;
        }
        $rest = $slash === false ? '' : substr($trimmed, $slash);
        return '/' . $map[$first] . $rest;
    }

    /**
     * Outbound: the short path ("/map") for app + full module name, or null
     * when the tenant defines none for it.
     */
    public static function shorten(string $app, string $mod): ?string
    {
        self::map();
        if ($app === '' || $mod === '') {
            return null;
        }
        $short = self::$reverse[$app . '/' . $mod] ?? null;
        return $short === null ? null : '/' . $short;
    }

    /**
     * Canonical redirect target for a long GET path that has a short form:
     * "/mapa/places_map/5" → "/map/5"; null when there is nothing to do.
     */
    public static function canonical(string $path): ?string
    {
        self::map();
        if (self::$reverse === []) {
            return null;
        }
        $segments = explode('/', trim($path, '/'));
        if (count($segments) < 2) {
            return null;
        }
        $key = $segments[0] . '/' . $segments[1];
        if (!isset(self::$reverse[$key])) {
            return null;
        }
        $rest = count($segments) > 2 ? '/' . implode('/', array_slice($segments, 2)) : '';
        return '/' . self::$reverse[$key] . $rest;
    }
}
