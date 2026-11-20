<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Database\Connection;

/**
 * Access Control List (ACL)
 *
 * Controls which user groups may access application modules.
 * Reads configuration from the `ib_apps` table.
 *
 * In the new schema, modules are stored as a JSON array of objects inside
 * each app group's `modules` column. The `group_access` is set at the app
 * group level and applies to all modules within that group.
 *
 * If group_access is NULL, the app (and all its modules) is accessible to
 * all authenticated users.
 */
class Acl
{
    /** @var array<string, string|null> module_key => group_access */
    private array $appCache = [];

    /** @var array<int, string[]> user_id => group names */
    private array $groupCache = [];

    /**
     * Check whether any of the given user groups may access a module.
     *
     * @param  string   $appModule  The module key (e.g. 'sys_users')
     * @param  string[] $userGroups List of group names the user belongs to
     * @param  Connection $db
     * @return bool
     */
    public function canAccess(string $appModule, array $userGroups, Connection $db): bool
    {
        $groupAccess = $this->getAppGroupAccess($appModule, $db);

        // NULL means no restriction — all authenticated users may access
        if ($groupAccess === null) {
            return true;
        }

        $groupAccess = trim($groupAccess);
        if ($groupAccess === '') {
            return false;
        }

        $allowed = array_map('trim', explode(',', $groupAccess));

        foreach ($userGroups as $group) {
            if (in_array($group, $allowed, true)) {
                return true;
            }
        }

        // group_access may list group IDs as well as names (AppAccess, which
        // builds the menu, accepts both): resolve the user's group ids.
        $allowedIds = array_values(array_filter($allowed, 'ctype_digit'));
        if ($allowedIds !== [] && $userGroups !== []) {
            $ph   = implode(',', array_fill(0, count($userGroups), '?'));
            $rows = $db->fetchAll("SELECT id FROM ib_groups WHERE name IN ({$ph})", array_values($userGroups));
            foreach ($rows as $row) {
                if (in_array((string) $row['id'], $allowedIds, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Key of the active app that contains the module, or null when no app does.
     */
    public function ownerApp(string $appModule, Connection $db): ?string
    {
        $row = $this->findApp($appModule, $db);
        return $row === null ? null : (string) $row['keyname'];
    }

    /**
     * Return all group names for a given user.
     *
     * @param  int        $userId
     * @param  Connection $db
     * @return string[]
     */
    public function getUserGroups(int $userId, Connection $db): array
    {
        if (isset($this->groupCache[$userId])) {
            return $this->groupCache[$userId];
        }

        $rows = $db->fetchAll(
            'SELECT g.name
               FROM ib_user_groups ug
               JOIN ib_groups g ON g.id = ug.group_id
              WHERE ug.user_id = :user_id',
            ['user_id' => $userId]
        );

        $names = array_column($rows, 'name');
        $this->groupCache[$userId] = $names;

        return $names;
    }

    /**
     * Check whether any of the user's groups match a specific role name.
     */
    public function hasRole(string $role, array $userGroups): bool
    {
        return in_array($role, $userGroups, true);
    }

    public function invalidateUserCache(int $userId): void
    {
        unset($this->groupCache[$userId]);
    }

    public function invalidateAppCache(string $appModule): void
    {
        unset($this->appCache[$appModule]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch (and cache) the group_access for the app group containing a module.
     *
     * Modules are stored as JSON objects in ib_apps.modules:
     *   [{"key":"sys_users","name":"Users","icon":"..."}, ...]
     *
     * We use JSON_SEARCH to find which app group contains the module key,
     * then return that group's group_access.
     *
     * If no app group owns the module, access is open (return null).
     *
     * @return string|null  Comma-separated group names, or NULL for open access
     */
    private function getAppGroupAccess(string $appModule, Connection $db): ?string
    {
        if (array_key_exists($appModule, $this->appCache)) {
            return $this->appCache[$appModule];
        }

        $row = $this->findApp($appModule, $db);

        if ($row === null) {
            // A module in no app is open (backward-compatible), EXCEPT system
            // modules: they decide who can do what and default to admins only.
            $this->appCache[$appModule] = str_starts_with($appModule, 'sys_') ? 'admin' : null;
            return $this->appCache[$appModule];
        }

        $this->appCache[$appModule] = $row['group_access'];

        return $row['group_access'];
    }

    /**
     * The active app whose module list contains the module. ib_apps.modules
     * comes in two shapes: a flat list [{"key":...}] and, what the installer
     * and the app manager write, groups [{"group":...,"modules":[{"key":...}]}].
     * Until 20/11/2026 only the flat shape was searched: every module of a
     * grouped app looked "in no app", i.e. open to any logged-in user.
     *
     * @return array{keyname: string, group_access: ?string}|null
     */
    private function findApp(string $appModule, Connection $db): ?array
    {
        return $db->fetchOne(
            "SELECT keyname, group_access
               FROM ib_apps
              WHERE is_active = 1
                AND (JSON_SEARCH(modules, 'one', :mod1, NULL, '\$[*].key') IS NOT NULL
                  OR JSON_SEARCH(modules, 'one', :mod2, NULL, '\$[*].modules[*].key') IS NOT NULL)
              ORDER BY id
              LIMIT 1",
            ['mod1' => $appModule, 'mod2' => $appModule]
        );
    }
}
