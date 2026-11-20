<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Core\Module;
use Intrabase\Database\Connection;

/**
 * Permission — Granular action-level permission check
 *
 * Reads from `ib_permissions` and applies constraints to a Module instance.
 * If no permission row exists for (group, module_key), all existing canXxx
 * flags on the module remain unchanged (backwards-compatible open access).
 *
 * Permission evaluation follows a UNION model: if the user belongs to multiple
 * groups, an action is allowed if ANY group grants it.
 *
 * Usage:
 *   Permission::applyToModule($module, $groupIds, 'lib/lib_files', $db);
 */
class Permission
{
    /**
     * In-memory per-request cache keyed by "moduleKey:groupIds"
     *
     * @var array<string, array<string, bool>>
     */
    private static array $cache = [];

    /**
     * Apply permission overrides to a module instance.
     *
     * If no rows are found in `ib_permissions` for the given module_key and
     * any of the supplied group IDs, the method returns without modification.
     *
     * @param Module     $module    Module instance to modify
     * @param int[]      $groupIds  User's group IDs
     * @param string     $moduleKey Module key (e.g. 'lib/lib_files')
     * @param Connection $db
     */
    public static function applyToModule(
        Module $module,
        array $groupIds,
        string $moduleKey,
        Connection $db
    ): void {
        if (empty($groupIds) || $moduleKey === '') {
            return;
        }

        $perms = self::loadPermissions($groupIds, $moduleKey, $db);
        if ($perms === null) {
            // No rows found — no restrictions defined, keep module defaults
            return;
        }

        // Apply the union of allowed actions to the module flags
        $module->canAdd    = $perms['can_create'];
        $module->canEdit   = $perms['can_edit'];
        $module->canDelete = $perms['can_delete'];
        $module->canExport = $perms['can_export'];

        // canBrowse is enforced by blocking the execute() call in Module if false
        // Store it as a module-level flag for callers to check
        $module->canBrowse = $perms['can_browse'];
    }

    /**
     * Directly check if a user (identified by group IDs) may perform an action.
     *
     * Returns null if no permission row exists (meaning: no restriction defined).
     *
     * @param int[]      $groupIds
     * @param string     $moduleKey
     * @param string     $action    'browse'|'create'|'edit'|'delete'|'export'
     * @param Connection $db
     * @return bool|null  true = allowed, false = denied, null = no rule defined
     */
    public static function check(
        array $groupIds,
        string $moduleKey,
        string $action,
        Connection $db
    ): ?bool {
        if (empty($groupIds)) {
            return null;
        }

        $perms = self::loadPermissions($groupIds, $moduleKey, $db);
        if ($perms === null) {
            return null;
        }

        return $perms['can_' . $action] ?? null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load and cache aggregated permissions for a (module_key, groupIds) pair.
     *
     * Returns null if no rows exist.
     *
     * @param  int[]  $groupIds
     * @param  string $moduleKey
     * @return array<string, bool>|null
     */
    private static function loadPermissions(
        array $groupIds,
        string $moduleKey,
        Connection $db
    ): ?array {
        sort($groupIds);
        $cacheKey = $moduleKey . ':' . implode(',', $groupIds);

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        // Build parameterized IN clause
        $params = ['module_key' => $moduleKey];
        $placeholders = [];
        foreach ($groupIds as $i => $gid) {
            $pk = 'gid' . $i;
            $params[$pk] = $gid;
            $placeholders[] = ':' . $pk;
        }
        $inList = implode(', ', $placeholders);

        $rows = $db->fetchAll(
            "SELECT can_browse, can_create, can_edit, can_delete, can_export
               FROM ib_permissions
              WHERE module_key = :module_key
                AND group_id IN ({$inList})",
            $params
        );

        if (empty($rows)) {
            self::$cache[$cacheKey] = null;
            return null;
        }

        // Union model: allowed if ANY matching group row grants it
        $result = [
            'can_browse' => false,
            'can_create' => false,
            'can_edit'   => false,
            'can_delete' => false,
            'can_export' => false,
        ];

        foreach ($rows as $row) {
            foreach (array_keys($result) as $col) {
                if ((bool) $row[$col]) {
                    $result[$col] = true;
                }
            }
        }

        self::$cache[$cacheKey] = $result;
        return $result;
    }
}
