<?php

declare(strict_types=1);

namespace Intrabase\Core;

/**
 * Router — URL helper for structured REST-style routes
 *
 * Generates and parses URLs of the form:
 *   /{app}/{mod}              → list/browse
 *   /{app}/{mod}/create       → create form
 *   /{app}/{mod}/{id}         → detail view
 *   /{app}/{mod}/{id}/edit    → edit form
 *   /{app}/{mod}/{id}/delete  → delete action
 *
 * Special routes kept as-is:
 *   /login, /logout, /profile, /api/...
 *
 * @package Intrabase\Core
 */
final class Router
{
    /** Current parsed request (set once by Application::handle()) */
    private static ?array $current = null;

    // -------------------------------------------------------------------------
    // URL Generation
    // -------------------------------------------------------------------------

    /**
     * Generate a URL for a module list/browse page.
     *   url('sys_users', 'system') → /system/sys_users
     */
    public static function url(string $mod, string $app = ''): string
    {
        if ($app === '') {
            $app = self::inferApp($mod);
        }
        if ($app === '' && $mod !== '' && $mod === self::currentMod()) {
            // The module being served: its app is the one in the URL. Covers apps
            // whose ib_apps.modules list is empty (the map cannot know them) —
            // without this, every link on their pages fell to ?mod=… (25/09/2026).
            $app = self::currentApp();
        }
        if ($app === '') {
            // Fallback to legacy query string
            return '?mod=' . urlencode($mod);
        }
        // Tenant short route (Support\TenantRoutes): /mapa/places_map → /map
        $tenantShort = \Intrabase\Support\TenantRoutes::shorten($app, $mod);
        if ($tenantShort !== null) {
            return $tenantShort;
        }
        // Short URL: strip app prefix from module slug (/erp/erp_invoices → /erp/invoices)
        $shortMod = $mod;
        $prefix = $app . '_';
        if (str_starts_with($mod, $prefix)) {
            $shortMod = substr($mod, strlen($prefix));
        }
        return '/' . urlencode($app) . '/' . urlencode($shortMod);
    }

    /**
     * Generate a URL for the create form.
     *   createUrl('sys_users', 'system') → /system/sys_users/create
     */
    public static function createUrl(string $mod, string $app = ''): string
    {
        return self::url($mod, $app) . '/create';
    }

    /**
     * Generate a URL for a record detail view.
     *   detailUrl('sys_users', 5, 'system') → /system/sys_users/5
     */
    public static function detailUrl(string $mod, int $id, string $app = ''): string
    {
        return self::url($mod, $app) . '/' . $id;
    }

    /**
     * Generate a URL for an edit form.
     *   editUrl('sys_users', 5, 'system') → /system/sys_users/5/edit
     */
    public static function editUrl(string $mod, int $id, string $app = ''): string
    {
        return self::url($mod, $app) . '/' . $id . '/edit';
    }

    /**
     * Generate a URL for a delete action.
     *   deleteUrl('sys_users', 5, 'system') → /system/sys_users/5/delete
     */
    public static function deleteUrl(string $mod, int $id, string $app = ''): string
    {
        return self::url($mod, $app) . '/' . $id . '/delete';
    }

    /**
     * Generate a URL for a custom action (GET).
     *   actionUrl('sys_users', 'export', 5, 'system') → /system/sys_users/5/export
     */
    public static function actionUrl(string $mod, string $action, int $id = 0, string $app = ''): string
    {
        $base = self::url($mod, $app);
        if ($id > 0) {
            return $base . '/' . $id . '/' . urlencode($action);
        }
        return $base . '/' . urlencode($action);
    }

    /**
     * Generate a URL preserving extra query parameters (for pagination, search, sort).
     */
    public static function urlWithQuery(string $mod, string $app = '', array $params = []): string
    {
        $base = self::url($mod, $app);
        if (empty($params)) {
            return $base;
        }
        return $base . '?' . http_build_query($params);
    }

    /**
     * Generate POST action URL for save operations.
     *   saveUrl('sys_users') → /system/sys_users/save
     */
    public static function saveUrl(string $mod, string $app = ''): string
    {
        return self::url($mod, $app) . '/save';
    }

    // -------------------------------------------------------------------------
    // Request Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse the current request path into route components.
     *
     * Returns an array with keys:
     *   - app    (string)  app keyname or ''
     *   - mod    (string)  module name or ''
     *   - id     (int)     record id or 0
     *   - action (string)  'list'|'create'|'view'|'edit'|'delete'|'save'|custom
     *
     * Examples:
     *   /system/sys_users           → [app=system, mod=sys_users, id=0, action=list]
     *   /system/sys_users/create    → [app=system, mod=sys_users, id=0, action=create]
     *   /system/sys_users/5         → [app=system, mod=sys_users, id=5,  action=view]
     *   /system/sys_users/5/edit    → [app=system, mod=sys_users, id=5,  action=edit]
     *   /system/sys_users/5/delete  → [app=system, mod=sys_users, id=5,  action=delete]
     *   /system/sys_users/save      → [app=system, mod=sys_users, id=0,  action=save]
     *
     * @param  string $path  Request URI path (from $_SERVER['REQUEST_URI'])
     * @return array{app:string, mod:string, id:int, action:string}|null
     *         Returns null if path does not match structured route pattern.
     */
    public static function parse(string $path): ?array
    {
        // Remove query string
        $path = strtok($path, '?') ?: '/';

        // Normalize trailing slash
        $path = rtrim($path, '/') ?: '/';

        // Must start with / and contain at least two segments: /app/mod
        if ($path === '/' || !str_starts_with($path, '/')) {
            return null;
        }

        $segments = explode('/', ltrim($path, '/'));

        // Need at least 2 segments: app + mod
        if (count($segments) < 2) {
            return null;
        }

        $app = self::sanitizeSegment($segments[0]);
        $mod = self::sanitizeSegment($segments[1]);

        if ($app === '' || $mod === '') {
            return null;
        }

        // Validate module name (lowercase, alphanumeric + underscore)
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $mod)) {
            return null;
        }

        $id     = 0;
        $action = 'list';

        if (isset($segments[2])) {
            $seg2 = $segments[2];

            if ($seg2 === 'create') {
                $action = 'create';
            } elseif ($seg2 === 'save') {
                $action = 'save';
            } elseif (ctype_digit($seg2)) {
                $id = (int) $seg2;
                $action = isset($segments[3]) ? self::sanitizeSegment($segments[3]) : 'view';
                if ($action === '') {
                    $action = 'view';
                }
            } else {
                // custom non-id action (e.g. /app/mod/export)
                $action = self::sanitizeSegment($seg2);
            }
        }

        return [
            'app'    => $app,
            'mod'    => $mod,
            'id'     => $id,
            'action' => $action,
        ];
    }

    /**
     * Set the current parsed route (called by Application).
     *
     * @param array{app:string, mod:string, id:int, action:string} $route
     */
    public static function setCurrent(array $route): void
    {
        self::$current = $route;
    }

    /**
     * Get the current parsed route.
     *
     * @return array{app:string, mod:string, id:int, action:string}|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Get current module name (from route or legacy ?mod=).
     */
    public static function currentMod(): string
    {
        if (self::$current !== null) {
            return self::$current['mod'];
        }
        return preg_replace('/[^a-z0-9_]/i', '', $_GET['mod'] ?? '') ?? '';
    }

    /**
     * Get current action (from route or legacy ?_=).
     */
    public static function currentAction(): string
    {
        if (self::$current !== null) {
            return self::$current['action'];
        }
        return preg_replace('/[^a-z0-9_]/i', '', $_GET['_'] ?? '') ?? '';
    }

    /**
     * Get current record ID (from route or legacy ?id=).
     */
    public static function currentId(): int
    {
        if (self::$current !== null) {
            return self::$current['id'];
        }
        return max(0, (int)($_GET['id'] ?? 0));
    }

    /**
     * Get current app keyname (from route or empty).
     */
    public static function currentApp(): string
    {
        if (self::$current !== null) {
            return self::$current['app'];
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // App inference cache
    // -------------------------------------------------------------------------

    /** @var array<string, string> mod → app keyname cache */
    private static array $modAppCache = [];

    /**
     * Set the mod→app mapping (called by Application after loading apps).
     *
     * @param array<string, string> $map
     */
    public static function setModAppMap(array $map): void
    {
        self::$modAppCache = $map;
    }

    /**
     * Infer the app keyname for a given module from the preloaded map.
     */
    private static function inferApp(string $mod): string
    {
        return self::$modAppCache[$mod] ?? '';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function sanitizeSegment(string $seg): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $seg);
    }
}
