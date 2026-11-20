<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use Intrabase\Security\Session;
use PDO;

/**
 * AppAccess
 *
 * Single source of truth for "which apps can this user see right now?".
 * Combines four independent filters applied in order:
 *   1. Admin override for the 'system' app.
 *   2. Tenant-level module restriction (tenant config `modules`).
 *   3. Active-entity plan (via EntityPlan::allowedSlugs on ib_apps.catalog_slug).
 *   4. Per-app group_access check.
 *
 * Used by Layout (app bar) and WidgetRenderer (dashboard widgets) so that
 * a widget can never render for an app the user cannot access.
 */
final class AppAccess
{
    /** Per-request cache keyed by user_id + entity_id. */
    private static array $cache = [];

    /** @var array<string, callable(Connection, int): bool> availability checks per app keyname */
    private static array $availability = [];

    /** Clear the per-request cache. Intended for tests. */
    public static function reset(): void { self::$cache = []; }

    /**
     * Let a plugin hide its app for a profile (active entity) or host, e.g. a
     * video-meeting app where no meeting server is configured. The check gets
     * the tenant connection and the active entity id; true = available.
     *
     * @param callable(Connection, int): bool $check
     */
    public static function registerAvailability(string $appKey, callable $check): void
    {
        self::$availability[$appKey] = $check;
        self::$cache = [];
    }

    /**
     * Fetch active apps filtered by the current user + active entity.
     * Returns the full ib_apps rows (ordered by sort_order, name) so
     * callers that need extra columns avoid a second query.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function visibleApps(Connection $db, Session $session): array
    {
        $user     = $session->user();
        $userId   = (int)($user['id'] ?? 0);
        $entityId = EntityPlan::activeEntityId($session);
        $cacheKey = $userId . ':' . $entityId;
        if (isset(self::$cache[$cacheKey])) return self::$cache[$cacheKey];

        $groupIds = $user['group_ids'] ?? [];
        $groupNames = [1 => 'admin', 2 => 'staff', 3 => 'readonly'];

        $stmt = $db->pdo()->prepare(
            'SELECT id, keyname, name, icon, modules, default_module, sort_order,
                    group_access, sidebar_collapsed, display_location, catalog_slug
               FROM ib_apps
              WHERE is_active = 1
           ORDER BY sort_order ASC, name ASC'
        );
        $stmt->execute();
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $profile  = self::profileFilter($db, $entityId);
        // Per-person permissions (UserAppAccess) override system groups, except for the System app.
        $managed  = UserAppAccess::managed($db, $userId, $entityId);
        $filtered = array_values(array_filter($all, static function (array $app) use ($groupIds, $groupNames, $profile, $managed): bool {
            if (!$profile($app)) {
                return false;
            }
            if ($managed && ($app['keyname'] ?? '') !== 'system') {
                return true;
            }

            $access = trim((string)($app['group_access'] ?? ''));
            if ($access === '') {
                return true;
            }
            $allowed = array_map('trim', explode(',', $access));
            foreach ($groupIds as $gid) {
                if (in_array((string)$gid, $allowed, true)) return true;
                $name = $groupNames[(int)$gid] ?? '';
                if ($name !== '' && in_array($name, $allowed, true)) return true;
            }
            return false;
        }));

        // Per-person permissions (UserAppAccess): apps without access are not listed.
        $levels = UserAppAccess::levels($db, $userId, $entityId);
        if ($levels !== []) {
            $filtered = array_values(array_filter($filtered, static fn(array $a): bool =>
                ($levels[(string) ($a['keyname'] ?? '')] ?? 'write') !== 'none'));
        }

        self::$cache[$cacheKey] = $filtered;
        return $filtered;
    }

    /**
     * Filters that depend on the PROFILE (active entity) and the host, not on
     * the user: tenant modules, the entity's plan and the availability checks
     * registered by plugins. Groups (group_access) are NOT here: they hide the
     * app from the menu, but some of its modules may still be reachable.
     *
     * @return callable(array<string,mixed>): bool
     */
    private static function profileFilter(Connection $db, int $entityId): callable
    {
        $tenant = \Intrabase\Core\Application::getInstance()->getTenantConfig();
        $tenantModules = array_filter(array_map('trim', explode(',', (string)($tenant['modules'] ?? ''))));

        $planSlugs = EntityPlan::allowedSlugs($db, $entityId);

        $availability = self::$availability;

        return static function (array $app) use ($tenantModules, $planSlugs, $availability, $db, $entityId): bool {
            $keyname = (string)($app['keyname'] ?? '');

            if ($tenantModules !== [] && !in_array($keyname, $tenantModules, true)) {
                return false;
            }

            if (isset($availability[$keyname])) {
                try {
                    if (!($availability[$keyname])($db, $entityId)) {
                        return false;
                    }
                } catch (\Throwable $e) {
                    error_log('[AppAccess] availability ' . $keyname . ': ' . $e->getMessage());
                }
            }

            $slug = trim((string)($app['catalog_slug'] ?? ''));
            if ($slug !== '' && $planSlugs !== null && !in_array($slug, $planSlugs, true)) {
                return false;
            }
            return true;
        };
    }

    /**
     * Is the (active) app available in the active profile? Profile filters
     * only, without groups: Application uses it to go back to the dashboard
     * when, after switching profile, the open page belongs to an app the new
     * profile does not have.
     */
    public static function availableForProfile(Connection $db, Session $session, array $app): bool
    {
        return (self::profileFilter($db, EntityPlan::activeEntityId($session)))($app);
    }

    /**
     * Keynames of visible apps. Convenience wrapper for WidgetRenderer
     * and any other caller that only needs the identifier set.
     *
     * @return array<int,string>
     */
    public static function visibleKeynames(Connection $db, Session $session): array
    {
        return array_map(static fn(array $a) => (string)($a['keyname'] ?? ''), self::visibleApps($db, $session));
    }

    /**
     * Extract the app keyname from an internal URL. Returns '' for external
     * URLs (http(s)://) or paths that don't start with "/<segment>".
     *
     * Examples:
     *   /erp/ent_clients → "erp"
     *   /mail            → "mail"
     *   https://x.com    → ""
     *   /#anchor         → ""
     */
    public static function urlAppKey(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) return '';
        if ($url[0] !== '/') return '';
        $path = strtok($url, '?#');
        $parts = explode('/', ltrim($path, '/'));
        $seg = $parts[0] ?? '';
        return preg_match('/^[a-z0-9_-]+$/i', $seg) ? strtolower($seg) : '';
    }
}
