<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * Sidebar modules that only make sense depending on the tenant's data.
 *
 * `disabled_modules` (config) hides modules by hand; a condition hides one
 * automatically when there is nothing to do in it (e.g. a tax report for a
 * kind of company the tenant is not). The module stays reachable by URL.
 *
 * The kernel registers no condition: plugins add the ones for their modules
 * with MenuConditions::register('mod_key', fn(Connection $db): bool => ...).
 */
final class MenuConditions
{
    /** @var array<string, callable(Connection): bool> */
    private static array $conditions = [];

    /** @var array<string, bool> result per database and module during the request */
    private static array $cache = [];

    /**
     * @param callable(Connection): bool $check true = show the module
     */
    public static function register(string $modKey, callable $check): void
    {
        self::$conditions[$modKey] = $check;
    }

    public static function visible(Connection $db, string $modKey): bool
    {
        $check = self::$conditions[$modKey] ?? null;
        if ($check === null) {
            return true;
        }
        try {
            $dbName = (string) $db->pdo()->query('SELECT DATABASE()')->fetchColumn();
            $key    = $dbName . '|' . $modKey;
            if (!array_key_exists($key, self::$cache)) {
                self::$cache[$key] = (bool) $check($db);
            }
            return self::$cache[$key];
        } catch (\Throwable $e) {
            error_log('[MenuConditions] ' . $modKey . ': ' . $e->getMessage());
            return true; // when in doubt, the menu hides nothing
        }
    }
}
