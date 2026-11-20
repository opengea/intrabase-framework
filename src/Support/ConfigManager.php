<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * ConfigManager — Runtime tenant configuration reader
 *
 * Provides a simple key-value store backed by the `ib_config` table.
 * All rows are loaded once per request and cached in memory.
 *
 * Usage:
 *   // In Application::registerCoreServices():
 *   ConfigManager::init($this->db);
 *
 *   // Anywhere in the app:
 *   $name    = ConfigManager::get('app_name', 'Intrabase');
 *   $timeout = (int) ConfigManager::get('session_timeout', '7200');
 *
 *   // Write a value (also updates cache):
 *   ConfigManager::set('app_name', 'My Intranet');
 *
 * @package Intrabase\Support
 */
class ConfigManager
{
    /** @var array<string, string|null> In-memory cache keyed by config_key */
    private static array $cache = [];

    /** Whether all rows have been loaded from the DB */
    private static bool $loaded = false;

    /** Active database connection */
    private static ?Connection $db = null;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Bind the database connection and reset the cache.
     * Must be called once during Application bootstrap before any get() calls.
     */
    public static function init(Connection $db): void
    {
        self::$db     = $db;
        self::$cache  = [];
        self::$loaded = false;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Get a config value by key.
     *
     * @param string $key     Configuration key
     * @param mixed  $default Returned when key is absent or value is null
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureLoaded();

        if (!array_key_exists($key, self::$cache)) {
            return $default;
        }

        return self::$cache[$key] ?? $default;
    }

    /**
     * Check whether a key exists in the configuration store.
     */
    public static function has(string $key): bool
    {
        self::ensureLoaded();
        return array_key_exists($key, self::$cache);
    }

    /**
     * Write a config value to the database and update the cache.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so it works for both
     * new and existing keys.
     */
    public static function set(string $key, string $value): void
    {
        if (self::$db === null) {
            return;
        }

        self::$db->execute(
            'INSERT INTO ib_config (config_key, config_value)
             VALUES (:key, :val)
             ON DUPLICATE KEY UPDATE config_value = :val2',
            ['key' => $key, 'val' => $value, 'val2' => $value]
        );

        self::$cache[$key] = $value;
    }

    /**
     * Get all loaded config entries as an associative array.
     *
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$cache;
    }

    /**
     * Clear the in-memory cache (useful after bulk updates or in tests).
     */
    public static function flush(): void
    {
        self::$cache  = [];
        self::$loaded = false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Lazy-load all config rows from DB (once per request).
     */
    private static function ensureLoaded(): void
    {
        if (self::$loaded || self::$db === null) {
            return;
        }

        $rows = self::$db->fetchAll('SELECT config_key, config_value FROM ib_config');

        foreach ($rows as $row) {
            self::$cache[(string) $row['config_key']] = $row['config_value'];
        }

        self::$loaded = true;
    }
}
